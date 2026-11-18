<?php
/**
 * Postmark Transport
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
 * Through Postmark's API.
 *
 * Postmark is for transactional email only -- it closes an account that
 * sends marketing through it -- which is why receipts sent through it
 * arrive. Keep a newsletter on another stream.
 *
 * A message with no From address is sent from `from`, which must be a
 * sender signature confirmed on the Postmark account.
 */
final class Postmark implements Transport {

	use Api;

	/**
	 * The endpoint.
	 *
	 * @var string
	 */
	private const ENDPOINT = 'https://api.postmarkapp.com/email';

	/**
	 * Construct.
	 *
	 * @param string $token   The server's API token.
	 * @param string $from    The address to send from when a message names none.
	 * @param string $stream  The message stream. Transactional mail kept out
	 *                        of a broadcast stream keeps its reputation.
	 * @param int    $timeout Seconds.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $token,
		private string $from = '',
		private string $stream = 'outbound',
		private int $timeout = 15,
	) {}

	/**
	 * What var_dump() shows: the settings, never the token.
	 *
	 * @return array<string, string|int>
	 */
	public function __debugInfo(): array {
		return [
			'token'   => '[redacted]',
			'from'    => $this->from,
			'stream'  => $this->stream,
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
			'From'          => '' !== $message->from_email ? Message::address( $message->from_email, $message->from_name ) : $this->from,
			'To'            => implode( ', ', $message->to ),
			'Subject'       => $message->subject,
			'HtmlBody'      => $message->html,
			'TextBody'      => $message->text,
			'MessageStream' => $this->stream,
		];

		if ( [] !== $message->cc ) {
			$payload['Cc'] = implode( ', ', $message->cc );
		}

		if ( [] !== $message->bcc ) {
			$payload['Bcc'] = implode( ', ', $message->bcc );
		}

		if ( '' !== $message->reply_to ) {
			$payload['ReplyTo'] = Message::address( $message->reply_to, $message->reply_to_name );
		}

		foreach ( $message->headers as $name => $value ) {
			$payload['Headers'][] = [
				'Name'  => $name,
				'Value' => $value,
			];
		}

		foreach ( self::files( $message->attachments ) as $file ) {
			$payload['Attachments'][] = [
				'Name'        => $file['name'],
				'Content'     => $file['content'],
				'ContentType' => $file['type'],
			];
		}

		return $this->post( self::ENDPOINT, $payload, [ 'X-Postmark-Server-Token' => $this->token ], $this->timeout, 'MessageID', 'Message' );
	}

	/**
	 * Its name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'postmark';
	}
}
