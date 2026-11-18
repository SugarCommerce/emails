<?php
/**
 * Memory Transport
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
 * Keeps every message and sends none.
 *
 * For a test suite, and the reason is not convenience: a suite wired to a
 * real provider emails a real customer sooner or later, usually from a
 * fixture that looked made up. Assert against what was kept instead.
 */
final class Memory implements Transport {

	/**
	 * What was handed over, oldest first.
	 *
	 * @var Message[]
	 */
	private array $sent = [];

	/**
	 * Construct.
	 *
	 * @param bool   $succeed Whether a send reports success. False to try a
	 *                        plugin's handling of a failure.
	 * @param string $error   Why it failed, when it does.
	 */
	public function __construct( private bool $succeed = true, private string $error = 'Simulated failure' ) {}

	/**
	 * Keep a message.
	 *
	 * @param Message $message The finished email.
	 *
	 * @return Result
	 */
	public function send( Message $message ): Result {
		if ( ! $this->succeed ) {
			return Result::failed( $this->error, 500, true );
		}

		$this->sent[] = $message;

		return Result::ok( 'memory-' . count( $this->sent ) );
	}

	/**
	 * Its name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'memory';
	}

	/**
	 * Everything kept.
	 *
	 * @return Message[]
	 */
	public function messages(): array {
		return $this->sent;
	}

	/**
	 * The newest, or null.
	 *
	 * @return Message|null
	 */
	public function last(): ?Message {
		return [] === $this->sent ? null : $this->sent[ count( $this->sent ) - 1 ];
	}

	/**
	 * Whether anything went to an address.
	 *
	 * @param string $email The address.
	 *
	 * @return bool
	 */
	public function sent_to( string $email ): bool {
		foreach ( $this->sent as $message ) {
			foreach ( $message->to as $to ) {
				if ( 0 === strcasecmp( $to, $email ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Forget everything.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->sent = [];
	}
}
