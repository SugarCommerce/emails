<?php
/**
 * Component tests.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Emails\Components\Alert;
use SugarCommerce\Emails\Components\OrderItems;
use SugarCommerce\Emails\Render\Components;

/**
 * The blocks an email is built from, where they changed on the move.
 */
final class ComponentsTest extends TestCase {

	/**
	 * Reset the stubbed globals and the registries.
	 */
	protected function setUp(): void {
		re_reset_globals();
	}

	public function test_order_items_sums_whole_amounts_and_writes_them_out(): void {
		$html = OrderItems::render(
			[
				'items'    => [
					[ 'name' => 'Pro <b>licence</b>', 'quantity' => 2, 'price' => 1500 ],
					[ 'name' => 'Pack', 'price' => 499 ],
				],
				'discount' => 500,
				'tax'      => 100,
				'currency' => 'eur',
				'format'   => static fn( int $amount, string $currency ): string => $currency . ':' . $amount,
			]
		);

		$this->assertStringContainsString( 'Pro &lt;b&gt;licence&lt;/b&gt;', $html );

		// The names the dark and soft designs colour the table by.
		foreach ( [ 'order-header', 'order-item-row', 'order-item-name', 'order-tax-row', 'order-label', 'order-value order-discount', 'order-total-row', 'order-total-value' ] as $class ) {
			$this->assertStringContainsString( 'class="' . $class . '"', $html );
		}
		$this->assertStringContainsString( '>eur:3000<', $html );
		$this->assertStringContainsString( '>eur:3499<', $html );
		$this->assertStringContainsString( '>-eur:500<', $html );

		// 3499 - 500 + 100.
		$this->assertMatchesRegularExpression( '/Total<\/td><td[^>]*>eur:3099</', $html );
	}

	public function test_order_items_takes_amounts_already_written_out_and_does_not_sum_them(): void {
		$html = OrderItems::render(
			[
				'items' => [ [ 'name' => 'Pack', 'price' => '€4.99', 'total' => '€4.99' ] ],
				'total' => '€4.99',
			]
		);

		$this->assertStringContainsString( '€4.99', $html );
		$this->assertStringNotContainsString( 'Subtotal', $html );
		$this->assertSame( 1, substr_count( $html, '>Total</td>' ) );
	}

	public function test_order_items_words_can_be_replaced(): void {
		$html = OrderItems::render(
			[
				'items'  => [ [ 'name' => 'Paket', 'price' => 100 ] ],
				'labels' => [ 'item' => 'Artikel', 'total' => 'Gesamt', 'subtotal' => 'Zwischensumme' ],
			]
		);

		$this->assertStringContainsString( '>Artikel</th>', $html );
		$this->assertStringContainsString( '>Zwischensumme</td>', $html );
		$this->assertStringContainsString( '>Gesamt</td>', $html );
		$this->assertStringNotContainsString( '>Item<', $html );
	}

	public function test_an_amount_with_no_formatter_is_the_code_and_two_decimals(): void {
		$this->assertSame( 'USD 12.50', OrderItems::amount( 1250, 'usd' ) );
		$this->assertSame( 'Free', OrderItems::amount( 'Free', 'usd' ) );
		$this->assertSame( '', OrderItems::amount( [ 1 ], 'usd' ) );
		$this->assertSame( '', OrderItems::render( [ 'items' => [] ] ) );
	}

	public function test_an_alert_can_lead_with_a_title(): void {
		$html = Components::render( 'alert', [ 'title' => 'Test <mode>.', 'message' => 'Nothing was charged.', 'type' => 'warning' ] );

		$this->assertStringContainsString( '<strong>Test &lt;mode&gt;.</strong> Nothing was charged.', $html );
		$this->assertStringNotContainsString( '<strong>', Alert::render( [ 'message' => 'Plain.' ] ) );
	}
}
