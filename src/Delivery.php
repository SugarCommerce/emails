<?php
/**
 * Delivery
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails;

use SugarCommerce\Emails\Transports\WpMail;
use SugarCommerce\Emails\Utils\Runtime;

/**
 * Where every finished email is handed over, and through what.
 *
 * Through wp_mail() unless a transport was named: by the email itself
 * (`Email::via()`), by its Mailer (`transport`), or for everything this copy
 * of the library sends with `Delivery::use()`. Each plugin bundles its own
 * copy, so a plugin that sends its receipts through Postmark leaves another
 * plugin's emails where they were.
 *
 * And one safety catch: `redirect()` sends every email to one inbox
 * instead, with whoever it was for in the subject and an `X-Original-To`
 * header. The usual way a staging copy of a shop emails the real customers
 * in its database is that nobody set it.
 */
final class Delivery {

	/**
	 * The transport named for everything, or null for wp_mail().
	 *
	 * @var Transport|null
	 */
	private static ?Transport $transport = null;

	/**
	 * The one inbox everything goes to, or empty.
	 *
	 * @var string
	 */
	private static string $redirect = '';

	/**
	 * Send everything through one transport. Null for wp_mail() again.
	 *
	 * @param Transport|null $transport The transport.
	 *
	 * @return void
	 */
	public static function use( ?Transport $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * The transport an email with none of its own goes through.
	 *
	 * @return Transport
	 */
	public static function transport(): Transport {
		$transport = self::$transport ?? new WpMail();

		/**
		 * Filter the transport an email with none of its own goes through.
		 *
		 * Named from this copy's own prefix, so it answers for this
		 * plugin's emails and nobody else's.
		 *
		 * @param Transport $transport The transport.
		 */
		$filtered = apply_filters( Runtime::hook( 'transport' ), $transport );

		return $filtered instanceof Transport ? $filtered : $transport;
	}

	/**
	 * Send every email to one inbox instead. Empty to stop.
	 *
	 * @param string $address The inbox.
	 *
	 * @return void
	 */
	public static function redirect( string $address ): void {
		self::$redirect = trim( $address );
	}

	/**
	 * The inbox every email goes to instead, or empty.
	 *
	 * @return string
	 */
	public static function redirected_to(): string {
		/**
		 * Filter the one inbox every email goes to instead.
		 *
		 * For a staging site: return an address and nobody else is sent
		 * anything.
		 *
		 * @param string $address The inbox, or empty.
		 */
		$address = sanitize_email( (string) apply_filters( Runtime::hook( 'redirect' ), self::$redirect ) );

		return is_email( $address ) ? $address : '';
	}

	/**
	 * Hand a message over.
	 *
	 * @param Message        $message The finished email.
	 * @param Transport|null $via     Its own transport, or null for the
	 *                                one everything goes through.
	 *
	 * @return Result
	 */
	public static function send( Message $message, ?Transport $via = null ): Result {
		$transport = $via ?? self::transport();

		if ( [] === $message->to ) {
			return Result::failed( __( 'The email has nobody to go to.', 'sugarcommerce' ) )->from( $transport->name() );
		}

		$redirect = self::redirected_to();

		if ( '' !== $redirect ) {
			$message = self::redirected( $message, $redirect );
		}

		$result = $transport->send( $message )->from( $transport->name() );

		/**
		 * Fires once a message has been handed over, whether it went or not.
		 *
		 * @param Result  $result  How it went: the provider's message id, or
		 *                         why not and whether trying again could help.
		 * @param Message $message What was handed over.
		 */
		do_action( Runtime::hook( 'delivered' ), $result, $message );

		return $result;
	}

	/**
	 * Forget the transport and the redirect. For tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$transport = null;
		self::$redirect  = '';
	}

	/**
	 * A message sent to the one inbox instead of whoever it was for.
	 *
	 * Nobody else is copied in: a Cc or a Bcc to a real customer is exactly
	 * what the redirect is for stopping.
	 *
	 * @param Message $message The message.
	 * @param string  $inbox   Where it goes instead.
	 *
	 * @return Message
	 */
	private static function redirected( Message $message, string $inbox ): Message {
		$original = implode( ', ', array_merge( $message->to, $message->cc, $message->bcc ) );

		return $message->with(
			[
				'to'      => [ $inbox ],
				'cc'      => [],
				'bcc'     => [],
				'subject' => '[' . $original . '] ' . $message->subject,
				'headers' => array_merge( $message->headers, [ 'X-Original-To' => $original ] ),
			]
		);
	}
}
