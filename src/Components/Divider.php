<?php
/**
 * Divider Component
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Components;

use SugarCommerce\Emails\Abstracts\Component;

/**
 * Divider Component
 *
 * Simple horizontal rule for content separation.
 *
 * @since 1.0.0
 */
class Divider extends Component {

	/**
	 * Render divider component
	 *
	 * @param array $args Not used, kept for consistency
	 *
	 * @return string Divider HTML
	 * @since 1.0.0
	 */
	public static function render( array $args = [] ): string {
		return '<hr class="component component-divider" style="border: 0; border-top: 1px solid #e5e7eb; margin: 32px 0;">';
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
		return 'style';
	}
}
