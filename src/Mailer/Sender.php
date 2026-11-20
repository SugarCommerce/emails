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
use SugarCommerce\Emails\Traits\Sender\Addresses;
use SugarCommerce\Emails\Traits\Sender\Settings;
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

	use Addresses;
	use Settings;

	/**
	 * What goes with an email besides its words, from its registration and
	 * from whoever sends it.
	 *
	 * @var array<int, string>
	 */
	private const array EXTRAS = [ 'attachments', 'cc', 'bcc' ];

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
