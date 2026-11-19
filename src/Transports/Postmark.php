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
 *
 * A message's metadata goes as Postmark's `Metadata` and its tag as `Tag`,
 * and Postmark sends both back with a bounce, a spam complaint or a
 * delivery webhook -- which is how one is tied back to an order. Postmark
 * keeps ten fields, a name of up to twenty characters and a value of up to
 * eighty; anything longer is cut there rather than refused.
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
	 * The most metadata fields Postmark keeps.
	 *
	 * @var int
	 */
	private const METADATA_FIELDS = 10;

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
			'From'          => '' !== $message->from_email ? Message::address( $message->from_email, $message->from_name ) : self::sender( $this->from ),
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

		if ( '' !== $message->tag ) {
			$payload['Tag'] = mb_substr( $message->tag, 0, 1000 );
		}

		foreach ( array_slice( $message->metadata, 0, self::METADATA_FIELDS, true ) as $key => $value ) {
			$payload['Metadata'][ substr( (string) $key, 0, 20 ) ] = mb_substr( (string) $value, 0, 80 );
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
