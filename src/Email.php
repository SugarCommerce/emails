<?php
/**
 * Email
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails;

use SugarCommerce\Emails\Render\Processor;
use SugarCommerce\Emails\Utils\Runtime;
use SugarCommerce\Text\Display\Color;
use WP_Error;

/**
 * One email, built and sent.
 *
 *     Email::make()
 *         ->to( $customer->email )
 *         ->subject( __( 'Your download is ready', 'my-plugin' ) )
 *         ->content( '<p>{download_link}</p>' )
 *         ->tags( 'shop' )
 *         ->about( $order )
 *         ->send();
 *
 * Three things here are not obvious and all of them are about not sending
 * something wrong.
 *
 * **A header value never contains a line break.** Every part that goes into a
 * header is cleaned of CR and LF as it is set. On a current WordPress this is
 * not an injection — PHPMailer refuses a custom header containing one and
 * strips them out of names and subjects — but "PHPMailer refuses it" means
 * `wp_mail()` returns false and the email silently does not go, which is a
 * worse outcome than a Reply-To name with a stray newline taken out of it.
 *
 * **An email with a tag that failed does not send.** The tag layer used to
 * catch the failure, write it to the error log and put an empty string in,
 * so a customer received an order confirmation with a blank where the total
 * should be. Sending nothing is recoverable; sending that is not.
 *
 * **What goes into the template goes in as text.** A title sits inside an
 * `<h1>`, and one built from a customer's name went in as whatever the name
 * had in it. Only the body, the logo and the footer are markup by contract.
 * The subject is filled in from the same tags as the body — it used to reach
 * the customer as typed, `{site_name}` and all — and being a header it is
 * filled in as words on one line, not as markup.
 */
final class Email {

	/**
	 * The template placeholders that take markup.
	 *
	 * The body, and the logo and footer the Parts draw. Everything else a
	 * template asks for is a word inside an element — the title in an
	 * `<h1>`, the subject in a `<title>` — and goes in escaped.
	 *
	 * @var string[]
	 */
	private const MARKUP = [ 'content', 'logo', 'footer' ];

	/**
	 * Who it goes to.
	 *
	 * @var string[]
	 */
	private array $to = [];

	/**
	 * Its subject, before the tags are filled in.
	 *
	 * @var string
	 */
	private string $subject = '';

	/**
	 * Its subject as it goes out, from the last build.
	 *
	 * @var string
	 */
	private string $resolved_subject = '';

	/**
	 * Its body, before the tags are filled in.
	 *
	 * @var string
	 */
	private string $content = '';

	/**
	 * The line an inbox shows after the subject, before the tags are filled in.
	 *
	 * @var string
	 */
	private string $preheader = '';

	/**
	 * Its plain-text part, when the sender wrote one.
	 *
	 * @var string
	 */
	private string $text = '';

	/**
	 * Which visual template wraps it.
	 *
	 * @var string
	 */
	private string $template = 'default';

	/**
	 * Who it is from: the address and the name.
	 *
	 * @var array{0: string, 1: string}
	 */
	private array $from = [ '', '' ];

	/**
	 * Where a reply goes: the address and the name.
	 *
	 * @var array{0: string, 1: string}
	 */
	private array $reply = [ '', '' ];

	/**
	 * Who else gets it.
	 *
	 * @var string[]
	 */
	private array $cc = [];

	/**
	 * Who else gets it, quietly.
	 *
	 * @var string[]
	 */
	private array $bcc = [];

	/**
	 * Headers of the sender's own, by name.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * Files to attach.
	 *
	 * @var string[]
	 */
	private array $attachments = [];

	/**
	 * The tag groups that apply.
	 *
	 * @var string[]
	 */
	private array $groups = [];

	/**
	 * Whatever the email is about.
	 *
	 * @var mixed
	 */
	private mixed $data = null;

	/**
	 * What goes in the template's own placeholders.
	 *
	 * @var array<string, string>
	 */
	private array $context = [];

	/**
	 * The last run's tag problems.
	 *
	 * @var array<string, string>
	 */
	private array $problems = [];

	/**
	 * Tags the last run met that nobody registered.
	 *
	 * @var string[]
	 */
	private array $unknown = [];

	/**
	 * Its own transport, or null for the one everything goes through.
	 *
	 * @var Transport|null
	 */
	private ?Transport $transport = null;

	/**
	 * How the last send went.
	 *
	 * @var Result|null
	 */
	private ?Result $result = null;

	/**
	 * Start an email.
	 *
	 * @return self
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Who it goes to.
	 *
	 * @param string|string[] $to One address or several.
	 *
	 * @return self
	 */
	public function to( string|array $to ): self {
		foreach ( (array) $to as $address ) {
			$address = sanitize_email( (string) $address );

			if ( '' !== $address ) {
				$this->to[] = $address;
			}
		}

		return $this;
	}

	/**
	 * What it says it is about.
	 *
	 * @param string $subject The subject.
	 *
	 * @return self
	 */
	public function subject( string $subject ): self {
		$this->subject = self::one_line( $subject );

		return $this;
	}

	/**
	 * Its body.
	 *
	 * @param string $content The body, with tags in it.
	 *
	 * @return self
	 */
	public function content( string $content ): self {
		$this->content = $content;

		return $this;
	}

	/**
	 * The line an inbox shows after the subject.
	 *
	 * Hidden in the email itself. Without one an inbox shows the first words
	 * it finds, which in most designs is the shop's name or the heading
	 * again. Tags work in it, as words.
	 *
	 * @param string $preheader One short sentence.
	 *
	 * @return self
	 */
	public function preheader( string $preheader ): self {
		$this->preheader = self::one_line( $preheader );

		return $this;
	}

	/**
	 * Its plain-text part, in place of the one worked out from the HTML.
	 *
	 * @param string $text The words, as they should read in a text client.
	 *
	 * @return self
	 */
	public function text( string $text ): self {
		$this->text = $text;

		return $this;
	}

	/**
	 * Which visual template wraps it.
	 *
	 * @param string $template The template.
	 *
	 * @return self
	 */
	public function template( string $template ): self {
		$this->template = $template;

		return $this;
	}

	/**
	 * Which tag groups apply.
	 *
	 * @param string|string[] $groups One group or several.
	 *
	 * @return self
	 */
	public function tags( string|array $groups ): self {
		$this->groups = array_values( array_unique( array_merge( $this->groups, (array) $groups ) ) );

		return $this;
	}

	/**
	 * Whatever the email is about, handed to every tag's callback.
	 *
	 * @param mixed $data The order, the customer, the subscription.
	 *
	 * @return self
	 */
	public function about( mixed $data ): self {
		$this->data = $data;

		return $this;
	}

	/**
	 * What goes in the template's own placeholders.
	 *
	 * Words, and inserted as words: `title` and `subtitle` are escaped on
	 * the way in, as is anything else a template asks for. `footer` and
	 * `logo` are markup — the Parts draw them — and go in as they are, the
	 * same as the body.
	 *
	 * @param array<string, string> $context title, subtitle, footer and the rest.
	 *
	 * @return self
	 */
	public function context( array $context ): self {
		$this->context = array_merge( $this->context, $context );

		return $this;
	}

	/**
	 * Who it comes from.
	 *
	 * @param string $email The address.
	 * @param string $name  The name.
	 *
	 * @return self
	 */
	public function from( string $email, string $name = '' ): self {
		$email = sanitize_email( $email );

		if ( '' !== $email ) {
			$this->from = [ $email, self::one_line( $name ) ];
		}

		return $this;
	}

	/**
	 * Where a reply goes.
	 *
	 * @param string $email The address.
	 * @param string $name  The name.
	 *
	 * @return self
	 */
	public function reply_to( string $email, string $name = '' ): self {
		$email = sanitize_email( $email );

		if ( '' !== $email ) {
			$this->reply = [ $email, self::one_line( $name ) ];
		}

		return $this;
	}

	/**
	 * Who else gets it.
	 *
	 * @param string $email The address.
	 *
	 * @return self
	 */
	public function cc( string $email ): self {
		$email = sanitize_email( $email );

		if ( '' !== $email ) {
			$this->cc[] = $email;
		}

		return $this;
	}

	/**
	 * Who else gets it, quietly.
	 *
	 * @param string $email The address.
	 *
	 * @return self
	 */
	public function bcc( string $email ): self {
		$email = sanitize_email( $email );

		if ( '' !== $email ) {
			$this->bcc[] = $email;
		}

		return $this;
	}

	/**
	 * A header of your own.
	 *
	 * @param string $name  Its name.
	 * @param string $value Its value.
	 *
	 * @return self
	 */
	public function header( string $name, string $value ): self {
		$name = self::one_line( $name );

		// A name is a word: `X-Order-Id`, not `X-Order-Id: 1\nBcc`.
		if ( '' === $name || 1 !== preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
			return $this;
		}

		$this->headers[ $name ] = self::one_line( $value );

		return $this;
	}

	/**
	 * A file to send with it.
	 *
	 * @param string $path The file.
	 *
	 * @return self
	 */
	public function attach( string $path ): self {
		if ( is_readable( $path ) ) {
			$this->attachments[] = $path;
		}

		return $this;
	}

	/**
	 * Send it through a transport of its own, rather than the one
	 * everything else goes through.
	 *
	 * @param Transport|null $transport The transport, or null for the usual.
	 *
	 * @return self
	 */
	public function via( ?Transport $transport ): self {
		$this->transport = $transport;

		return $this;
	}

	/**
	 * The finished email.
	 *
	 * @return string
	 */
	public function html(): string {
		$processor = new Processor( $this->groups );

		// The subject takes the same tags as the body — `Your order from
		// {site_name}` is the first example in the README — and used to be
		// sent as typed, because only the body was filled in. It is a
		// header: what a tag returns goes in as words, on one line.
		$this->resolved_subject = self::one_line( $processor->plain( $this->subject, $this->data ) );

		// Kept before each run, because a run starts a fresh report.
		$problems = $processor->problems();
		$unknown  = $processor->unknown();

		$preheader = self::one_line( $processor->plain( $this->preheader, $this->data ) );
		$problems  = array_merge( $problems, $processor->problems() );
		$unknown   = array_merge( $unknown, $processor->unknown() );

		$body = $processor->process( $this->content, $this->data );

		$this->problems = array_merge( $problems, $processor->problems() );
		$this->unknown  = array_values( array_unique( array_merge( $unknown, $processor->unknown() ) ) );

		return $this->wrap( $body, $this->resolved_subject, $preheader );
	}

	/**
	 * The finished email, with samples where the real values would be.
	 *
	 * @return string
	 */
	public function preview(): string {
		$processor = new Processor( $this->groups );

		return $this->wrap(
			$processor->preview( $this->content ),
			self::one_line( $processor->preview( $this->subject ) ),
			self::one_line( wp_strip_all_tags( $processor->preview( $this->preheader ) ) )
		);
	}

	/**
	 * What went wrong filling the tags in.
	 *
	 * @return array<string, string>
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * The tags the last build met that nobody registered.
	 *
	 * Taken out rather than sent, and not a failure -- an email may mention
	 * a tag from a group it did not name -- but the usual cause is a typo
	 * and the usual symptom a gap in a sentence. Worth asserting empty
	 * across every email in a test.
	 *
	 * @return string[]
	 */
	public function unknown(): array {
		return $this->unknown;
	}

	/**
	 * How the last send went: the provider's message id, or why not and
	 * whether trying again could help. Null before a send.
	 *
	 * @return Result|null
	 */
	public function result(): ?Result {
		return $this->result;
	}

	/**
	 * The finished email as a transport is handed it.
	 *
	 * For a queue that sends later, or a test that wants to look. Builds it,
	 * so problems() and unknown() are about this build.
	 *
	 * @return Message
	 */
	public function message(): Message {
		$html = $this->html();

		return new Message(
			to: $this->to,
			subject: $this->resolved_subject,
			html: $html,
			text: $this->text,
			from_email: $this->from[0],
			from_name: $this->from[1],
			reply_to: $this->reply[0],
			reply_to_name: $this->reply[1],
			cc: $this->cc,
			bcc: $this->bcc,
			headers: $this->headers,
			attachments: $this->attachments,
		);
	}

	/**
	 * Send it.
	 *
	 * @return true|WP_Error
	 */
	public function send(): true|WP_Error {
		$this->result = null;

		if ( [] === $this->to ) {
			return new WP_Error( 'no_recipient', __( 'The email has nobody to go to.', 'sugarcommerce' ) );
		}

		$message = $this->message();

		// A tag that could not be filled in leaves a gap in a sentence, and
		// the sentence is usually the one with the amount in it.
		if ( [] !== $this->problems ) {
			return new WP_Error(
				'tags_failed',
				sprintf(
					/* translators: %s: what went wrong, one per tag. */
					__( 'The email was not sent because some of it could not be filled in: %s', 'sugarcommerce' ),
					implode( ' ', $this->problems )
				),
				[ 'problems' => $this->problems ]
			);
		}

		$this->result = Delivery::send( $message, $this->transport );

		/**
		 * Fires after an email has been handed over.
		 *
		 * @param bool     $sent    Whether it went.
		 * @param string[] $to      Who to.
		 * @param string   $subject What it says it is about.
		 * @param Result   $result  How it went, in full.
		 */
		do_action( Runtime::hook( 'sent' ), $this->result->sent, $this->to, $this->resolved_subject, $this->result );

		return $this->result->to_wp();
	}

	/**
	 * Put the body inside the visual template.
	 *
	 * @param string $body      The body, with its tags filled in.
	 * @param string $subject   The subject, with its tags filled in.
	 * @param string $preheader The inbox line, with its tags filled in.
	 *
	 * @return string
	 */
	private function wrap( string $body, string $subject, string $preheader = '' ): string {
		$shell = Templates::get( $this->template );

		$replacements = array_merge(
			self::site_context(),
			[
				'title'    => $subject,
				'subtitle' => '',
				'footer'   => '',
				'logo'     => '',
				'subject'  => $subject,
			],
			$this->context,
			[ 'content' => $body ]
		);

		$replacements = self::look( $replacements, $this->context );

		$filled = [];

		foreach ( $replacements as $key => $value ) {
			// Text goes in as text. The title sits inside an <h1>, and a
			// title built from a customer's name went in as whatever the
			// name had in it. The body, the logo and the footer are markup by
			// contract and go in as they are.
			$filled[ '{' . $key . '}' ] = in_array( $key, self::MARKUP, true )
				? (string) $value
				: esc_html( (string) $value );
		}

		$shell = self::document( strtr( $shell, $filled ), $preheader );

		// Anything the template asked for and nobody filled in. A template
		// placeholder left in the markup shows up as `{colour_primary}` in
		// the middle of the email.
		return (string) preg_replace( '/\{[a-z0-9_]+\}/i', '', $shell );
	}

	/**
	 * Say what language the email is in, which way it reads, and what the
	 * inbox should show after the subject.
	 *
	 * Done to whatever template was used -- the library's, a theme's copy,
	 * another plugin's -- so none of them has to remember. A template that
	 * already says its language is left to say it.
	 *
	 * @param string $html      The filled-in template.
	 * @param string $preheader The inbox line, or empty.
	 *
	 * @return string
	 */
	private static function document( string $html, string $preheader ): string {
		$lang = str_replace( '_', '-', function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '' );
		$dir  = function_exists( 'is_rtl' ) && is_rtl() ? 'rtl' : 'ltr';

		if ( 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $lang ) ) {
			$html = (string) preg_replace(
				'/<html(?![^>]*\blang=)([^>]*)>/i',
				sprintf( '<html lang="%s" dir="%s"$1>', esc_attr( $lang ), $dir ),
				$html,
				1
			);
		}

		if ( '' === $preheader ) {
			return $html;
		}

		// Hidden every way a mail client might show it, and followed by
		// spacing so the inbox does not run on into the email's first words.
		$hidden = sprintf(
			'<div style="display:none;font-size:1px;color:transparent;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">%s%s</div>',
			esc_html( $preheader ),
			str_repeat( '&#847;&zwnj;&nbsp;', 40 )
		);

		$with = (string) preg_replace( '/<body\b[^>]*>/i', '$0' . $hidden, $html, 1, $count );

		return $count > 0 ? $with : $hidden . $html;
	}

	/**
	 * The colours and the alignment a template draws with, made safe and
	 * made to agree with each other.
	 *
	 * A site gives one colour. The words on top of it and the links beside
	 * it follow from that unless the site said otherwise: white words on a
	 * pale header and a pale link on a white page are both a colour doing
	 * what it was told and an email nobody can read.
	 *
	 * Each of these goes into a `style` attribute, so each is checked for
	 * being what it claims to be and not merely escaped.
	 *
	 * @param array<string, mixed> $replacements Everything the template may ask for.
	 * @param array<string, mixed> $given        What this email was given itself.
	 *
	 * @return array<string, mixed>
	 */
	private static function look( array $replacements, array $given ): array {
		$primary = Color::hex( $replacements['color_primary'] ?? '', '#2271b1' );

		// Whether the colour is light is WCAG's call (Color::contrast); what
		// goes with a light one is the email's own: near-black words, not
		// pure black, and a link in the admin's near-black, which its
		// underline still marks as a link, rather than a pale one on white.
		$light = Color::is_light( $primary );

		$replacements['color_primary']    = $primary;
		$replacements['color_on_primary'] = Color::hex( $given['color_on_primary'] ?? '', $light ? '#111111' : '#ffffff' );
		$replacements['color_link']       = Color::hex( $given['color_link'] ?? '', $light ? '#1d2327' : $primary );

		$align = (string) ( $given['header_align'] ?? '' );

		$replacements['header_align'] = in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'center';

		return $replacements;
	}

	/**
	 * What every email knows about the site it came from.
	 *
	 * @return array<string, string>
	 */
	private static function site_context(): array {
		return [
			'site_name'     => (string) get_bloginfo( 'name' ),
			'site_url'      => home_url(),
			'admin_email'   => (string) get_option( 'admin_email' ),

			// The site's year, not the server's. A footer that says 2025 for
			// the first few hours of the new year is a small thing that looks
			// like nobody is home.
			'year'          => (string) current_time( 'Y' ),
			'date'          => (string) current_time( (string) get_option( 'date_format' ) ),
			'time'          => (string) current_time( (string) get_option( 'time_format' ) ),

			'color_primary' => '#2271b1',
		];
	}

	/**
	 * A string with no line breaks in it.
	 *
	 * Everything that goes into a header goes through this. See the note at
	 * the top: the point is that the email sends, not that an injection is
	 * being stopped — PHPMailer already stops that, by refusing the whole
	 * message.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function one_line( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $value ) );
	}
}
