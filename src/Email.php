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

use SugarCommerce\Emails\Models\Result;
use SugarCommerce\Emails\Traits\Email\Addresses;
use SugarCommerce\Emails\Traits\Email\Rendering;
use SugarCommerce\Emails\Traits\Email\Sending;

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

	use Addresses;
	use Rendering;
	use Sending;

	/**
	 * The template placeholders that take markup.
	 *
	 * The body, and the logo and footer the Parts draw. Everything else a
	 * template asks for is a word inside an element — the title in an
	 * `<h1>`, the subject in a `<title>` — and goes in escaped.
	 *
	 * @var string[]
	 */
	private const array MARKUP = [ 'content', 'logo', 'footer' ];

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
	 * For the provider to send back with a bounce or a complaint.
	 *
	 * @var array<string, string>
	 */
	private array $metadata = [];

	/**
	 * What kind of email it is, for the provider's reports.
	 *
	 * @var string
	 */
	private string $tag = '';

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
	 * Something for the provider to send back with a bounce or a complaint.
	 *
	 * Put the order's id here and a bounce webhook says which order it was
	 * about, with no lookup by address. Postmark sends it as Metadata,
	 * Resend as tags; wp_mail() has nowhere to put it and leaves it off.
	 *
	 *     ->meta( 'order_id', $order->id )
	 *
	 * @param string     $key   A short name: letters, numbers, `_` and `-`,
	 *                          up to 20 (Postmark's longest).
	 * @param string|int $value Its value, on one line. Empty takes it off.
	 *
	 * @return self
	 */
	public function meta( string $key, string|int $value ): self {
		$key = self::one_line( $key );

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,20}$/', $key ) ) {
			return $this;
		}

		$value = self::one_line( (string) $value );

		if ( '' === $value ) {
			unset( $this->metadata[ $key ] );
		} else {
			$this->metadata[ $key ] = $value;
		}

		return $this;
	}

	/**
	 * What kind of email it is, for the provider's reports: `receipt`,
	 * `password-reset`.
	 *
	 * One word for the provider's dashboard and its bounce webhooks --
	 * Postmark's Tag, a `category` tag at Resend. Not tags(), which names
	 * the groups of merge tags the body is filled in from.
	 *
	 * @param string $tag The kind. Empty for none.
	 *
	 * @return self
	 */
	public function tag( string $tag ): self {
		$this->tag = self::one_line( $tag );

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
