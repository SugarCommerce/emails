<?php
/**
 * Abstract Component Base Class
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Abstracts;

use SugarCommerce\Emails\Interfaces\Component as ComponentInterface;

/**
 * AbstractComponent
 *
 * Base class for email components with common utilities.
 *
 * @since 1.0.0
 */
abstract class Component implements ComponentInterface {

	/**
	 * Convert hex color to rgba
	 *
	 * @param string $hex   Hex color code
	 * @param float  $alpha Alpha value (0-1)
	 *
	 * @return string RGBA color string
	 * @since 1.0.0
	 */
	protected static function hex_to_rgba( string $hex, float $alpha = 1 ): string {
		$hex = ltrim( $hex, '#' );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha );
	}
	/**
	 * What a tag's callback is giving when it returns a string rather than
	 * an array.
	 *
	 * Nothing, unless the component says otherwise. A component with an
	 * obvious single argument — the text of a button, the message of an
	 * alert — names it and a bare string goes there.
	 *
	 * Everything else takes a list, and there is no single value that could
	 * mean a table of order lines. Tag refuses a bare string for those and
	 * says so, rather than putting the string in the argument a list belongs
	 * in and leaving the component to iterate over a word — which rendered
	 * half a table with a PHP warning in the middle of the email, and sent
	 * it.
	 *
	 * @return string
	 */
	public static function primary_key(): string {
		return '';
	}
}
