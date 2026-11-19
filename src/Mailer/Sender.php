<?php
/**
 * Sender
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Mailer;

use SugarCommerce\Emails\Email;
use SugarCommerce\Emails\Emails;
use SugarCommerce\Emails\Mailer;
use WP_Error;

/**
 * Sending one registered email about one record, and what that reads.
 *
 * One road for every email: find what it is about, ask whether anything
 * wants to send it instead, check the site has not switched it off, work out
 * who it goes to, build it and say how it went. A plugin's own emails take
 * this road, so another plugin's email is switched off, reworded and
 * reported exactly as theirs are.
 */
final class Sender {

	/**
	 * What goes with an email besides its words, from its registration and
	 * from whoever sends it.
	 *
	 * @var array<int, string>
	 */
	private const EXTRAS = [ 'attachments', 'cc', 'bcc' ];

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * Send an email.
	 *
	 * @param string               $id     The email.
	 * @param mixed                $record What it is about: a row or an id.
	 * @param array<string, mixed> $args   `to`, `subject`, `body`; and
	 *                                     `attachments` (file paths), `cc`
	 *                                     and `bcc` (addresses), added to
	 *                                     what the email was registered with.
	 *
	 * @return true|WP_Error True when it went, or was not meant to.
	 */
	public function send( string $id, mixed $record, array $args = [] ): true|WP_Error {
		$this->mailer->ready();

		$id    = sanitize_key( $id );
		$email = $this->mailer->registry()->get( $id );

		if ( null === $email ) {
			return new WP_Error(
				$this->mailer->hook( 'unknown_email' ),
				sprintf( $this->mailer->label( 'unknown_email' ), $id )
			);
		}

		$about = $this->mailer->contexts()->load( (string) $email['context'], $record );

		if ( null === $about ) {
			return new WP_Error( $this->mailer->hook( 'email_no_record' ), $this->mailer->label( 'no_record' ) );
		}

		/**
		 * Filter an email before it is sent, to send it some other way or
		 * not at all.
		 *
		 * Return true and the email counts as dealt with; return a WP_Error
		 * and it counts as failed. Either way nothing is sent from here and
		 * neither of the actions below fires. Null, the default, carries on.
		 *
		 * @param null|bool|WP_Error   $sent  Null to carry on.
		 * @param string               $id    The email.
		 * @param mixed                $about What it is about.
		 * @param array<string, mixed> $args  What the caller passed.
		 */
		$sent = apply_filters( $this->mailer->hook( 'send_email' ), null, $id, $about, $args );

		if ( null !== $sent ) {
			return is_wp_error( $sent ) ? $sent : true;
		}

		// Switched off by the site. Not an error -- it is a setting, and
		// reporting it as a failure would fill a log with a preference.
		if ( ! $this->enabled( $id ) ) {
			return true;
		}

		$to = $args['to'] ?? $this->recipient( $id, $about );

		if ( empty( $to ) ) {
			return $this->failed(
				$id,
				$about,
				new WP_Error( $this->mailer->hook( 'email_no_recipient' ), $this->mailer->label( 'no_recipient' ) )
			);
		}

		$overrides = [
			'to'   => $to,
			'data' => $about,
		];

		if ( '' !== trim( (string) ( $args['subject'] ?? '' ) ) ) {
			$overrides['subject'] = (string) $args['subject'];
		}

		if ( '' !== trim( (string) ( $args['body'] ?? '' ) ) ) {
			$overrides['content'] = wpautop( (string) $args['body'] );
		}

		foreach ( self::EXTRAS as $extra ) {
			if ( isset( $args[ $extra ] ) ) {
				$overrides[ $extra ] = $args[ $extra ];
			}
		}

		$sent = $this->deliver( $id, $overrides );

		if ( is_wp_error( $sent ) ) {
			return $this->failed( $id, $about, $sent );
		}

		/**
		 * Fires once an email has been handed over for delivery.
		 *
		 * @param string          $id    The email.
		 * @param mixed           $about What it is about.
		 * @param string|string[] $to    Who it went to.
		 */
		do_action( $this->mailer->hook( 'email_sent' ), $id, $about, $to );

		return true;
	}

	/**
	 * Build a registered email and send it, from the site.
	 *
	 * The one place a registered email is handed to WordPress, whoever
	 * asked: a send, a test, a scheduled summary. The From name, the From
	 * address and the Reply-To are put on here -- once, which is what makes
	 * an email and a test of it come from the same sender.
	 *
	 * Whether the email is switched on is not asked here. That is send()'s
	 * question, and a test is sent from one that is off.
	 *
	 * @param string               $id        The email.
	 * @param array<string, mixed> $overrides What Emails is told in place of
	 *                                        the saved email: `to`, `data`,
	 *                                        `subject`, `content`, `context`.
	 *
	 * @return true|WP_Error
	 */
	public function deliver( string $id, array $overrides ): true|WP_Error {
		$email = Emails::compose( $this->mailer->group(), $id, array_diff_key( $overrides, array_flip( self::EXTRAS ) ) );

		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$this->extras( $email, $id, $overrides );

		return $this->from_site( $email )->via( $this->mailer->transport() )->send();
	}

	/**
	 * Put on what goes with an email besides its words: files, copies to
	 * others, headers of its own.
	 *
	 * From its registration (`attachments`, `cc`, `bcc`: a list, or a
	 * callable given what it is about and its id), then from the caller,
	 * then through the filters -- so a plugin adds a PDF invoice to
	 * another plugin's receipt, or a copy to the bookkeeper, without
	 * either knowing of the other.
	 *
	 * @param Email                $email     The email, built.
	 * @param string               $id        Which it is.
	 * @param array<string, mixed> $overrides What deliver() was given.
	 *
	 * @return void
	 */
	private function extras( Email $email, string $id, array $overrides ): void {
		$about      = $overrides['data'] ?? null;
		$registered = $this->mailer->registry()->get( $id ) ?? [];
		$lists      = [];

		foreach ( self::EXTRAS as $extra ) {
			$lists[ $extra ] = array_merge(
				self::listed( $registered[ $extra ] ?? [], $about, $id ),
				self::listed( $overrides[ $extra ] ?? [], $about, $id )
			);
		}

		/**
		 * Filter the files sent with an email.
		 *
		 * @param array<int, string> $paths The files, as paths on this server.
		 *                                  One that cannot be read is left off.
		 * @param string             $id    The email.
		 * @param mixed              $about What it is about.
		 */
		$attachments = (array) apply_filters( $this->mailer->hook( 'email_attachments' ), $lists['attachments'], $id, $about );

		/**
		 * Filter who is copied in on an email.
		 *
		 * @param array<int, string> $cc    The addresses.
		 * @param string             $id    The email.
		 * @param mixed              $about What it is about.
		 */
		$cc = (array) apply_filters( $this->mailer->hook( 'email_cc' ), $lists['cc'], $id, $about );

		/**
		 * Filter who is copied in on an email without the others seeing.
		 *
		 * @param array<int, string> $bcc   The addresses.
		 * @param string             $id    The email.
		 * @param mixed              $about What it is about.
		 */
		$bcc = (array) apply_filters( $this->mailer->hook( 'email_bcc' ), $lists['bcc'], $id, $about );

		/**
		 * Filter an email's headers of its own, by name: `X-Order-Id`.
		 *
		 * From, Reply-To, Cc and Bcc have their own ways in; a name that is
		 * not one word, or a value with a line break, is left off.
		 *
		 * @param array<string, string> $headers By name.
		 * @param string                $id      The email.
		 * @param mixed                 $about   What it is about.
		 */
		$headers = (array) apply_filters( $this->mailer->hook( 'email_headers' ), [], $id, $about );

		foreach ( $attachments as $path ) {
			$email->attach( (string) $path );
		}

		foreach ( $cc as $address ) {
			$email->cc( (string) $address );
		}

		foreach ( $bcc as $address ) {
			$email->bcc( (string) $address );
		}

		foreach ( $headers as $name => $value ) {
			if ( ! in_array( strtolower( (string) $name ), [ 'from', 'reply-to', 'cc', 'bcc', 'to', 'subject', 'content-type' ], true ) ) {
				$email->header( (string) $name, (string) $value );
			}
		}

		/**
		 * Fires as an email is about to go, with the email itself: the last
		 * word on anything above.
		 *
		 * @param Email  $email The email.
		 * @param string $id    Which it is.
		 * @param mixed  $about What it is about.
		 */
		do_action( $this->mailer->hook( 'email_before_send' ), $email, $id, $about );
	}

	/**
	 * A list given as a list, one value, or a callable returning either.
	 *
	 * @param mixed  $given What was given.
	 * @param mixed  $about What the email is about.
	 * @param string $id    The email.
	 *
	 * @return array<int, string>
	 */
	private static function listed( mixed $given, mixed $about, string $id ): array {
		if ( is_callable( $given ) && ! is_string( $given ) ) {
			$given = $given( $about, $id );
		}

		if ( is_string( $given ) ) {
			$given = explode( ',', $given );
		}

		return array_values( array_filter( array_map( static fn( $value ): string => trim( (string) $value ), (array) $given ), 'strlen' ) );
	}

	/**
	 * Say who an email is from, and where a reply to it goes.
	 *
	 * @param Email $email An email that has not been sent yet.
	 *
	 * @return Email The same one.
	 */
	public function from_site( Email $email ): Email {
		$sender = $this->identity();

		// With no address there is nothing to name: WordPress's own sender
		// is used.
		if ( '' !== $sender['address'] ) {
			$email->from( $sender['address'], $sender['name'] );
		}

		if ( '' !== $sender['reply_to'] ) {
			$email->reply_to( $sender['reply_to'] );
		}

		return $email;
	}

	/**
	 * The same, as header lines, for an email that is not built by Emails:
	 * a test of an email with nothing yet to be about, which is a preview
	 * handed straight to wp_mail().
	 *
	 * @return array<int, string>
	 */
	public function headers(): array {
		$sender  = $this->identity();
		$headers = [];

		if ( '' !== $sender['address'] ) {
			$headers[] = '' === $sender['name']
				? 'From: ' . $sender['address']
				: sprintf( 'From: %1$s <%2$s>', $sender['name'], $sender['address'] );
		}

		if ( '' !== $sender['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $sender['reply_to'];
		}

		return $headers;
	}

	/**
	 * Who the site's emails come from, and where a reply to one goes.
	 *
	 * One answer for every email a mailer sends, and for a test of any of
	 * them. A site that has filled in neither is sent from its name, at the
	 * address WordPress itself would use -- worked out here the way
	 * wp_mail() works it out, so that a name can be put beside it: a From
	 * header needs both.
	 *
	 * A reply goes to the address under the `reply_to` key when there is
	 * one and it is not the From address. A From address is very often one
	 * nobody reads.
	 *
	 * @return array{name: string, address: string, reply_to: string}
	 *         `reply_to` is empty when a reply should go where the email
	 *         came from.
	 */
	public function identity(): array {
		$store   = $this->mailer->store();
		$name    = trim( sanitize_text_field( (string) $store->get( $store->key( 'from_name' ), '' ) ) );
		$address = sanitize_email( (string) $store->get( $store->key( 'from_address' ), '' ) );
		$reply   = sanitize_email( (string) $store->get( $store->key( 'reply_to' ), '' ) );

		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}

		if ( '' === $address || ! is_email( $address ) ) {
			$address = Mailer::default_address();
		}

		$sender = [
			'name'     => $name,
			'address'  => $address,

			// Only when it is somewhere else. A Reply-To that repeats the
			// From address says nothing.
			'reply_to' => is_email( $reply ) && strtolower( $reply ) !== strtolower( $address ) ? $reply : '',
		];

		/**
		 * Filter who the emails come from.
		 *
		 * @param array{name: string, address: string, reply_to: string} $sender
		 *        The From name, the From address, and where a reply goes
		 *        (empty for the From address).
		 */
		$filtered = (array) apply_filters( $this->mailer->hook( 'email_sender' ), $sender );

		return [
			'name'     => trim( sanitize_text_field( (string) ( $filtered['name'] ?? $sender['name'] ) ) ),
			'address'  => sanitize_email( (string) ( $filtered['address'] ?? $sender['address'] ) ),
			'reply_to' => sanitize_email( (string) ( $filtered['reply_to'] ?? '' ) ),
		];
	}

	/**
	 * Whether the site has an email switched on.
	 *
	 * What the switch on its panel was saved as; until it has been saved,
	 * what the email was registered with.
	 *
	 * @param string $id The email.
	 *
	 * @return bool
	 */
	public function enabled( string $id ): bool {
		$email = $this->mailer->registry()->get( $id );

		if ( null === $email ) {
			return false;
		}

		$saved = $this->mailer->store()->saved( (string) $email['setting'] );

		return null !== $saved && array_key_exists( 'enabled', $saved )
			? (bool) $saved['enabled']
			: (bool) $email['enabled'];
	}

	/**
	 * What the site owner has written for an email, as Emails reads it.
	 *
	 * Without `enabled`, which Emails would act on by quietly not sending.
	 * Whether an email goes is decided in send(), so that a test can still
	 * be sent from one that is switched off.
	 *
	 * @param string $id The email.
	 *
	 * @return array<string, mixed> `template` and `context` always; `to`,
	 *                              `subject` and `content` when written.
	 */
	public function stored( string $id ): array {
		$email = $this->mailer->registry()->get( $id );

		if ( null === $email ) {
			return [];
		}

		// The look first: the design the email is drawn in and the colour,
		// logo and footer the design is filled in with. The same for every
		// way an email is built, so a preview is what is sent.
		$stored = $this->mailer->look()->settings( $id );
		$saved  = $this->mailer->store()->saved( (string) $email['setting'] );

		if ( null === $saved ) {
			return $stored;
		}

		// Several addresses, separated by commas, which is what the box
		// says beside it.
		$to = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $saved['recipient'] ?? '' ) ) ) );

		if ( [] !== $to ) {
			$stored['to'] = array_values( $to );
		}

		if ( '' !== trim( (string) ( $saved['subject'] ?? '' ) ) ) {
			$stored['subject'] = (string) $saved['subject'];
		}

		// The editor keeps a body the way WordPress keeps a post, without
		// its paragraphs; wpautop() puts them back.
		//
		// Unless it is wording the email shipped with earlier and nobody
		// has touched: then it was never the site's own, and the email's
		// wording as it is now is what goes. See Registry::untouched().
		if ( '' !== trim( (string) ( $saved['body'] ?? '' ) ) && ! $this->mailer->registry()->untouched( $id, (string) $saved['body'] ) ) {
			$stored['content'] = wpautop( (string) $saved['body'] );
		}

		// The heading is the template's title, which sits above the body.
		// Unless a preview is of a card whose heading has just been
		// emptied: then the saved one is not what is on the screen.
		if ( '' !== trim( (string) ( $saved['heading'] ?? '' ) ) && ! $this->mailer->look()->tried( 'no_heading' ) ) {
			$stored['context']['title'] = (string) $saved['heading'];
		}

		return $stored;
	}

	/**
	 * The subject and body an email would be sent with now.
	 *
	 * @param string $id The email.
	 *
	 * @return array{subject: string, body: string}
	 */
	public function wording( string $id ): array {
		$email  = $this->mailer->registry()->get( $id ) ?? [];
		$stored = $this->stored( $id );

		return [
			'subject' => (string) ( $stored['subject'] ?? $email['subject'] ?? '' ),
			'body'    => (string) ( $stored['content'] ?? $email['body'] ?? '' ),
		];
	}

	/**
	 * Who an email goes to.
	 *
	 * `admin`: whoever the site typed into the panel's Send to box, then
	 * the address under the `admin_email` key, then the site's admin
	 * address. A callable: whoever it says. `customer`: the record's
	 * `email`, then whoever the `customer_email` callable says.
	 *
	 * @param string $id    The email.
	 * @param mixed  $about What it is about.
	 *
	 * @return string|string[] Empty when there is nobody.
	 */
	public function recipient( string $id, mixed $about ): string|array {
		$email = $this->mailer->registry()->get( $id ) ?? [];
		$who   = $email['recipient'] ?? 'customer';

		if ( 'admin' === $who ) {
			$typed = $this->stored( $id )['to'] ?? [];

			return [] !== $typed ? $typed : $this->admin_email();
		}

		if ( 'customer' !== $who && is_callable( $who ) ) {
			$to = call_user_func( $who, $about );

			return is_array( $to ) ? array_values( array_filter( $to ) ) : (string) $to;
		}

		$own = is_object( $about ) ? ( $about->email ?? '' ) : ( is_array( $about ) ? ( $about['email'] ?? '' ) : '' );

		if ( '' !== (string) $own ) {
			return (string) $own;
		}

		$find = $this->mailer->config( 'customer_email' );

		return is_callable( $find ) ? (string) call_user_func( $find, $about ) : '';
	}

	/**
	 * Where a note to the site goes when nobody typed an address on its
	 * panel.
	 *
	 * @return string
	 */
	public function admin_email(): string {
		$store = $this->mailer->store();

		return (string) $store->get( $store->key( 'admin_email' ), get_option( 'admin_email' ) );
	}

	/**
	 * Say an email did not go, and hand the reason back.
	 *
	 * @param string   $id    The email.
	 * @param mixed    $about What it is about.
	 * @param WP_Error $error Why.
	 *
	 * @return WP_Error
	 */
	private function failed( string $id, mixed $about, WP_Error $error ): WP_Error {
		/**
		 * Fires when an email could not be sent.
		 *
		 * @param string   $id    The email.
		 * @param mixed    $about What it is about.
		 * @param WP_Error $error Why.
		 */
		do_action( $this->mailer->hook( 'email_failed' ), $id, $about, $error );

		return $error;
	}
}
