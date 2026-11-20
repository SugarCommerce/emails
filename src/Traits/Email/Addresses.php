<?php
/**
 * Addresses: who it goes to, who it is from, and who is copied in.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Email;

use SugarCommerce\Emails\Address;

/**
 * Addresses: who it goes to, who it is from, and who is copied in.
 *
 * An address is read as written; one that is not an address is kept aside
 * and reported by problems() rather than sent to.
 *
 * @since 1.0.0
 */
trait Addresses {

	/**
	 * Who it goes to.
	 *
	 * `jane@example.com` or `Jane Doe <jane@example.com>`: the address is
	 * taken out of the brackets. Handed whole to sanitize_email(), that
	 * second one became `Janejane@example.com` -- a stranger's inbox, or a
	 * bounce, and nobody told.
	 *
	 * @param string|string[] $to One address or several.
	 *
	 * @return self
	 */
	public function to( string|array $to ): self {
		foreach ( (array) $to as $address ) {
			$address = self::email_of( (string) $address );

			if ( '' !== $address ) {
				$this->to[] = $address;
			}
		}

		return $this;
	}

	/**
	 * Who it comes from.
	 *
	 * @param string $email The address, or `Name <address>`.
	 * @param string $name  The name. Empty to keep the one written with
	 *                      the address, if any.
	 *
	 * @return self
	 */
	public function from( string $email, string $name = '' ): self {
		$this->from = self::named( $email, $name ) ?? $this->from;

		return $this;
	}

	/**
	 * Where a reply goes.
	 *
	 * @param string $email The address, or `Name <address>`.
	 * @param string $name  The name. Empty to keep the one written with
	 *                      the address, if any.
	 *
	 * @return self
	 */
	public function reply_to( string $email, string $name = '' ): self {
		$this->reply = self::named( $email, $name ) ?? $this->reply;

		return $this;
	}

	/**
	 * Who else gets it.
	 *
	 * @param string $email The address, or `Name <address>`.
	 *
	 * @return self
	 */
	public function cc( string $email ): self {
		$email = self::email_of( $email );

		if ( '' !== $email ) {
			$this->cc[] = $email;
		}

		return $this;
	}

	/**
	 * Who else gets it, quietly.
	 *
	 * @param string $email The address, or `Name <address>`.
	 *
	 * @return self
	 */
	public function bcc( string $email ): self {
		$email = self::email_of( $email );

		if ( '' !== $email ) {
			$this->bcc[] = $email;
		}

		return $this;
	}

	/**
	 * An address and its name, from what was given.
	 *
	 * @param string $email The address, or `Name <address>`.
	 * @param string $name  The name, which wins over one written with the
	 *                      address.
	 *
	 * @return array{0: string, 1: string}|null Null when there is no address.
	 */
	private static function named( string $email, string $name ): ?array {
		$address = Address::try_parse( $email );
		$email   = sanitize_email( null === $address ? $email : $address->email );

		if ( '' === $email ) {
			return null;
		}

		return [ $email, self::one_line( '' !== trim( $name ) || null === $address ? $name : $address->name ) ];
	}

	/**
	 * The address part of what was given, made safe, or ''.
	 *
	 * @param string $value An address, or `Name <address>`.
	 *
	 * @return string
	 */
	private static function email_of( string $value ): string {
		$address = Address::try_parse( $value );

		return sanitize_email( null === $address ? $value : $address->email );
	}
}
