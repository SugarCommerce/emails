<?php
/**
 * Editable emails: registering, sending, the look and the settings screen.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Tests;

use SugarCommerce\Emails\Emails;
use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Tags;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * What a site owner changes is what goes out, and nothing else does.
 *
 * Most of these are about an email somebody can edit: that the wording they
 * saved is sent, that a switch they threw is honoured, that a preview is of
 * what is on their screen and not of what was last saved, and that a test
 * goes to them and not to a customer.
 */
final class MailerTest extends TestCase {

	/**
	 * Orders the `order` context finds, by id.
	 *
	 * @var array<int, object>
	 */
	private array $orders = [];

	/**
	 * The newest order's id, for a test email.
	 *
	 * @var int
	 */
	private int $latest = 0;

	/**
	 * Reset the stubbed globals and the registries.
	 */
	protected function setUp(): void {
		re_reset_globals();

		$this->orders = [
			5 => (object) [
				'id'     => 5,
				'email'  => 'buyer@example.test',
				'name'   => 'Jane <b>Doe</b>',
				'number' => 'A-5',
			],
			6 => (object) [
				'id'          => 6,
				'email'       => '',
				'customer_id' => 9,
				'name'        => 'No Address',
				'number'      => 'A-6',
			],
		];
		$this->latest = 0;
	}

	/**
	 * A mailer with an order context, two tags and the emails given.
	 *
	 * @param callable|null        $emails Registers the emails.
	 * @param array<string, mixed> $config Anything else.
	 *
	 * @return Mailer
	 */
	private function mailer( ?callable $emails = null, array $config = [] ): Mailer {
		return Mailer::register(
			'shop',
			array_merge(
				[
					'contexts' => [
						'order' => [
							'load'   => fn( $id ) => $this->orders[ (int) $id ] ?? null,
							'latest' => fn(): int => $this->latest,
						],
					],
					'tags'     => static function ( Mailer $mailer ): void {
						$mailer->register_tag(
							'customer_name',
							[
								'label'       => 'Customer name',
								'description' => 'Who bought it.',
								'group'       => 'Customer',
								'callback'    => static fn( $order ): string => (string) $order->name,
								'sample'      => 'Sam <i>Ple</i>',
							]
						);
						$mailer->register_tag(
							'site',
							[
								'group'    => 'Site',
								'callback' => static fn(): string => 'Example Shop',
								'sample'   => 'Example Shop',
							]
						);
						$mailer->register_tag(
							'order_number',
							[
								'group'    => 'Order',
								'callback' => static fn( $order ): string => (string) $order->number,
								'sample'   => 'A-1001',
							]
						);
					},
					'emails'   => $emails ?? static function ( Mailer $mailer ): void {
						$mailer->register_email(
							'receipt',
							[
								'label'   => 'Receipt',
								'subject' => 'Order {order_number}',
								'body'    => "Hello {customer_name},\n\nThanks.",
							]
						);
						$mailer->register_email(
							'admin_sale',
							[
								'label'     => 'New sale',
								'subject'   => 'New order {order_number}',
								'body'      => 'A sale.',
								'recipient' => 'admin',
							]
						);
					},
				],
				$config
			)
		);
	}

	/**
	 * Seed the default option.
	 *
	 * @param array<string, mixed> $settings What it holds.
	 *
	 * @return void
	 */
	private function saved( array $settings ): void {
		$GLOBALS['re_options']['shop_settings'] = $settings;
	}

	/**
	 * The last email wp_mail() was handed.
	 *
	 * @return array<string, mixed>
	 */
	private function last_mail(): array {
		$this->assertNotEmpty( $GLOBALS['re_mail'], 'Nothing was sent.' );

		return $GLOBALS['re_mail'][ array_key_last( $GLOBALS['re_mail'] ) ];
	}

	// Registering ----------------------------------------------------------

	public function test_a_registration_made_early_waits_and_replaces_a_built_in_tag(): void {
		$mailer = $this->mailer();

		// Before ready(): kept, not added.
		$mailer->register_tag( 'order_number', [ 'callback' => static fn(): string => 'REPLACED' ] );
		$mailer->register_email( 'review', [ 'subject' => 'Review', 'body' => 'Tell us.' ] );

		$this->assertSame( [], Tags::group( 'shop' ) );

		$mailer->ready();

		$this->assertSame( 'REPLACED', Tags::get( 'shop', 'order_number' )->render( $this->orders[5] ) );
		$this->assertSame( [ 'receipt', 'admin_sale', 'review' ], array_keys( $mailer->registry()->emails() ) );

		// Once ready, straight in.
		$mailer->register_email( 'late', [ 'body' => 'x' ] );
		$this->assertNotNull( Emails::get( 'shop', 'late' ) );
	}

	public function test_words_given_as_closures_are_worked_out_when_first_read(): void {
		$calls  = 0;
		$mailer = $this->mailer(
			null,
			[
				'labels'         => static function () use ( &$calls ): array {
					++$calls;

					return [ 'enabled' => 'Send it' ];
				},
				'templates'      => static fn(): array => [ 'letter' => [ 'label' => 'Letter' ] ],
				'last_tag_group' => static fn(): string => 'Customer',
			]
		);

		$this->assertSame( 0, $calls, 'Nothing is translated when the mailer is registered.' );
		$this->assertSame( 'Send it', $mailer->label( 'enabled' ) );
		$this->assertSame( 'On', $mailer->label( 'on' ) );
		$this->assertSame( 1, $calls, 'The closure runs once.' );
		$this->assertSame( [ 'letter' ], array_keys( $mailer->look()->templates() ) );
		$this->assertSame( 'Customer', array_column( $mailer->panels()->editor_tags( 'order' ), 'group' )[2] );
	}

	public function test_another_plugin_registers_on_the_prefixed_actions(): void {
		add_action(
			'shop_register_emails',
			static function ( string $group, Mailer $mailer ): void {
				$mailer->register_email( 'from_addon', [ 'subject' => 'Add-on' ] );
			}
		);

		$mailer = $this->mailer();

		$this->assertNotNull( $mailer->registry()->get( 'from_addon' ) );
		$this->assertSame( 'email_from_addon', $mailer->registry()->get( 'from_addon' )['setting'] );
	}

	public function test_a_tag_without_a_callback_or_an_unknown_context_is_refused(): void {
		$mailer = $this->mailer( null, [ 'functions' => [ 'register_tag' => 'shop_register_email_tag' ] ] );
		$mailer->ready();

		$this->assertFalse( $mailer->register_tag( 'nothing', [] ) );
		$this->assertFalse( $mailer->register_tag( 'stray', [ 'context' => 'booking', 'callback' => 'strval' ] ) );
		$this->assertSame( 'shop_register_email_tag', $GLOBALS['re_wrong'][0][0] );
		$this->assertStringContainsString( '&quot;booking&quot;', $GLOBALS['re_wrong'][0][1] );
		$this->assertStringContainsString( 'shop_email_contexts', $GLOBALS['re_wrong'][0][1] );

		$this->assertFalse( $mailer->register_email( 'stray', [ 'context' => 'booking' ] ) );
	}

	public function test_a_context_can_be_added_by_filter(): void {
		add_filter(
			'shop_email_contexts',
			static function ( array $contexts ): array {
				$contexts['booking'] = [
					'group' => 'bookings',
					'load'  => static fn( $id ) => (object) [ 'id' => $id ],
				];

				return $contexts;
			}
		);

		$mailer = $this->mailer();
		$mailer->ready();

		$this->assertTrue( $mailer->register_tag( 'booking_id', [ 'context' => 'booking', 'callback' => static fn( $b ): string => 'B' . $b->id ] ) );
		$this->assertSame( 'B3', Tags::get( 'bookings', 'booking_id' )->render( 3 ) );
		$this->assertSame( 'shop', $mailer->contexts()->group( 'order' ) );
		$this->assertSame( '', $mailer->contexts()->group( 'nothing' ) );
	}

	public function test_an_email_can_be_unregistered_and_its_neighbours_stay(): void {
		$mailer = $this->mailer();

		$mailer->ready();

		$this->assertTrue( $mailer->unregister_email( 'receipt' ) );
		$this->assertFalse( $mailer->unregister_email( 'receipt' ) );
		$this->assertNull( Emails::get( 'shop', 'receipt' ) );
		$this->assertNotNull( Emails::get( 'shop', 'admin_sale' ) );
		$this->assertArrayNotHasKey( 'email_receipt', $mailer->fields() );
	}

	public function test_a_built_in_email_can_be_taken_away_before_it_is_registered(): void {
		$mailer = $this->mailer();

		// On plugins_loaded, say: the receipt is not registered yet.
		$this->assertTrue( $mailer->unregister_email( 'receipt' ) );

		$mailer->ready();

		$this->assertNull( $mailer->registry()->get( 'receipt' ) );
		$this->assertNull( Emails::get( 'shop', 'receipt' ) );
		$this->assertNotNull( $mailer->registry()->get( 'admin_sale' ) );
	}

	public function test_the_tags_are_listed_with_their_descriptions(): void {
		$mailer = $this->mailer( null, [ 'last_tag_group' => 'Site' ] );

		$tags = $mailer->tags();

		$this->assertSame(
			[
				'tag'         => 'customer_name',
				'placeholder' => '{customer_name}',
				'label'       => 'Customer name',
				'description' => 'Who bought it.',
				'html'        => false,
				'group'       => 'Customer',
			],
			$tags[0]
		);

		// The heading named last goes last, whenever it was registered.
		$this->assertSame( [ '{customer_name}', '{order_number}', '{site}' ], array_column( $mailer->panels()->editor_tags( 'order' ), 'tag' ) );
	}

	public function test_the_list_of_emails_says_what_each_would_be_sent_with(): void {
		$this->saved( [ 'email_admin_sale' => [ 'enabled' => 0, 'subject' => 'Ka-ching' ] ] );

		$emails = $this->mailer()->emails();

		$this->assertSame( 'customer', $emails['receipt']['recipient'] );
		$this->assertTrue( $emails['receipt']['enabled'] );
		$this->assertSame( 'Order {order_number}', $emails['receipt']['subject'] );
		$this->assertFalse( $emails['admin_sale']['enabled'] );
		$this->assertSame( 'Ka-ching', $emails['admin_sale']['subject'] );
		$this->assertSame( 'email_admin_sale', $emails['admin_sale']['setting'] );
	}

	// Sending --------------------------------------------------------------

	public function test_an_email_is_sent_with_the_saved_wording_about_the_record(): void {
		$this->saved( [ 'email_receipt' => [ 'subject' => 'Saved {order_number}', 'body' => 'Saved for {customer_name}.' ] ] );

		$this->assertTrue( $this->mailer()->send( 'receipt', 5 ) );

		$mail = $this->last_mail();

		$this->assertSame( [ 'buyer@example.test' ], $mail['to'] );
		$this->assertSame( 'Saved A-5', $mail['subject'] );

		// A text tag is escaped: a customer's name is not markup.
		$this->assertStringContainsString( 'Saved for Jane &lt;b&gt;Doe&lt;/b&gt;.', $mail['message'] );
		$this->assertContains( 'shop_email_sent', $GLOBALS['re_actions'] );
	}

	public function test_an_email_switched_off_is_not_sent_and_is_not_an_error(): void {
		$this->saved( [ 'email_receipt' => [ 'enabled' => '0' ] ] );

		$this->assertTrue( $this->mailer()->send( 'receipt', 5 ) );
		$this->assertSame( [], $GLOBALS['re_mail'] );
	}

	public function test_an_email_never_switched_is_what_it_was_registered_with(): void {
		// Reworded before there was a switch: no `enabled` at all.
		$this->saved( [ 'email_receipt' => [ 'subject' => 'Reworded' ] ] );

		$mailer = $this->mailer(
			static function ( Mailer $mailer ): void {
				$mailer->register_email( 'receipt', [ 'body' => 'x' ] );
				$mailer->register_email( 'dormant', [ 'body' => 'x', 'enabled' => false ] );
			}
		);

		$this->assertTrue( $mailer->sender()->enabled( 'receipt' ) );
		$this->assertFalse( $mailer->sender()->enabled( 'dormant' ) );
		$this->assertFalse( $mailer->sender()->enabled( 'unknown' ) );

		$this->assertTrue( $mailer->send( 'dormant', 5 ) );
		$this->assertSame( [], $GLOBALS['re_mail'] );
	}

	public function test_a_caller_can_change_the_recipient_subject_and_body(): void {
		$this->mailer()->send( 'receipt', 5, [ 'to' => 'other@example.test', 'subject' => 'Given', 'body' => 'Given body' ] );

		$mail = $this->last_mail();

		$this->assertSame( [ 'other@example.test' ], $mail['to'] );
		$this->assertSame( 'Given', $mail['subject'] );
		$this->assertStringContainsString( '<p>Given body</p>', $mail['message'] );
	}

	public function test_files_and_copies_come_from_the_registration_the_caller_and_the_filters(): void {
		$invoice = tempnam( sys_get_temp_dir(), 'inv' );
		$terms   = tempnam( sys_get_temp_dir(), 'terms' );
		$extra   = tempnam( sys_get_temp_dir(), 'extra' );

		$mailer = $this->mailer(
			static function ( Mailer $mailer ) use ( $invoice ): void {
				$mailer->register_email(
					'receipt',
					[
						'subject'     => 'Order {order_number}',
						'body'        => 'Thanks.',
						'attachments' => static fn( $order, string $id ): array => 'receipt' === $id && 5 === $order->id ? [ $invoice ] : [],
						'bcc'         => 'books@example.test',
					]
				);
			}
		);

		add_filter( 'shop_email_attachments', static fn( array $paths, string $id, $order ): array => array_merge( $paths, [ $extra, '/no/such/file.pdf' ] ) );
		add_filter( 'shop_email_cc', static fn( array $cc ): array => array_merge( $cc, [ 'boss@example.test' ] ) );
		add_filter(
			'shop_email_headers',
			static fn(): array => [
				'X-Order-Id' => '5',
				'Bcc'        => 'sneaky@example.test',
				'Bad Name'   => 'x',
			]
		);

		$built = null;
		$GLOBALS['re_hooks']['shop_email_before_send'][] = static function ( $email, string $id, $order ) use ( &$built ): void {
			$built = [ $id, $order->id ];
		};

		$this->assertTrue( $mailer->send( 'receipt', 5, [ 'attachments' => [ $terms ], 'cc' => 'partner@example.test' ] ) );

		$mail    = $this->last_mail();
		$headers = implode( "\n", (array) $mail['headers'] );

		$this->assertSame( [ $invoice, $terms, $extra ], $mail['attachments'] );
		$this->assertStringContainsString( 'Cc: partner@example.test', $headers );
		$this->assertStringContainsString( 'boss@example.test', $headers );
		$this->assertStringContainsString( 'Bcc: books@example.test', $headers );
		$this->assertStringContainsString( 'X-Order-Id: 5', $headers );
		$this->assertStringNotContainsString( 'sneaky@example.test', $headers );
		$this->assertStringNotContainsString( 'Bad Name', $headers );
		$this->assertSame( [ 'receipt', 5 ], $built );

		// Nothing given, nothing added.
		$this->mailer()->send( 'admin_sale', 5 );

		unlink( $invoice );
		unlink( $terms );
		unlink( $extra );
	}

	public function test_an_email_with_nothing_extra_has_no_files_or_copies(): void {
		$this->mailer()->send( 'receipt', 5 );

		$mail = $this->last_mail();

		$this->assertSame( [], $mail['attachments'] );
		$this->assertStringNotContainsString( 'Cc:', implode( "\n", (array) $mail['headers'] ) );
		$this->assertContains( 'shop_email_before_send', $GLOBALS['re_actions'] );
	}

	public function test_errors_name_the_problem_and_the_failure_is_announced(): void {
		$mailer = $this->mailer();

		$this->assertSame( 'shop_unknown_email', $mailer->send( 'nothing', 5 )->get_error_code() );
		$this->assertSame( 'shop_email_no_record', $mailer->send( 'receipt', 404 )->get_error_code() );

		// Order 6 has no address, and nothing says how to find one.
		$error = $mailer->send( 'receipt', 6 );

		$this->assertSame( 'shop_email_no_recipient', $error->get_error_code() );
		$this->assertContains( 'shop_email_failed', $GLOBALS['re_actions'] );
		$this->assertNotContains( 'shop_email_sent', $GLOBALS['re_actions'] );
	}

	public function test_a_customer_with_no_address_on_the_record_is_found_by_the_callback(): void {
		$mailer = $this->mailer( null, [ 'customer_email' => static fn( $order ): string => 9 === $order->customer_id ? 'found@example.test' : '' ] );

		$this->assertTrue( $mailer->send( 'receipt', 6 ) );
		$this->assertSame( [ 'found@example.test' ], $this->last_mail()['to'] );
	}

	public function test_a_note_to_the_site_goes_where_it_was_told(): void {
		$mailer = $this->mailer();

		// Nobody said: the site's admin address.
		$this->assertSame( 'admin@example.test', $mailer->sender()->recipient( 'admin_sale', $this->orders[5] ) );

		// The address the plugin keeps for itself, when it has one.
		$keyed = Mailer::register( 'keyed', [ 'keys' => [ 'admin_email' => 'shop_email' ], 'contexts' => [ 'order' => [] ] ] );
		$keyed->register_email( 'note', [ 'recipient' => 'admin' ] );
		$GLOBALS['re_options']['keyed_settings'] = [ 'shop_email' => 'shop@example.test' ];
		$this->assertSame( 'shop@example.test', $keyed->sender()->recipient( 'note', null ) );

		// What was typed on the panel, several and tidied.
		$this->saved( [ 'email_admin_sale' => [ 'recipient' => 'one@example.test, not an address ,two@example.test' ] ] );
		$this->assertSame( [ 'one@example.test', 'two@example.test' ], $mailer->sender()->recipient( 'admin_sale', $this->orders[5] ) );

		$mailer->send( 'admin_sale', 5 );
		$this->assertSame( [ 'one@example.test', 'two@example.test' ], $this->last_mail()['to'] );

		// Each written as people write them, a comma inside quotes and all.
		$this->saved( [ 'email_admin_sale' => [ 'recipient' => '"Accounts, Ltd" <accounts@example.test>, Boss <boss@example.test>' ] ] );
		$this->assertSame( [ 'accounts@example.test', 'boss@example.test' ], $mailer->sender()->recipient( 'admin_sale', $this->orders[5] ) );
	}

	public function test_a_from_address_typed_with_a_name_is_the_address(): void {
		$mailer = $this->mailer( null, [ 'keys' => [ 'reply_to' => 'support' ] ] );

		$this->saved(
			[
				'email_from_address' => 'The Shop <hello@example.test>',
				'support'            => 'Help <help@example.test>',
			]
		);

		$this->assertSame( 'hello@example.test', $mailer->sender()->identity()['address'] );
		$this->assertSame( 'help@example.test', $mailer->sender()->identity()['reply_to'] );
	}

	public function test_a_recipient_worked_out_in_code_is_asked(): void {
		$mailer = $this->mailer(
			static function ( Mailer $mailer ): void {
				$mailer->register_email( 'summary', [ 'recipient' => static fn( $order ): array => [ 'a@example.test', '', 'b@example.test' ] ] );
			}
		);

		$this->assertSame( [ 'a@example.test', 'b@example.test' ], $mailer->sender()->recipient( 'summary', $this->orders[5] ) );
	}

	public function test_sending_can_be_taken_over(): void {
		$mailer = $this->mailer();

		add_filter( 'shop_send_email', static fn( $sent, string $id ) => 'receipt' === $id ? new WP_Error( 'mine', 'Mine' ) : true );

		$this->assertSame( 'mine', $mailer->send( 'receipt', 5 )->get_error_code() );
		$this->assertTrue( $mailer->send( 'admin_sale', 5 ) );
		$this->assertSame( [], $GLOBALS['re_mail'] );
		$this->assertNotContains( 'shop_email_sent', $GLOBALS['re_actions'] );
	}

	public function test_wording_shipped_earlier_and_never_touched_is_replaced_by_the_current(): void {
		$mailer = $this->mailer(
			static function ( Mailer $mailer ): void {
				$mailer->register_email(
					'receipt',
					[
						'body'    => "Line one.\n\nNew line.",
						'earlier' => [ "Line one.\n\nOld line." ],
					]
				);
			}
		);

		// As an editor saves it: without paragraphs, broken differently.
		$this->saved( [ 'email_receipt' => [ 'body' => "Line one.\n\n  Old line." ] ] );
		$this->assertSame( "<p>Line one.</p>\n<p>New line.</p>\n", $mailer->sender()->wording( 'receipt' )['body'] );

		// One word changed, and it is the site's own for ever.
		$this->saved( [ 'email_receipt' => [ 'body' => "Line one.\n\nOld line!" ] ] );
		$this->assertSame( "<p>Line one.</p>\n<p>Old line!</p>\n", $mailer->sender()->wording( 'receipt' )['body'] );
	}

	// Who it is from -------------------------------------------------------

	public function test_an_empty_sender_is_the_site_at_wordpresss_address(): void {
		$identity = $this->mailer()->sender()->identity();

		$this->assertSame(
			[
				'name'     => 'Example Shop',
				'address'  => 'wordpress@example.test',
				'reply_to' => '',
			],
			$identity
		);
		$this->assertSame( 'wordpress@example.test', Mailer::default_address() );
	}

	public function test_a_reply_goes_elsewhere_only_when_it_is_elsewhere(): void {
		$mailer = $this->mailer( null, [ 'keys' => [ 'reply_to' => 'support' ] ] );

		$this->saved(
			[
				'email_from_name'    => "Shop\nName",
				'email_from_address' => 'hello@example.test',
				'support'            => 'HELLO@example.test',
			]
		);
		$this->assertSame( '', $mailer->sender()->identity()['reply_to'] );

		$this->saved( [ 'email_from_address' => 'not-an-address', 'support' => 'help@example.test' ] );
		$this->assertSame( 'wordpress@example.test', $mailer->sender()->identity()['address'] );
		$this->assertSame( 'help@example.test', $mailer->sender()->identity()['reply_to'] );
	}

	public function test_a_sent_email_and_a_test_both_come_from_the_site(): void {
		$this->saved( [ 'email_from_name' => 'The Shop', 'email_from_address' => 'hello@example.test' ] );

		$mailer = $this->mailer();
		$mailer->send( 'receipt', 5 );

		$this->assertContains( 'From: The Shop <hello@example.test>', $this->last_mail()['headers'] );

		// A test with nothing to be about is the preview, handed to wp_mail().
		$result = $mailer->actions()->test( 'receipt' )( [] );

		$this->assertTrue( $result['success'] );
		$this->assertContains( 'From: The Shop <hello@example.test>', $this->last_mail()['headers'] );
		$this->assertSame( [ 'From: The Shop <hello@example.test>' ], $mailer->sender()->headers() );
	}

	public function test_the_sender_can_be_filtered(): void {
		add_filter( 'shop_email_sender', static fn( array $sender ): array => [ 'name' => 'Filtered' ] + $sender );

		$this->assertSame( 'Filtered', $this->mailer()->sender()->identity()['name'] );
	}

	// The look -------------------------------------------------------------

	public function test_only_a_real_colour_design_and_size_are_used(): void {
		$this->saved(
			[
				'email_template'     => '../../etc/passwd',
				'email_brand_color'  => 'red;background:url(x)',
				'email_logo'         => 'twelve',
				'email_logo_height'  => 'enormous',
				'email_header_align' => 'right',
			]
		);

		$chosen = $this->mailer( null, [ 'colour' => '#123456' ] )->look()->chosen();

		$this->assertSame( 'default', $chosen['template'] );
		$this->assertSame( '#123456', $chosen['color'] );
		$this->assertSame( 0, $chosen['logo'] );
		$this->assertSame( 'medium', $chosen['logo_height'] );
		$this->assertSame( 'center', $chosen['align'] );
		$this->assertTrue( $chosen['footer_enabled'] );
	}

	public function test_the_footer_is_the_default_until_one_is_written_and_can_be_switched_off(): void {
		$mailer = $this->mailer(
			null,
			[
				'default_footer' => static fn(): string => "Example Shop\n1 High Street",
				'fill_footer'    => static fn( string $text ): string => str_replace( '{shop}', 'Example <Shop>', $text ),
			]
		);

		$this->assertSame( 'Example Shop<br />1 High Street', $mailer->look()->settings()['context']['footer'] );

		// Written: its tags filled in, and still text.
		$this->saved( [ 'email_footer' => "© {shop}\n\n" ] );
		$this->assertSame( '© Example &lt;Shop&gt;', $mailer->look()->settings()['context']['footer'] );

		$this->saved( [ 'email_footer' => 'Written', 'email_footer_enabled' => '0' ] );
		$this->assertSame( '', $mailer->look()->settings()['context']['footer'] );
	}

	public function test_a_footer_without_a_filler_has_the_year_and_the_site(): void {
		$this->saved( [ 'email_footer' => '{site_name} {year}' ] );

		$this->assertSame( 'Example Shop ' . gmdate( 'Y' ), $this->mailer()->look()->brand()['footer'] );
	}

	public function test_a_look_can_be_tried_without_saving_it_and_is_forgotten_after(): void {
		$this->saved( [ 'email_brand_color' => '#111111' ] );

		$look = $this->mailer()->look();

		$inside = $look->trying( [ 'color' => '#ABCDEF', 'nonsense' => 'x' ], static fn() => $look->chosen()['color'] );

		$this->assertSame( '#abcdef', $inside );
		$this->assertSame( '#111111', $look->chosen()['color'] );
		$this->assertNull( $look->tried( 'color' ) );
		$this->assertSame( [ 'email_brand_color' => '#111111' ], $GLOBALS['re_options']['shop_settings'] );
	}

	public function test_an_email_can_have_a_design_of_its_own(): void {
		$mailer = $this->mailer(
			static function ( Mailer $mailer ): void {
				$mailer->register_email( 'plain_one', [ 'template' => 'plain' ] );
				$mailer->register_email( 'other', [] );
			}
		);
		$look   = $mailer->look();

		$this->saved( [ 'email_template' => 'soft' ] );

		$this->assertSame( 'plain', $look->brand( 'plain_one' )['template'] );
		$this->assertSame( 'soft', $look->brand( 'other' )['template'] );

		// Picked on its card, it wins over what it was registered with.
		$this->saved( [ 'email_template' => 'soft', 'email_plain_one' => [ 'template' => 'dark' ] ] );
		$this->assertSame( 'dark', $look->brand( 'plain_one' )['template'] );

		// And what is on the card now wins over that.
		$this->assertSame( 'mono', $look->trying( [ 'own_template' => 'mono' ], static fn() => $look->brand( 'plain_one' )['template'] ) );

		// A name that is not a design is the site's.
		$this->saved( [ 'email_template' => 'soft', 'email_plain_one' => [ 'template' => 'nope' ] ] );
		$this->assertSame( 'soft', $look->brand( 'plain_one' )['template'] );
	}

	public function test_another_plugin_can_bring_a_design_and_change_the_look(): void {
		$file = sys_get_temp_dir() . '/re-letterhead-' . getmypid() . '.html';
		file_put_contents( $file, '<html><body style="color:{color_primary}">LETTERHEAD {content}</body></html>' );

		add_filter(
			'shop_email_templates',
			static function ( array $templates ) use ( $file ): array {
				$templates['letterhead'] = [
					'label' => 'Letterhead',
					'file'  => $file,
				];

				return $templates;
			}
		);
		add_filter( 'shop_email_brand', static fn( array $brand ): array => [ 'color' => '#00ff00' ] );

		$this->saved( [ 'email_template' => 'letterhead' ] );

		$mailer = $this->mailer();
		$mailer->send( 'receipt', 5 );
		unlink( $file );

		$message = $this->last_mail()['message'];

		$this->assertStringContainsString( 'LETTERHEAD', $message );
		$this->assertStringContainsString( 'color:#00ff00', $message );
		$this->assertSame( 'letterhead', $mailer->look()->drawing() );
		$this->assertStringContainsString( 'sugarcommerce-emails-look--letterhead', $mailer->look_card()->fields()['email_template']['options']['letterhead']['preview'] );
	}

	public function test_the_saved_look_is_what_is_sent(): void {
		$this->saved(
			[
				'email_brand_color' => '#ffee00',
				'email_logo'        => 9,
				'email_footer'      => 'Small print',
			]
		);

		$this->mailer()->send( 'receipt', 5 );

		$message = $this->last_mail()['message'];

		$this->assertStringContainsString( '#ffee00', $message );
		$this->assertStringContainsString( 'uploads/9-medium.png', $message );
		$this->assertStringContainsString( 'Small print', $message );
	}

	// The settings screen --------------------------------------------------

	public function test_each_email_has_a_panel_made_from_its_registration(): void {
		$mailer = $this->mailer(
			static function ( Mailer $mailer ): void {
				$mailer->register_email( 'receipt', [ 'label' => 'Receipt', 'subject' => 'S', 'body' => 'B', 'description' => 'When paid.' ] );
				$mailer->register_email( 'admin_sale', [ 'recipient' => 'admin', 'enabled' => false ] );
				$mailer->register_email( 'hidden', [ 'panel' => false ] );
				$mailer->register_email( 'own', [ 'panel' => static fn( string $id ): array => [ 'custom_' . $id => [ 'type' => 'text' ] ] ] );
			},
			[
				'panel'  => [
					'tab'     => 'mail',
					'section' => 'sent',
				],
				'labels' => [ 'enabled' => 'Send it' ],
			]
		);

		$fields = $mailer->fields();

		$this->assertSame( [ 'email_receipt', 'email_admin_sale', 'custom_own' ], array_keys( $fields ) );

		$receipt = $fields['email_receipt'];

		$this->assertSame( 'email_editor', $receipt['type'] );
		$this->assertSame( [ 'mail', 'sent' ], [ $receipt['tab'], $receipt['section'] ] );
		$this->assertSame( [ 'enabled', 'subject', 'heading', 'body', 'template' ], array_keys( $receipt['fields'] ) );
		$this->assertSame( 'Send it', $receipt['fields']['enabled']['label'] );
		$this->assertSame(
			[
				'enabled'  => 1,
				'subject'  => 'S',
				'heading'  => '',
				'body'     => "<p>B</p>\n",
				'template' => '',
			],
			$receipt['default']
		);
		$this->assertSame( 'When paid.', $receipt['summary'] );
		$this->assertSame( 'To the customer', $receipt['meta'] );
		$this->assertTrue( $receipt['collapsed'] );
		$this->assertIsCallable( $receipt['actions']['preview'] );
		$this->assertIsCallable( $receipt['actions']['test'] );
		$this->assertNotEmpty( $receipt['tags'] );

		// Only a note to the site has somewhere else to send it.
		$sale = $fields['email_admin_sale'];

		$this->assertSame( [ 'enabled', 'recipient', 'subject', 'heading', 'body', 'template' ], array_keys( $sale['fields'] ) );
		$this->assertSame( 'admin@example.test', $sale['fields']['recipient']['placeholder'] );
		$this->assertSame( 0, $sale['default']['enabled'] );
		$this->assertSame( 'To you', $sale['meta'] );
	}

	public function test_blank_parts_are_filled_and_written_ones_kept(): void {
		$this->saved(
			[
				'email_receipt'    => [
					'subject' => '  ',
					'body'    => '<p> </p>',
					'heading' => 'Mine',
				],
				'email_admin_sale' => [
					'enabled' => 0,
					'subject' => 'Kept',
					'body'    => 'Kept body',
				],
			]
		);

		$this->mailer()->panels()->fill_blanks();

		$saved = $GLOBALS['re_options']['shop_settings'];

		$this->assertSame( 'Order {order_number}', $saved['email_receipt']['subject'] );
		$this->assertSame( "<p>Hello {customer_name},</p>\n<p>Thanks.</p>\n", $saved['email_receipt']['body'] );
		$this->assertSame( 'Mine', $saved['email_receipt']['heading'] );

		// Written down as what it has been all along: on.
		$this->assertSame( 1, $saved['email_receipt']['enabled'] );

		$this->assertSame(
			[
				'enabled' => 0,
				'subject' => 'Kept',
				'body'    => 'Kept body',
			],
			$saved['email_admin_sale']
		);
	}

	public function test_nothing_is_written_for_an_email_never_saved(): void {
		$this->saved( [] );

		$this->mailer()->panels()->fill_blanks();

		$this->assertSame( [], $GLOBALS['re_options']['shop_settings'] );
	}

	public function test_a_preview_is_of_the_card_as_it_stands_and_saves_nothing(): void {
		$this->saved( [ 'email_receipt' => [ 'subject' => 'Saved', 'heading' => 'Saved heading', 'body' => 'Saved body' ] ] );

		$preview = $this->mailer()->actions()->preview( 'receipt' );

		$result = $preview(
			[
				'subject'    => 'On screen {order_number}',
				'heading'    => '',
				'body'       => "Unsaved for {customer_name}\n\nSecond",
				'look_color' => '#ff00ff',
			]
		);

		$this->assertTrue( $result['success'] );

		$html = html_entity_decode( $result['data']['html'], ENT_QUOTES );

		$this->assertStringContainsString( '<iframe class="sugarcommerce-emails-preview"', $result['data']['html'] );
		$this->assertStringContainsString( 'Unsaved for Sam &lt;i&gt;Ple&lt;/i&gt;', $html );
		$this->assertStringContainsString( 'On screen A-1001', $html );
		$this->assertStringContainsString( '#ff00ff', $html );

		// The heading was emptied on the card: the saved one is not shown.
		$this->assertStringNotContainsString( 'Saved heading', $html );
		$this->assertStringNotContainsString( 'Saved body', $html );
		$this->assertSame( [ 'email_receipt' => [ 'subject' => 'Saved', 'heading' => 'Saved heading', 'body' => 'Saved body' ] ], $GLOBALS['re_options']['shop_settings'] );
		$this->assertSame( [], $GLOBALS['re_mail'] );
	}

	public function test_the_preview_frame_escapes_the_whole_email(): void {
		$frame = $this->mailer()->actions()->frame( '<p title="x">&lt;b&gt;</p>' );

		$this->assertStringContainsString( 'srcdoc="&lt;p title=&quot;x&quot;&gt;&amp;lt;b&amp;gt;&lt;/p&gt;"', $frame );
	}

	public function test_a_test_goes_to_whoever_asked_about_the_newest_record(): void {
		$this->latest = 5;

		$this->saved( [ 'email_receipt' => [ 'enabled' => 0 ] ] );

		$result = $this->mailer()->actions()->test( 'receipt' )( [ 'subject' => 'Testing {order_number}' ] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Sent to tester@example.test.', $result['message'] );

		$mail = $this->last_mail();

		// Switched off, and still sent: it is a test. To the tester, about
		// the real order, and never to the customer on it.
		$this->assertSame( [ 'tester@example.test' ], $mail['to'] );
		$this->assertSame( 'Testing A-5', $mail['subject'] );
		$this->assertStringContainsString( 'Jane &lt;b&gt;Doe&lt;/b&gt;', $mail['message'] );
	}

	public function test_a_test_with_nothing_to_be_about_is_the_preview(): void {
		$result = $this->mailer()->actions()->test( 'receipt' )( [] );

		$this->assertTrue( $result['success'] );

		$mail = $this->last_mail();

		$this->assertSame( [ 'tester@example.test' ], $mail['to'] );
		$this->assertSame( 'Test email', $mail['subject'] );
		$this->assertStringContainsString( 'Sam &lt;i&gt;Ple&lt;/i&gt;', $mail['message'] );
	}

	public function test_a_test_needs_somebody_to_send_it_to(): void {
		$GLOBALS['re_user'] = '';

		$result = $this->mailer()->actions()->test( 'receipt' )( [] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( [], $GLOBALS['re_mail'] );
	}

	public function test_the_look_card_draws_an_email_in_the_look_on_the_screen(): void {
		$mailer = $this->mailer(
			null,
			[
				'look_card' => [
					'endpoint'      => static fn(): string => 'https://example.test/wp-json/kit/v1/action',
					'preview_email' => 'admin_sale',
				],
				'labels'    => [ 'look_drawing' => 'Drawing your receipt…' ],
			]
		);

		$html = $mailer->look_card()->fields()['email_look_preview']['html'];

		$this->assertStringContainsString( 'class="sugarcommerce-emails-look-preview"', $html );
		$this->assertStringContainsString( 'data-endpoint="https://example.test/wp-json/kit/v1/action"', $html );
		$this->assertStringContainsString( 'data-action="shop_email_look_preview"', $html );
		$this->assertStringContainsString( 'data-parts="email_template:look_template,email_brand_color:look_color,email_logo:look_logo,email_logo_height:look_logo_height,email_header_align:look_align,email_footer:look_footer,email_footer_enabled:look_footer_enabled"', $html );
		$this->assertStringContainsString( 'Drawing your receipt…', $html );

		$result = $mailer->look_card()->preview( [ 'look_color' => '#00aa00', 'look_template' => 'dark' ] );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'New order A-1001', html_entity_decode( $result['data']['html'], ENT_QUOTES ) );
		$this->assertStringContainsString( '#00aa00', html_entity_decode( $result['data']['html'], ENT_QUOTES ) );
		$this->assertSame( 'dark', $mailer->look()->drawing() );
	}

	public function test_the_look_card_hooks_its_assets_only_when_configured(): void {
		$this->mailer();
		$this->assertArrayNotHasKey( 'admin_enqueue_scripts', $GLOBALS['re_hooks'] );

		$this->mailer( null, [ 'look_card' => [ 'screen' => 'shop-settings' ] ] );
		$this->assertCount( 1, $GLOBALS['re_hooks']['admin_enqueue_scripts'] );
		$this->assertCount( 2, $GLOBALS['re_hooks']['init'] );
	}

	public function test_two_mailers_keep_to_themselves(): void {
		$one = $this->mailer();
		$two = Mailer::register(
			'other',
			[
				'key_prefix' => 'mail_',
				'contexts'   => [ 'order' => [ 'load' => fn( $id ) => $this->orders[ (int) $id ] ?? null ] ],
				'emails'     => static fn( Mailer $mailer ) => $mailer->register_email( 'receipt', [ 'subject' => 'Other receipt', 'body' => 'Other.' ] ),
			]
		);

		$sent = [];
		add_filter(
			'shop_send_email',
			static function ( $value, string $id ) use ( &$sent ) {
				$sent[] = $id;

				return $value;
			}
		);

		$two->send( 'receipt', 5 );

		$this->assertSame( [], $sent );
		$this->assertSame( 'Other receipt', $this->last_mail()['subject'] );
		$this->assertSame( 'mail_receipt', $two->registry()->get( 'receipt' )['setting'] );
		$this->assertSame( 'email_receipt', $one->registry()->get( 'receipt' )['setting'] );
		$this->assertSame( $one, Mailer::get( 'shop' ) );
	}
}
