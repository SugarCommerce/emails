<?php
/**
 * Addresses: who a registered email is from and to, and its headers.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Sender;

use SugarCommerce\Emails\Address;
use SugarCommerce\Emails\Email;
use SugarCommerce\Emails\Mailer;

/**
 * Addresses: who a registered email is from and to, and its headers.
 *
 * The From is the site's own address unless the plugin says otherwise; a
 * recipient is read as written, and the site's address stands in when an
 * admin email names none.
 *
 * @since 1.0.0
 */
trait Addresses {

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
			$headers[] = 'From: ' . Address::format_for_wp_mail( $sender['address'], $sender['name'], false );
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
		$address = self::email_of( (string) $store->get( $store->key( 'from_address' ), '' ) );
		$reply   = self::email_of( (string) $store->get( $store->key( 'reply_to' ), '' ) );

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
			'address'  => self::email_of( (string) ( $filtered['address'] ?? $sender['address'] ) ),
			'reply_to' => self::email_of( (string) ( $filtered['reply_to'] ?? '' ) ),
		];
	}

	/**
	 * The address in a setting, made safe: `Name <address>` gives the
	 * address, where sanitize_email() alone made `Nameaddress`.
	 *
	 * @param string $value What was saved.
	 *
	 * @return string The address, or ''.
	 */
	private static function email_of( string $value ): string {
		return sanitize_email( Address::try_parse( $value )?->email ?? $value );
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
}
