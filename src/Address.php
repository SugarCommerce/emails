<?php
/**
 * Address
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails;

use InvalidArgumentException;

/**
 * An email address, and the name that goes with it.
 *
 *     Address::parse( 'Jane Doe <jane@example.com>' );        // jane@example.com, Jane Doe
 *     Address::parse( '"Acme, Inc." <shop@example.com>' );    // shop@example.com, Acme, Inc.
 *     Address::list( '"Acme, Inc." <a@example.com>, b@example.com' ); // two addresses
 *     ( new Address( 'shop@example.com', 'Acme, Inc.' ) )->header();  // "Acme, Inc." <shop@example.com>
 *
 * Two things went wrong without it, both silently.
 *
 * **`Jane <jane@example.com>` is not an address.** WordPress's
 * sanitize_email() takes it as one and strips what an address cannot hold,
 * which leaves `Janejane@example.com`: delivered to a stranger or bounced,
 * and nobody told. parse() takes the address out of the angle brackets.
 *
 * **A name with a comma in it is two recipients.** `From: Acme, Inc.
 * <shop@example.com>` reads as `Acme` and `Inc. <shop@example.com>` to an
 * API that parses the header, and Postmark and Resend both refuse it.
 * header() quotes the name and escapes what the quotes need.
 *
 * Refused, not cleaned: a line break or another control character in the
 * address or the name (a header split in two is a header somebody else
 * wrote), and anything that is not an address.
 *
 * @since 1.0.0
 */
final readonly class Address {

	/**
	 * An address.
	 *
	 * @param string $email The address.
	 * @param string $name  The name that goes with it, or empty.
	 *
	 * @since 1.0.0
	 *
	 * @throws InvalidArgumentException When the address is not one, or
	 *                                  either part holds a line break or
	 *                                  another control character.
	 */
	public function __construct(
		public string $email,
		public string $name = '',
	) {
		// A tab is ordinary space in a name, and nowhere else.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $email ) || 1 === preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $name ) ) {
			throw new InvalidArgumentException( 'An email address and its name cannot hold a line break or another control character.' );
		}

		if ( ! self::valid( $email ) ) {
			throw new InvalidArgumentException( 'Not an email address.' );
		}
	}

	/**
	 * An address from what somebody wrote.
	 *
	 * `Name <address>`, `"Quoted, Name" <address>`, `<address>`, or the
	 * address on its own.
	 *
	 * @param string $value What was written.
	 *
	 * @return self
	 * @since 1.0.0
	 *
	 * @throws InvalidArgumentException When it holds no address.
	 */
	public static function parse( string $value ): self {
		$value = trim( $value );

		if ( 1 === preg_match( '/^(.*?)\s*<([^<>]+)>$/sD', $value, $parts ) ) {
			return new self( trim( $parts[2] ), self::unquote( trim( $parts[1] ) ) );
		}

		return new self( $value );
	}

	/**
	 * An address from what somebody wrote, or null when there is none.
	 *
	 * @param string $value What was written.
	 *
	 * @return self|null
	 * @since 1.0.0
	 */
	public static function try_parse( string $value ): ?self {
		try {
			return self::parse( $value );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * An address from whatever shape it came in.
	 *
	 * @param self|string|array{0?: string, 1?: string} $value An Address, a
	 *                                                       string for
	 *                                                       parse(), or
	 *                                                       `[ address, name ]`.
	 *
	 * @return self
	 * @since 1.0.0
	 *
	 * @throws InvalidArgumentException When it holds no address.
	 */
	public static function from( self|string|array $value ): self {
		return match ( true ) {
			$value instanceof self => $value,
			is_array( $value )     => new self( trim( (string) ( $value[0] ?? '' ) ), trim( (string) ( $value[1] ?? '' ) ) ),
			default                => self::parse( $value ),
		};
	}

	/**
	 * Every address in a list somebody typed, separated by commas.
	 *
	 * A comma inside quotes is part of a name, not the end of an address:
	 * `"Acme, Inc." <a@example.com>, b@example.com` is two. What is not an
	 * address is left out.
	 *
	 * @param string|array<int, mixed> $value A list, or several.
	 *
	 * @return list<self>
	 * @since 1.0.0
	 */
	public static function list( string|array $value ): array {
		$found = [];

		foreach ( (array) $value as $one ) {
			foreach ( self::split( (string) $one ) as $piece ) {
				$address = self::try_parse( $piece );

				if ( null !== $address ) {
					$found[] = $address;
				}
			}
		}

		return $found;
	}

	/**
	 * The address part of what somebody wrote, or '' when there is none.
	 *
	 * What `sanitize_email()` should have been given: the address out of the
	 * angle brackets, not the brackets and the name with it.
	 *
	 * @param string $value What was written.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function email_of( string $value ): string {
		return self::try_parse( $value )?->email ?? '';
	}

	/**
	 * As a header writes it: `"Name" <address>`, or the address alone.
	 *
	 * The name is always quoted, and a quote or a backslash in it escaped,
	 * so a comma, a full stop or a colon in a shop's name cannot split it.
	 * Letters beyond ASCII are left as they are, for the API or the mailer
	 * that writes the header to encode.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function header(): string {
		return self::format( $this->email, $this->name );
	}

	/**
	 * An address and a name as a header writes them, without checking
	 * either: for values already checked, as a Message's are.
	 *
	 * @param string $email The address.
	 * @param string $name  The name, or empty.
	 *
	 * @return string `"Name" <address>`, or the address alone.
	 * @since 1.0.0
	 */
	public static function format( string $email, string $name = '' ): string {
		if ( '' === $name ) {
			return $email;
		}

		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $name ) . '" <' . $email . '>';
	}

	/**
	 * As wp_mail() can read it: `Name <address>`.
	 *
	 * wp_mail() takes the name off its own way and hands it to PHPMailer,
	 * which quotes and encodes it. It cannot be given a quoted name -- it
	 * strips the quotes from a From name and escapes them into a Reply-To
	 * one -- and it splits a Reply-To, Cc or Bcc line at every comma, quoted
	 * or not. So the name goes in bare, without quotes, and without commas
	 * where a comma would split it.
	 *
	 * @param bool $splits Whether the header is one wp_mail() splits at
	 *                     commas: Reply-To, Cc, Bcc. Not From.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function for_wp_mail( bool $splits = true ): string {
		return self::format_for_wp_mail( $this->email, $this->name, $splits );
	}

	/**
	 * An address and a name as wp_mail() can read them, without checking
	 * either. See {@see self::for_wp_mail()}.
	 *
	 * @param string $email  The address.
	 * @param string $name   The name, or empty.
	 * @param bool   $splits Whether wp_mail() splits the header at commas.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function format_for_wp_mail( string $email, string $name = '', bool $splits = true ): string {
		$name = str_replace( '"', '', $name );
		$name = $splits ? str_replace( ',', ' ', $name ) : $name;
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );

		return '' === $name ? $email : $name . ' <' . $email . '>';
	}

	/**
	 * The domain the address is at.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function domain(): string {
		return substr( (string) strrchr( $this->email, '@' ), 1 );
	}

	/**
	 * The header form.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function __toString(): string {
		return $this->header();
	}

	/**
	 * Whether a string is an address.
	 *
	 * WordPress's own judgement when it is loaded, so an address the site
	 * takes elsewhere is taken here.
	 *
	 * @param string $email The string.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private static function valid( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}

		return function_exists( 'is_email' )
			? false !== is_email( $email )
			: false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * A name with its quotes taken off and what they escaped put back.
	 *
	 * @param string $name As written.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function unquote( string $name ): string {
		if ( strlen( $name ) >= 2 && str_starts_with( $name, '"' ) && str_ends_with( $name, '"' ) ) {
			return trim( (string) preg_replace( '/\\\\(.)/s', '$1', substr( $name, 1, -1 ) ) );
		}

		return trim( $name, " \t\"" );
	}

	/**
	 * A list split at the commas that are not inside quotes or brackets.
	 *
	 * @param string $value The list.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function split( string $value ): array {
		$pieces  = [];
		$current = '';
		$quoted  = false;
		$angled  = false;
		$length  = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];

			if ( $quoted && '\\' === $char && $i + 1 < $length ) {
				$current .= $char . $value[ ++$i ];
				continue;
			}

			if ( '"' === $char && ! $angled ) {
				$quoted = ! $quoted;
			} elseif ( '<' === $char && ! $quoted ) {
				$angled = true;
			} elseif ( '>' === $char && ! $quoted ) {
				$angled = false;
			} elseif ( ( ',' === $char || ';' === $char ) && ! $quoted && ! $angled ) {
				$pieces[] = $current;
				$current  = '';
				continue;
			}

			$current .= $char;
		}

		$pieces[] = $current;

		return array_values( array_filter( array_map( 'trim', $pieces ), 'strlen' ) );
	}
}
