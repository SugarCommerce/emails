<?php
/**
 * Logo Component
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Parts;

use SugarCommerce\Emails\Abstracts\Component;

/**
 * Logo Component
 *
 * Simple logo display with fixed sizing.
 *
 * @since 1.0.0
 */
class Logo extends Component {

	/**
	 * Render logo component
	 *
	 * @param array $args  {
	 *
	 * @type string $url   Logo URL (required)
	 * @type string $alt   Alt text
	 * @type string $align Alignment (left|center|right)
	 * @type int    $height The tallest it may be drawn, in pixels. Left out, only its width is held.
	 *                     }
	 *
	 * @return string Logo HTML
	 * @since 1.0.0
	 */
	public static function render( array $args = [] ): string {
		$url   = $args['url'] ?? '';
		$alt   = $args['alt'] ?? get_bloginfo( 'name' );
		$align = $args['align'] ?? 'left';

		if ( empty( $url ) ) {
			return '';
		}

		// A wide logo is held by its width and a tall one by its height;
		// `height: auto` keeps either in proportion. Without a height a
		// square logo was two hundred pixels of header.
		$height = isset( $args['height'] ) ? max( 0, (int) $args['height'] ) : 0;

		$img = sprintf(
			'<img src="%s" alt="%s" style="max-width: 200px; width: auto;%s">',
			esc_url( $url ),
			esc_attr( $alt ),
			$height > 0 ? sprintf( ' max-height: %dpx; height: auto;', $height ) : ''
		);

		if ( $align === 'center' || $align === 'right' ) {
			$img = sprintf( '<div style="text-align: %s;">%s</div>', esc_attr( $align ), $img );
		}

		return $img;
	}
}
