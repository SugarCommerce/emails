<?php
/**
 * Color
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Utils;

/**
 * The little a template needs to know about a brand colour.
 *
 * A template is handed one colour and draws a header in it, a link in it
 * and words on top of it. Two of those go wrong with a pale colour: white
 * words on a yellow header cannot be read, and neither can a yellow link on
 * a white page. So the colour a site chose is where the other two start,
 * and this works out what they have to be.
 */
final class Color {

	/**
	 * A six-digit hex colour, or the fallback.
	 *
	 * Strict, because the answer goes into a `style` attribute as it is: a
	 * colour is `#` and six hex digits (three are widened) or it is not one.
	 *
	 * @param mixed  $value    What was given.
	 * @param string $fallback Used when that is not a colour.
	 *
	 * @return string Lower case, with its `#`.
	 */
	public static function hex( mixed $value, string $fallback = '#2271b1' ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		if ( 1 === preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $short ) ) {
			return '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
		}

		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}

	/**
	 * How bright a colour looks, from 0 (black) to 255 (white).
	 *
	 * Weighted the way an eye is: green counts for most and blue for least,
	 * which is why a pure yellow is bright and a pure blue is not.
	 *
	 * @param string $hex A colour.
	 *
	 * @return float
	 */
	public static function brightness( string $hex ): float {
		$hex = self::hex( $hex, '#000000' );

		return (
			hexdec( substr( $hex, 1, 2 ) ) * 299 +
			hexdec( substr( $hex, 3, 2 ) ) * 587 +
			hexdec( substr( $hex, 5, 2 ) ) * 114
		) / 1000;
	}

	/**
	 * Whether white words on this colour could not be read.
	 *
	 * @param string $hex A colour.
	 *
	 * @return bool
	 */
	public static function is_light( string $hex ): bool {
		return self::brightness( $hex ) > 150;
	}

	/**
	 * The colour of words drawn on top of this one: near-black on a light
	 * colour, white on a dark one.
	 *
	 * @param string $hex The colour underneath.
	 *
	 * @return string
	 */
	public static function readable_on( string $hex ): string {
		return self::is_light( $hex ) ? '#111111' : '#ffffff';
	}

	/**
	 * The colour of a link on a white page: the brand colour where that
	 * can be read there, and near-black, which a link's underline still
	 * marks as one, where it cannot.
	 *
	 * @param string $hex The brand colour.
	 *
	 * @return string
	 */
	public static function link( string $hex ): string {
		return self::is_light( $hex ) ? '#1d2327' : self::hex( $hex );
	}
}
