<?php
/**
 * WordPress Mail Transport
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Transports;

use SugarCommerce\Emails\Message;
use SugarCommerce\Emails\Result;
use SugarCommerce\Emails\Transport;
use WP_Error;

/**
 * Through `wp_mail()`, as WordPress sends everything else.
 *
 * The default, and the one that changes nothing: an SMTP plugin, a mail
 * logger and every `wp_mail` filter on the site see these emails exactly as
 * they see the site's own.
 *
 * Two things wp_mail() does not do on its own are done here. The plain-text
 * part goes in beside the HTML, set on PHPMailer for this one message --
 * wp_mail() empties it before every send, so it cannot leak into the next.
 * And when the send fails, the reason PHPMailer gave is kept: wp_mail()
 * returns false and says why only to whoever is listening for
 * `wp_mail_failed`.
 */
final class WpMail implements Transport {

	/**
	 * Construct.
	 *
	 * @param bool $text Whether to send the plain-text part beside the HTML.
	 */
	public function __construct( private bool $text = true ) {}

	/**
	 * Hand a message to wp_mail().
	 *
	 * @param Message $message The finished email.
	 *
	 * @return Result
	 */
	public function send( Message $message ): Result {
		$error = '';
		$text  = $this->text ? $message->text : '';

		$failed = static function ( $wp_error ) use ( &$error ): void {
			if ( $wp_error instanceof WP_Error ) {
				$error = $wp_error->get_error_message();
			}
		};

		$alternative = static function ( $phpmailer ) use ( $text ): void {
			if ( '' !== $text && is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
				$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's.
			}
		};

		add_action( 'wp_mail_failed', $failed );
		add_action( 'phpmailer_init', $alternative );

		try {
			$sent = wp_mail(
				$message->to,
				$message->subject,
				$message->html,
				$message->lines( (string) get_bloginfo( 'charset' ) ?: 'UTF-8' ),
				$message->attachments
			);
		} finally {
			remove_action( 'wp_mail_failed', $failed );
			remove_action( 'phpmailer_init', $alternative );
		}

		return $sent
			? Result::ok()
			: Result::failed( $error );
	}

	/**
	 * Its name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'wp_mail';
	}
}
