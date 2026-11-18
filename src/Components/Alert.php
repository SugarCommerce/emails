<?php
/**
 * Alert Component
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Components;

use SugarCommerce\Emails\Abstracts\Component;
use SugarCommerce\Text\Display\Color;

/**
 * Alert Component
 *
 * Fixed-style alert boxes with semantic types.
 *
 * @since 1.0.0
 */
class Alert extends Component {

	/**
	 * Alert colors by type
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private const COLORS = [
		'info'    => '#3b82f6',
		'success' => '#10b981',
		'warning' => '#f59e0b',
		'error'   => '#ef4444',
	];

	/**
	 * Render alert component
	 *
	 * @param array $args    {
	 *
	 * @type string $message Alert message (required)
	 * @type string $type    Type (info|success|warning|error)
	 * @type string $title   A few words in bold before the message, such as "Test mode."
	 *                       }
	 *
	 * @return string Alert HTML
	 * @since 1.0.0
	 */
	public static function render( array $args = [] ): string {
		$message = $args['message'] ?? '';
		$type    = $args['type'] ?? 'info';

		if ( empty( $message ) ) {
			return '';
		}

		$color    = self::COLORS[ $type ] ?? self::COLORS['info'];
		$bg_color = Color::rgba( $color, 0.1 );
		$title    = trim( (string) ( $args['title'] ?? '' ) );

		// A title leads the message, in bold: "Test mode. Nothing was charged."
		if ( '' !== $title ) {
			$message = '<strong>' . esc_html( $title ) . '</strong> ' . $message;
		}

		return sprintf(
			'<div class="component component-alert alert-%s" style="background: %s; border-left: 4px solid %s; color: #1f2937; padding: 16px; margin: 24px 0; border-radius: 4px;">%s</div>',
			esc_attr( $type ),
			$bg_color,
			$color,
			wp_kses_post( $message )
		);
	}


	/**
	 * What a tag's callback is understood to be giving when it returns a
	 * string rather than an array.
	 *
	 * Declared here rather than in a table somewhere else: this class is the
	 * only thing that knows, and a table of these elsewhere is one more place
	 * to add a component and forget it.
	 *
	 * @return string
	 */
	public static function primary_key(): string {
		return 'message';
	}
}
