<?php
/**
 * Result
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Models;

use WP_Error;

/**
 * How handing an email over went.
 *
 * Never an exception: a bounce or a wrong API key is a normal outcome for
 * outbound mail, and a try/catch round every send is how one gets
 * swallowed. When it went, the message id the provider gave it is here --
 * the only thing that ties a later bounce back to it. When it did not, the
 * reason, and whether trying again could help: a provider that is down is
 * worth another go, an address it refused is not.
 */
final class Result {

	/**
	 * Construct.
	 *
	 * @param bool   $sent       Whether it was accepted.
	 * @param string $message_id The provider's id for it.
	 * @param string $error      Why not.
	 * @param int    $status     The HTTP status, or 0.
	 * @param bool   $retryable  Whether trying again could work.
	 * @param string $transport  The transport's name.
	 */
	public function __construct(
		public readonly bool $sent,
		public readonly string $message_id = '',
		public readonly string $error = '',
		public readonly int $status = 0,
		public readonly bool $retryable = false,
		public readonly string $transport = '',
	) {}

	/**
	 * It went.
	 *
	 * @param string $message_id The provider's id for it.
	 * @param int    $status     The HTTP status.
	 *
	 * @return self
	 */
	public static function ok( string $message_id = '', int $status = 0 ): self {
		return new self( true, $message_id, '', $status );
	}

	/**
	 * It did not.
	 *
	 * @param string $error     Why.
	 * @param int    $status    The HTTP status, or 0.
	 * @param bool   $retryable Whether trying again could work.
	 *
	 * @return self
	 */
	public static function failed( string $error, int $status = 0, bool $retryable = false ): self {
		return new self( false, '', $error, $status, $retryable );
	}

	/**
	 * The same, saying which transport it came from.
	 *
	 * @param string $name The transport's name.
	 *
	 * @return self
	 */
	public function from( string $name ): self {
		return new self( $this->sent, $this->message_id, $this->error, $this->status, $this->retryable, $name );
	}

	/**
	 * As the rest of WordPress takes it: true, or an error with the
	 * details in its data. The code is `send_failed`, as it always was.
	 *
	 * @return true|WP_Error
	 */
	public function to_wp(): true|WP_Error {
		if ( $this->sent ) {
			return true;
		}

		return new WP_Error(
			'send_failed',
			'' !== $this->error ? $this->error : __( 'WordPress could not send the email.', 'sugarcommerce' ),
			[
				'status'    => $this->status,
				'retryable' => $this->retryable,
				'transport' => $this->transport,
			]
		);
	}
}
