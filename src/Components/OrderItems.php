<?php
/**
 * Order Items Component
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Components;

use SugarCommerce\Emails\Abstracts\Component;
use SugarCommerce\Money\Money;

/**
 * What was bought, as a table with the sums under it.
 *
 * An amount is either an integer in the currency's smallest unit -- cents,
 * pence -- or a string that is already written out. An integer is written
 * out by `format` when one is given, by sugarcommerce/money when it is
 * installed, and as the currency code and two decimals when neither is: a
 * table of order lines should not need an opinion about currency, and a
 * plugin that has already formatted its amounts should not have them
 * formatted again.
 *
 * Every word in it can be replaced through `labels`, so a receipt in German
 * has no English left in its table.
 */
class OrderItems extends Component {

	/**
	 * Draw it.
	 *
	 * @param array<string, mixed> $args {
	 *     @type array<int, array<string, mixed>> $items    Lines: `name`, `quantity`, `price` (each) and
	 *                                                      optionally `total` (the line).
	 *     @type int|string                       $subtotal Worked out from the lines when left out and
	 *                                                      they are integers.
	 *     @type int|string                       $discount Taken off. Left out, or 0, for none.
	 *     @type int|string                       $shipping Added on.
	 *     @type int|string                       $tax      Added on.
	 *     @type int|string                       $total    Worked out when left out and the rest are
	 *                                                      integers.
	 *     @type string                           $currency A currency code, for an integer amount.
	 *     @type callable                         $format   Given an integer amount and the currency,
	 *                                                      returns it written out.
	 *     @type array<string, string>            $labels   `item`, `quantity`, `price`, `total`,
	 *                                                      `subtotal`, `discount`, `shipping`, `tax`.
	 * }
	 *
	 * @return string
	 */
	public static function render( array $args = [] ): string {
		$items = $args['items'] ?? [];

		if ( ! is_array( $items ) || [] === $items ) {
			return '';
		}

		$currency = (string) ( $args['currency'] ?? 'USD' );
		$format   = $args['format'] ?? null;
		$label    = array_merge( self::labels(), array_filter( (array) ( $args['labels'] ?? [] ), 'is_string' ) );
		$money    = static fn( mixed $amount ): string => esc_html( self::amount( $amount, $currency, $format ) );

		$cell = 'padding: 12px 8px; text-align: %s; font-weight: 600; color: #374151;';
		$html = '<table class="component component-order-items" role="presentation" style="width: 100%; border-collapse: collapse; margin: 24px 0; font-size: 14px;">';

		$html .= sprintf(
			'<thead class="order-header"><tr style="border-bottom: 2px solid #e5e7eb;"><th class="order-col-item" style="%1$s">%2$s</th><th class="order-col-qty" style="%3$s">%4$s</th><th class="order-col-price" style="%5$s">%6$s</th><th class="order-col-total" style="%5$s">%7$s</th></tr></thead><tbody class="order-items">',
			sprintf( $cell, 'left' ),
			esc_html( $label['item'] ),
			sprintf( $cell, 'center' ),
			esc_html( $label['quantity'] ),
			sprintf( $cell, 'right' ),
			esc_html( $label['price'] ),
			esc_html( $label['total'] )
		);

		// Summed only while every amount is a number: a sum of strings
		// somebody already wrote out is not one.
		$sum = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$quantity = max( 0, (int) ( $item['quantity'] ?? 1 ) );
			$price    = $item['price'] ?? 0;
			$line     = $item['total'] ?? ( is_int( $price ) ? $price * $quantity : $price );

			$sum = null !== $sum && is_int( $line ) ? $sum + $line : null;

			$html .= sprintf(
				'<tr class="order-item-row" style="border-bottom: 1px solid #f3f4f6;"><td class="order-item-name" style="padding: 12px 8px; color: #111827;">%1$s</td><td class="order-item-qty" style="padding: 12px 8px; text-align: center; color: #6b7280;">%2$d</td><td class="order-item-price" style="padding: 12px 8px; text-align: right; color: #6b7280;">%3$s</td><td class="order-item-total" style="padding: 12px 8px; text-align: right; color: #111827; font-weight: 500;">%4$s</td></tr>',
				esc_html( (string) ( $item['name'] ?? '' ) ),
				$quantity,
				$money( $price ),
				$money( $line )
			);
		}

		$html .= '</tbody><tfoot class="order-summary">';

		$subtotal = $args['subtotal'] ?? $sum;
		$running  = is_int( $subtotal ) ? $subtotal : null;

		if ( null !== $subtotal ) {
			$html .= self::row( 'subtotal', $label['subtotal'], $money( $subtotal ) );
		}

		foreach ( [
			'discount' => -1,
			'shipping' => 1,
			'tax' => 1,
		] as $part => $sign ) {
			$value = $args[ $part ] ?? null;

			if ( null === $value || 0 === $value || '' === $value ) {
				continue;
			}

			$running = null !== $running && is_int( $value ) ? $running + $sign * $value : null;
			$html   .= self::row( $part, $label[ $part ], ( $sign < 0 ? '-' : '' ) . $money( $value ) );
		}

		$total = $args['total'] ?? $running;

		if ( null !== $total ) {
			$html .= sprintf(
				'<tr class="order-total-row" style="border-top: 2px solid #e5e7eb;"><td colspan="3" class="order-total-label" style="padding: 12px 8px 8px; text-align: right; font-weight: 700; color: #111827; font-size: 16px;">%1$s</td><td class="order-total-value" style="padding: 12px 8px 8px; text-align: right; font-weight: 700; color: #111827; font-size: 16px;">%2$s</td></tr>',
				esc_html( $label['total'] ),
				$money( $total )
			);
		}

		return $html . '</tfoot></table>';
	}

	/**
	 * An amount, written out.
	 *
	 * @param mixed $amount   An integer in the smallest unit, or a string
	 *                        already written out.
	 * @param string $currency The currency code.
	 * @param mixed  $format   The plugin's own formatter, if it has one.
	 *
	 * @return string
	 */
	public static function amount( mixed $amount, string $currency, mixed $format = null ): string {
		if ( ! is_int( $amount ) ) {
			return is_scalar( $amount ) ? (string) $amount : '';
		}

		if ( is_callable( $format ) ) {
			return (string) call_user_func( $format, $amount, $currency );
		}

		if ( class_exists( Money::class ) ) {
			return Money::format( $amount, [ 'currency' => $currency ] );
		}

		return strtoupper( $currency ) . ' ' . number_format( $amount / 100, 2 );
	}

	/**
	 * One line of the sums under the table.
	 *
	 * Classed as the old table was -- `order-subtotal-row`, `order-label`,
	 * `order-value` -- because the dark design and the soft one colour it by
	 * those names.
	 *
	 * @param string $part  Which: `subtotal`, `discount`, `shipping`, `tax`.
	 * @param string $label What it is called.
	 * @param string $value The amount, already escaped.
	 *
	 * @return string
	 */
	private static function row( string $part, string $label, string $value ): string {
		return sprintf(
			'<tr class="order-%1$s-row"><td colspan="3" class="order-label" style="padding: 4px 8px; text-align: right; color: #6b7280; font-size: 13px;">%2$s</td><td class="order-value%3$s" style="padding: 4px 8px; text-align: right; color: %4$s;">%5$s</td></tr>',
			esc_attr( $part ),
			esc_html( $label ),
			'discount' === $part ? ' order-discount' : '',
			'discount' === $part ? '#059669' : '#374151',
			$value
		);
	}

	/**
	 * The words in the table.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'item'     => __( 'Item', 'sugarcommerce' ),
			'quantity' => __( 'Qty', 'sugarcommerce' ),
			'price'    => __( 'Price', 'sugarcommerce' ),
			'total'    => __( 'Total', 'sugarcommerce' ),
			'subtotal' => __( 'Subtotal', 'sugarcommerce' ),
			'discount' => __( 'Discount', 'sugarcommerce' ),
			'shipping' => __( 'Shipping', 'sugarcommerce' ),
			'tax'      => __( 'Tax', 'sugarcommerce' ),
		];
	}
}
