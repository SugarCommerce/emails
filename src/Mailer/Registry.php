<?php
/**
 * Registry
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Mailer;

use SugarCommerce\Text\Text;
use SugarCommerce\Emails\Emails;
use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Tags;

/**
 * The emails and merge tags a mailer has had registered.
 *
 * Emails and Tags keep the tags and build the emails; this is the editable
 * side of it. It turns the plain arguments a plugin writes -- a label, a
 * callback given the record, a sample -- into what they take, and it
 * remembers what each email was registered with, which they have no place
 * for: who it goes to, what it is about, which setting its panel saves
 * under, and the wording it used to ship with.
 *
 * It also takes a registration at any time. One made before the plugin's
 * own are in place is kept and added after them: built-in wording is
 * translated, which cannot be done before `init`, and a tag another plugin
 * names after a built-in one is meant to replace it, not to be replaced.
 */
final class Registry {

	/**
	 * Whether registrations go straight in, or wait.
	 *
	 * @var bool
	 */
	private bool $open = false;

	/**
	 * The emails, by name, as each was registered.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $emails = [];

	/**
	 * Tags and emails registered before the plugin's own were, in the
	 * order they arrived.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $waiting = [];

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * From here on, a registration goes straight in.
	 *
	 * @return void
	 */
	public function open(): void {
		$this->open = true;
	}

	/**
	 * Add whatever was registered before the plugin's own were.
	 *
	 * @return void
	 */
	public function flush(): void {
		$waiting       = $this->waiting;
		$this->waiting = [];

		foreach ( $waiting as [ $kind, $name, $args ] ) {
			match ( $kind ) {
				'tag'    => $this->add_tag( $name, $args ),
				'forget' => $this->forget( $name ),
				default  => $this->add_email( $name, $args ),
			};
		}
	}

	/**
	 * Register a merge tag, now or once the plugin's own are in place.
	 *
	 * @param string               $tag  The tag, without its braces.
	 * @param array<string, mixed> $args {
	 *     @type callable        $callback    Given what the email is about,
	 *                                        returns the text. Required.
	 *     @type string          $label       What it is called under Add Tag.
	 *     @type string          $description One short sentence saying what
	 *                                        it puts in the email.
	 *     @type string          $context     What kind of email can use it.
	 *                                        The default context when left out.
	 *     @type string|callable $sample      What a preview shows in its place.
	 *     @type bool            $html        Whether the callback returns
	 *                                        markup. False, and it is escaped.
	 *     @type string          $group       The heading it is listed under in
	 *                                        Add Tag.
	 * }
	 *
	 * @return bool False when it has no name or nothing to call. True when
	 *              it was registered, or kept to be.
	 */
	public function tag( string $tag, array $args ): bool {
		$tag = sanitize_key( trim( $tag, '{} ' ) );

		if ( '' === $tag || ! is_callable( $args['callback'] ?? null ) ) {
			return false;
		}

		if ( ! $this->open ) {
			$this->waiting[] = [ 'tag', $tag, $args ];

			return true;
		}

		return $this->add_tag( $tag, $args );
	}

	/**
	 * Register an email, now or once the plugin's own are in place.
	 *
	 * @param string               $id   Its name.
	 * @param array<string, mixed> $args {
	 *     @type string          $label       What its panel is called.
	 *     @type string          $description When it is sent, as one sentence.
	 *     @type string          $subject     The subject it starts with.
	 *     @type string          $body        The wording it starts with, as
	 *                                        lines with a blank between or as
	 *                                        markup.
	 *     @type string          $heading     A title above the body.
	 *     @type string          $preheader   The line an inbox shows after the
	 *                                        subject. Tags work in it.
	 *     @type string          $template    A design of its own, by name.
	 *     @type string|callable $recipient   `customer` (the default), `admin`,
	 *                                        or a callable given the record.
	 *     @type string          $context     What it is about.
	 *     @type bool            $enabled     Whether it is sent until the
	 *                                        site says otherwise.
	 *     @type string          $setting     The setting its panel saves under.
	 *     @type bool|callable   $panel       False for no panel, or a callable
	 *                                        returning fields of its own.
	 *     @type string[]        $earlier     Bodies it shipped with before,
	 *                                        word for word. See untouched().
	 * }
	 *
	 * @return bool False when it has no name. True when it was registered,
	 *              or kept to be.
	 */
	public function email( string $id, array $args ): bool {
		$id = sanitize_key( $id );

		if ( '' === $id ) {
			return false;
		}

		if ( ! $this->open ) {
			$this->waiting[] = [ 'email', $id, $args ];

			return true;
		}

		return $this->add_email( $id, $args );
	}

	/**
	 * Take an email away.
	 *
	 * @param string $id The email.
	 *
	 * @return bool Whether there was one. Before the plugin's own are in,
	 *              true: it is taken away as it arrives.
	 */
	public function forget( string $id ): bool {
		$id = sanitize_key( $id );

		// One that is still waiting never arrives.
		$before        = count( $this->waiting );
		$this->waiting = array_values(
			array_filter(
				$this->waiting,
				static fn( array $item ): bool => ! ( 'email' === $item[0] && $id === $item[1] )
			)
		);

		// Before the plugin's own are in: one of those is taken away once
		// it arrives, so taking a built-in email away does not depend on
		// when somebody asked.
		if ( ! $this->open ) {
			$this->waiting[] = [ 'forget', $id, [] ];

			return true;
		}

		if ( ! isset( $this->emails[ $id ] ) ) {
			return count( $this->waiting ) < $before;
		}

		unset( $this->emails[ $id ] );

		Emails::remove( $this->mailer->group(), $id );

		return true;
	}

	/**
	 * Every email, by name, as each was registered.
	 *
	 * Asking is what registers them, when nothing has yet: whoever needs an
	 * email first -- a settings screen put together before `init`, a send
	 * from an early hook -- finds it there.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function emails(): array {
		$this->mailer->ready();

		return $this->emails;
	}

	/**
	 * One email as it was registered, or null.
	 *
	 * @param string $id The email.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$this->mailer->ready();

		return $this->emails[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * The merge tags one kind of email can use, described.
	 *
	 * Read back from Tags, so a tag put there by something that went round
	 * self::tag() is still listed.
	 *
	 * @param string $context What kind of email.
	 *
	 * @return array<int, array{tag: string, placeholder: string, label: string, description: string, html: bool, group: string}>
	 */
	public function tags( string $context ): array {
		$tags = [];

		foreach ( Tags::group( $this->mailer->contexts()->group( $context ) ) as $tag ) {
			$tags[] = [
				'tag'         => $tag->name(),
				'placeholder' => $tag->placeholder(),
				'label'       => (string) $tag->get( 'label', '' ),
				'description' => (string) $tag->get( 'description', '' ),
				'html'        => 'html' === (string) $tag->get( 'type', 'text' ),
				'group'       => (string) $tag->get( 'heading', '' ),
			];
		}

		return $tags;
	}

	/**
	 * Whether a saved body is wording the email shipped with earlier, with
	 * nothing changed in it.
	 *
	 * Opening a settings screen often writes an email's wording into its
	 * setting so the editor has something in it, and from then on that copy
	 * is what is sent. So a site that has only ever looked at the screen
	 * holds the wording of the day it looked, and a line added to the email
	 * since would reach nobody. An email registered with the bodies it used
	 * to ship (`earlier`) has a saved body that is still one of them told
	 * apart from one somebody wrote.
	 *
	 * Compared without white space and as paragraphs, because an editor
	 * keeps a body without them and may break its lines anywhere. One
	 * changed word and it is the site's own, and is left alone for ever.
	 *
	 * @param string $id   The email.
	 * @param string $body The body as it was saved.
	 *
	 * @return bool
	 */
	public function untouched( string $id, string $body ): bool {
		$flat = static fn( string $text ): string => (string) preg_replace( '/\s+/', '', wpautop( $text ) );
		$body = $flat( $body );

		foreach ( (array) ( $this->get( $id )['earlier'] ?? [] ) as $old ) {
			if ( $flat( (string) $old ) === $body ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hand one tag to Tags.
	 *
	 * @param string               $tag  The tag, already made a key.
	 * @param array<string, mixed> $args What it was registered with.
	 *
	 * @return bool
	 */
	private function add_tag( string $tag, array $args ): bool {
		$contexts = $this->mailer->contexts();
		$context  = (string) ( $args['context'] ?? $contexts->default() );
		$group    = $contexts->group( $context );
		$callback = $args['callback'];

		if ( '' === $group ) {
			$this->unknown_context( $context, 'register_tag' );

			return false;
		}

		return null !== Tags::add(
			$group,
			$tag,
			[
				'label'       => (string) ( $args['label'] ?? Text::humanize( $tag ) ),
				'description' => (string) ( $args['description'] ?? '' ),

				// The heading it is listed under in Add Tag. Kept under a
				// name of its own: `group` and `groups` already mean
				// something else to Tags.
				'heading'     => (string) ( $args['group'] ?? '' ),

				// A tag is text unless it says otherwise, and text is
				// escaped on the way into the email: what a callback
				// returns is very often what a customer typed.
				'type'        => empty( $args['html'] ) ? 'text' : 'html',

				// What it shows in a preview, where there is nothing to
				// read. Without one a preview is of the template, braces
				// and all, and not of the email somebody is about to send.
				'preview'     => self::sample( $args['sample'] ?? null, ! empty( $args['html'] ) ),

				// The sender passes a row or an id; the callback is always
				// handed the row, so a tag is a line of code and not a
				// lookup followed by one.
				'callback'    => static function ( $data ) use ( $callback, $context, $contexts ): string {
					$about = $contexts->load( $context, $data );

					if ( null === $about ) {
						return '';
					}

					$value = call_user_func( $callback, $about );

					return is_scalar( $value ) ? (string) $value : '';
				},
			]
		);
	}

	/**
	 * A tag's sample, as a preview shows it.
	 *
	 * A sample is put in as it is given. A text tag's real value is
	 * escaped, so its sample is too: a preview that drew markup the sent
	 * email would show as angle brackets is not a preview.
	 *
	 * @param mixed $sample A string, something to call for one, or nothing.
	 * @param bool  $html   Whether the tag is markup.
	 *
	 * @return callable|null Null for no sample, and the tag shows as itself.
	 */
	private static function sample( mixed $sample, bool $html ): ?callable {
		if ( null === $sample ) {
			return null;
		}

		return static function () use ( $sample, $html ): string {
			$text = is_callable( $sample ) ? call_user_func( $sample ) : $sample;
			$text = is_scalar( $text ) ? (string) $text : '';

			return $html ? $text : esc_html( $text );
		};
	}

	/**
	 * Remember one email and hand it to Emails.
	 *
	 * @param string               $id   Its name, already made a key.
	 * @param array<string, mixed> $args What it was registered with.
	 *
	 * @return bool
	 */
	private function add_email( string $id, array $args ): bool {
		$email = array_merge(
			[
				'label'       => Text::humanize( $id ),
				'description' => '',
				'subject'     => '',
				'body'        => '',
				'heading'     => '',
				'preheader'   => '',

				// A design of its own, by name. Empty, and it is drawn in
				// whichever the site chose for all its emails.
				'template'    => '',
				'recipient'   => 'customer',
				'context'     => $this->mailer->contexts()->default(),
				'enabled'     => true,

				// Every email is saved under the key prefix and its name,
				// so a site's edited wording is found where it was left.
				'setting'     => $this->mailer->store()->email_key( $id ),
				'panel'       => true,
				'earlier'     => [],
			],
			$args
		);

		$group = $this->mailer->contexts()->group( (string) $email['context'] );

		if ( '' === $group ) {
			$this->unknown_context( (string) $email['context'], 'register_email' );

			return false;
		}

		// As paragraphs. Wording is written as lines with a blank between,
		// which reads well in the source and arrives in an inbox as one
		// run-on paragraph unless somebody puts the paragraphs in.
		$email['body'] = wpautop( (string) $email['body'] );

		$this->emails[ $id ] = $email;

		$sender = $this->mailer->sender();

		return Emails::add(
			$this->mailer->group(),
			$id,
			[
				'label'       => (string) $email['label'],
				'description' => (string) $email['description'],
				'subject'     => (string) $email['subject'],
				'content'     => $email['body'],
				'tag_groups'  => [ $group ],
				'preheader'   => (string) $email['preheader'],
				'context'     => '' === (string) $email['heading'] ? [] : [ 'title' => (string) $email['heading'] ],

				// Where the site's own copy of this email comes from. The
				// registered subject and body are the defaults; whatever
				// the site owner writes on its panel replaces them.
				'settings'    => static fn (): array => $sender->stored( $id ),
			]
		);
	}

	/**
	 * Say that a registration named a context nobody has.
	 *
	 * Out loud, because the alternative is a tag that is in no editor and a
	 * plugin author with no idea why.
	 *
	 * @param string $context The name given.
	 * @param string $method  Which method was called.
	 *
	 * @return void
	 */
	private function unknown_context( string $context, string $method ): void {
		// Named as the plugin's own wrapper is, when it has one: that is
		// what the plugin author called.
		$called = $this->mailer->config( 'functions', [] )[ $method ] ?? Mailer::class . '::' . $method;

		_doing_it_wrong(
			esc_html( (string) $called ),
			esc_html( sprintf( $this->mailer->label( 'unknown_context' ), $context, $this->mailer->hook( 'email_contexts' ) ) ),
			'1.0.0'
		);
	}
}
