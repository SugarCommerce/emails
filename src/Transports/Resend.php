<?php
/**
 * Resend Transport
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

/**
 * Through Resend's API.
 *
 * A message with no From address is sent from `from`, which must be on a
 * domain verified on the Resend account.
 */
final class Resend implements Transport {

	use Api;

	/**
	 * The endpoint.
	 *
	 * @var string
	 */
	private const ENDPOINT = 'https://api.resend.com/emails';

	/**
	 * Construct.
	 *
	 * @param string $api_key The API key.
	 * @param string $from    The address to send from when a message names none.
	 * @param int    $timeout Seconds.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $api_key,
		private string $from = '',
		private int $timeout = 15,
	) {}

	/**
	 * What var_dump() shows: the settings, never the key.
	 *
	 * @return array<string, string|int>
	 */
	public function __debugInfo(): array {
		return [
			'api_key' => '[redacted]',
			'from'    => $this->from,
			'timeout' => $this->timeout,
		];
	}

	/**
	 * Send it.
	 *
	 * @param Message $message The finished email.
	 *
	 * @return Result
	 */
	public function send( Message $message ): Result {
		$payload = [
			'from'    => '' !== $message->from_email ? Message::address( $message->from_email, $message->from_name ) : $this->from,
			'to'      => $message->to,
			'subject' => $message->subject,
			'html'    => $message->html,
			'text'    => $message->text,
		];

		if ( [] !== $message->cc ) {
			$payload['cc'] = $message->cc;
		}

		if ( [] !== $message->bcc ) {
			$payload['bcc'] = $message->bcc;
		}

		if ( '' !== $message->reply_to ) {
			$payload['reply_to'] = $message->reply_to;
		}

		if ( [] !== $message->headers ) {
			$payload['headers'] = $message->headers;
		}

		foreach ( self::files( $message->attachments ) as $file ) {
			$payload['attachments'][] = [
				'filename' => $file['name'],
				'content'  => $file['content'],
			];
		}

		return $this->post( self::ENDPOINT, $payload, [ 'Authorization' => 'Bearer ' . $this->api_key ], $this->timeout, 'id' );
	}

	/**
	 * Its name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'resend';
	}
}
