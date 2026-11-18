<?php
/**
 * Stored keys and hook names, for a plugin moving onto the mailer.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Tests;

use SugarCommerce\Emails\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * A plugin that wrote its own editable emails before this library had them
 * keeps every setting a site saved, under the name it was saved by.
 *
 * The configuration below is a shop plugin's as it stands -- settings in one
 * `{plugin}_settings` option, each email under `email_{name}`, the look under
 * `email_template`, `email_brand_color` and the rest, the reply-to and the
 * shop's own address under `business_email` -- and every test reads or writes
 * through the mailer and checks the raw option, never the mailer's idea of it.
 * A key that moves by one character fails here, not on a live shop that has
 * lost its wording.
 */
final class MailerKeysTest extends TestCase {

	/**
	 * The option the shop keeps its settings in.
	 */
	private const OPTION = 'sugarcart_settings';

	/**
	 * Reset the stubbed globals and the registries.
	 */
	protected function setUp(): void {
		re_reset_globals();
	}

	/**
	 * The shop plugin's configuration, as it would hand it over.
	 *
	 * @return Mailer
	 */
	private function mailer(): Mailer {
		return Mailer::register(
			'sugarcart',
			[
				'option_name'     => self::OPTION,
				'key_prefix'      => 'email_',
				'keys'            => [
					'reply_to'      => 'business_email',
					'admin_email'   => 'business_email',
					'business_name' => 'business_name',
				],
				'default_context' => 'order',
				'contexts'        => [
					'order'   => [
						'group' => 'sugarcart',
						'load'  => static fn( $id ) => (object) [ 'id' => $id, 'email' => 'buyer@example.test', 'number' => 'SC-' . $id ],
					],
					'license' => [
						'group' => 'sugarcart_license',
						'load'  => static fn( $id ) => (object) [ 'id' => $id, 'customer_id' => 3 ],
					],
					'summary' => [
						'group' => 'sugarcart_summary',
						'load'  => static fn() => [ 'revenue' => '£10.00' ],
					],
				],
				'emails'          => static function ( Mailer $mailer ): void {
					$mailer->register_email( 'receipt', [ 'subject' => 'Your order {order_number}', 'body' => 'Thanks.' ] );
					$mailer->register_email( 'admin_sale', [ 'subject' => 'New order', 'body' => 'A sale.', 'recipient' => 'admin' ] );
					$mailer->register_email( 'license_expiring', [ 'subject' => 'Expiring', 'body' => 'Soon.', 'context' => 'license' ] );
					$mailer->register_email(
						'sales_summary',
						[
							'subject'   => 'Summary',
							'body'      => 'Figures.',
							'context'   => 'summary',
							'recipient' => static fn(): array => [ 'owner@example.test' ],
							'panel'     => static fn( string $id, array $email ): array => [ $email['setting'] => [ 'type' => 'email_editor' ] ],
						]
					);
				},
				'tags'            => static function ( Mailer $mailer ): void {
					$mailer->register_tag( 'order_number', [ 'callback' => static fn( $order ): string => (string) $order->number ] );
				},
			]
		);
	}

	/**
	 * Seed the option the way a shop that has used its settings has it.
	 *
	 * @param array<string, mixed> $settings What it holds.
	 *
	 * @return void
	 */
	private function saved( array $settings ): void {
		$GLOBALS['re_options'][ self::OPTION ] = $settings;
	}

	public function test_every_key_is_the_one_the_shop_already_uses(): void {
		$mailer = $this->mailer();

		$mailer->ready();

		$this->assertSame(
			[
				'parts'  => [
					'template'       => 'email_template',
					'color'          => 'email_brand_color',
					'logo'           => 'email_logo',
					'logo_height'    => 'email_logo_height',
					'align'          => 'email_header_align',
					'footer'         => 'email_footer',
					'footer_enabled' => 'email_footer_enabled',
					'from_name'      => 'email_from_name',
					'from_address'   => 'email_from_address',
					'look_preview'   => 'email_look_preview',
					'reply_to'       => 'business_email',
					'admin_email'    => 'business_email',
					'business_name'  => 'business_name',
				],
				'emails' => [
					'receipt'          => 'email_receipt',
					'admin_sale'       => 'email_admin_sale',
					'license_expiring' => 'email_license_expiring',
					'sales_summary'    => 'email_sales_summary',
				],
			],
			$mailer->store()->keys()
		);
	}

	public function test_saved_wording_and_switches_are_read_from_the_shops_keys(): void {
		$this->saved(
			[
				'email_receipt'    => [
					'enabled' => 0,
					'subject' => 'Saved subject',
					'heading' => 'Saved heading',
					'body'    => 'Saved body',
				],
				'email_admin_sale' => [ 'recipient' => 'one@example.test, two@example.test' ],
			]
		);

		$mailer = $this->mailer();

		$this->assertFalse( $mailer->sender()->enabled( 'receipt' ) );
		$this->assertTrue( $mailer->sender()->enabled( 'admin_sale' ) );
		$this->assertSame( [ 'subject' => 'Saved subject', 'body' => "<p>Saved body</p>\n" ], $mailer->sender()->wording( 'receipt' ) );
		$this->assertSame( 'Saved heading', $mailer->sender()->stored( 'receipt' )['context']['title'] );
		$this->assertSame( [ 'one@example.test', 'two@example.test' ], $mailer->sender()->recipient( 'admin_sale', (object) [] ) );
	}

	public function test_the_look_is_read_from_the_shops_keys(): void {
		$this->saved(
			[
				'email_template'       => 'soft',
				'email_brand_color'    => '#AA3300',
				'email_logo'           => '7',
				'email_logo_height'    => 'large',
				'email_header_align'   => 'left',
				'email_footer'         => 'Line one',
				'email_footer_enabled' => '1',
				'business_name'        => 'Sugar Shop',
			]
		);

		$mailer = $this->mailer();

		$this->assertSame(
			[
				'template'       => 'soft',
				'color'          => '#aa3300',
				'logo'           => 7,
				'logo_height'    => 'large',
				'align'          => 'left',
				'footer'         => 'Line one',
				'footer_enabled' => true,
			],
			$mailer->look()->chosen()
		);

		$this->assertStringContainsString( 'alt="Sugar Shop"', $mailer->look()->settings()['context']['logo'] );
		$this->assertStringContainsString( 'max-height: 72px', $mailer->look()->settings()['context']['logo'] );
	}

	public function test_the_sender_and_the_shops_address_are_read_from_the_shops_keys(): void {
		$this->saved(
			[
				'email_from_name'    => 'Sugar Shop',
				'email_from_address' => 'hello@sugar.test',
				'business_email'     => 'help@sugar.test',
			]
		);

		$mailer = $this->mailer();

		$this->assertSame(
			[
				'name'     => 'Sugar Shop',
				'address'  => 'hello@sugar.test',
				'reply_to' => 'help@sugar.test',
			],
			$mailer->sender()->identity()
		);

		// A note to the shop with nobody typed on its panel goes to the
		// shop's own address, not the site administrator's.
		$this->assertSame( 'help@sugar.test', $mailer->sender()->recipient( 'admin_sale', (object) [] ) );
	}

	public function test_the_settings_screen_saves_under_the_shops_keys(): void {
		$mailer = $this->mailer();

		$this->assertSame(
			[ 'email_receipt', 'email_admin_sale', 'email_license_expiring', 'email_sales_summary' ],
			array_keys( $mailer->fields() )
		);

		$this->assertSame(
			[
				'email_template',
				'email_brand_color',
				'email_header_align',
				'email_logo',
				'email_logo_height',
				'email_footer_enabled',
				'email_footer',
				'email_look_preview',
			],
			array_keys( $mailer->look_card()->fields() )
		);

		$this->assertSame( [ 'email_footer_enabled' => 1 ], $mailer->look_card()->fields()['email_footer']['show_when'] );
		$this->assertSame( [ 'look' ], array_keys( $mailer->look_card()->sections() ) );
	}

	public function test_filling_blanks_writes_back_to_the_same_key_and_nothing_else(): void {
		$this->saved(
			[
				'email_receipt' => [ 'subject' => '' ],
				'currency'      => 'GBP',
			]
		);

		$this->mailer()->panels()->fill_blanks();

		$this->assertSame(
			[
				'email_receipt' => [
					'subject' => 'Your order {order_number}',
					'body'    => "<p>Thanks.</p>\n",
					'enabled' => 1,
				],
				'currency'      => 'GBP',
			],
			$GLOBALS['re_options'][ self::OPTION ]
		);
	}

	public function test_a_plugin_with_its_own_reader_and_writer_is_read_and_written_through_them(): void {
		$read    = [];
		$written = [];

		$mailer = Mailer::register(
			'shop',
			[
				'settings' => static fn(): array => [ 'email_receipt' => [ 'subject' => '' ] ],
				'option'   => static function ( string $key, $fallback ) use ( &$read ) {
					$read[] = $key;

					return 'email_from_name' === $key ? 'Through The Screen' : $fallback;
				},
				'update'   => static function ( string $key, $value ) use ( &$written ): bool {
					$written[ $key ] = $value;

					return true;
				},
				'contexts' => [ 'order' => [ 'load' => static fn( $id ) => (object) [ 'id' => $id ] ] ],
				'emails'   => static fn( Mailer $mailer ) => $mailer->register_email( 'receipt', [ 'subject' => 'Hi', 'body' => 'Body' ] ),
			]
		);

		$this->assertSame( 'Through The Screen', $mailer->sender()->identity()['name'] );
		$this->assertContains( 'email_from_name', $read );

		$mailer->panels()->fill_blanks();

		$this->assertSame( [ 'email_receipt' ], array_keys( $written ) );
		$this->assertArrayNotHasKey( 'shop_settings', $GLOBALS['re_options'] );
	}

	public function test_every_hook_is_named_from_the_prefix(): void {
		$seen = [];

		foreach ( [ 'send_email', 'email_contexts', 'email_templates', 'email_brand', 'email_sender', 'emails', 'email_tags' ] as $filter ) {
			add_filter(
				'sugarcart_' . $filter,
				static function ( $value ) use ( $filter, &$seen ) {
					$seen[] = $filter;

					return $value;
				}
			);
		}

		$mailer = $this->mailer();

		$this->assertTrue( $mailer->send( 'receipt', 5 ) );
		$mailer->emails();
		$mailer->tags( 'order' );

		foreach ( [ 'send_email', 'email_contexts', 'email_templates', 'email_brand', 'email_sender', 'emails', 'email_tags' ] as $filter ) {
			$this->assertContains( $filter, $seen, $filter );
		}

		foreach ( [ 'sugarcart_register_email_tags', 'sugarcart_register_emails', 'sugarcart_email_sent' ] as $action ) {
			$this->assertContains( $action, $GLOBALS['re_actions'] );
		}

		$this->assertSame( 'sugarcart_email_look_preview', $mailer->look_card()->action() );

		$error = $mailer->send( 'nothing', 5 );
		$this->assertSame( 'sugarcart_unknown_email', $error->get_error_code() );
	}
}
