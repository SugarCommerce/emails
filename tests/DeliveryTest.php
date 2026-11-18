<?php
/**
 * Transport, delivery and message tests.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Emails\Delivery;
use SugarCommerce\Emails\Email;
use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Message;
use SugarCommerce\Emails\Result;
use SugarCommerce\Emails\Tags;
use SugarCommerce\Emails\Transport;
use SugarCommerce\Emails\Transports\Memory;
use SugarCommerce\Emails\Transports\Postmark;
use SugarCommerce\Emails\Transports\Resend;
use SugarCommerce\Emails\Transports\WpMail;
use SugarCommerce\Emails\Utils\Assets;
use SugarCommerce\Emails\Utils\Runtime;
use WP_Error;

/**
 * How a finished email leaves the site.
 *
 * Through wp_mail() unless somebody named something else, and the same way
 * for a send, a registered email and a test of one. Most of these are about
 * the edges: the reason a send failed reaching whoever asked, a staging site
 * not emailing a customer, an API key not leaking into a header.
 */
final class DeliveryTest extends TestCase {

	/**
	 * Reset the stubbed globals and the registries.
	 */
	protected function setUp(): void {
		re_reset_globals();
	}

	/**
	 * A plain email to one person.
	 *
	 * @return Email
	 */
	private function email(): Email {
		return Email::make()
			->to( 'jane@example.test' )
			->subject( 'Your receipt' )
			->content( '<p>Thanks. <a href="https://example.test/d/1">Download</a></p>' );
	}

	// The default -------------------------------------------------------------

	public function test_an_email_goes_through_wp_mail_with_a_text_part_beside_it(): void {
		$this->assertTrue( $this->email()->send() );

		$mail = $GLOBALS['re_mail'][0];

		$this->assertSame( [ 'jane@example.test' ], $mail['to'] );
		$this->assertSame( 'Content-Type: text/html; charset=UTF-8', $mail['headers'][0] );
		$this->assertStringContainsString( 'Thanks. Download (https://example.test/d/1)', $mail['alt'] );

		// Set for this message only: the hook is gone once it is sent.
		$this->assertEmpty( $GLOBALS['re_hooks']['phpmailer_init'] ?? [] );
		$this->assertEmpty( $GLOBALS['re_hooks']['wp_mail_failed'] ?? [] );
	}

	public function test_the_text_part_can_be_written_or_left_out(): void {
		$this->email()->text( 'Thanks, in words.' )->send();
		( new WpMail( false ) )->send( new Message( [ 'a@example.test' ], 'S', '<p>Hi</p>' ) );

		$this->assertSame( 'Thanks, in words.', $GLOBALS['re_mail'][0]['alt'] );
		$this->assertSame( '', $GLOBALS['re_mail'][1]['alt'] );
	}

	public function test_why_wp_mail_failed_reaches_whoever_sent_it(): void {
		$GLOBALS['re_sent']       = false;
		$GLOBALS['re_mail_error'] = 'SMTP connect() failed.';

		$email  = $this->email();
		$result = $email->send();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'send_failed', $result->get_error_code() );
		$this->assertSame( 'SMTP connect() failed.', $result->get_error_message() );
		$this->assertSame( 'wp_mail', $result->get_error_data()['transport'] );
		$this->assertFalse( $email->result()->sent );
	}

	public function test_a_failure_with_no_reason_says_wordpress_could_not_send_it(): void {
		$GLOBALS['re_sent'] = false;

		$this->assertSame( 'WordPress could not send the email.', $this->email()->send()->get_error_message() );
	}

	// Choosing a transport ----------------------------------------------------

	public function test_everything_can_go_through_another_transport(): void {
		$memory = new Memory();
		Delivery::use( $memory );

		$email = $this->email();

		$this->assertTrue( $email->send() );
		$this->assertSame( [], $GLOBALS['re_mail'] );
		$this->assertTrue( $memory->sent_to( 'JANE@example.test' ) );
		$this->assertSame( 'memory-1', $email->result()->message_id );
		$this->assertSame( 'memory', $email->result()->transport );

		Delivery::use( null );
		$this->assertInstanceOf( WpMail::class, Delivery::transport() );
	}

	public function test_one_email_can_go_its_own_way(): void {
		$memory = new Memory();

		$this->email()->via( $memory )->send();
		$this->email()->send();

		$this->assertCount( 1, $memory->messages() );
		$this->assertCount( 1, $GLOBALS['re_mail'] );
	}

	public function test_the_transport_can_be_filtered_under_this_copys_prefix(): void {
		$memory = new Memory();

		add_filter( Runtime::hook( 'transport' ), static fn() => $memory );
		$this->email()->send();

		$this->assertCount( 1, $memory->messages() );

		// Something that is not a transport is ignored.
		re_reset_globals();
		add_filter( Runtime::hook( 'transport' ), static fn() => 'postmark' );

		$this->assertInstanceOf( WpMail::class, Delivery::transport() );
	}

	public function test_a_failed_send_says_whether_trying_again_could_help(): void {
		$email = $this->email()->via( new Memory( false, 'Down for maintenance' ) );
		$error = $email->send();

		$this->assertSame( 'Down for maintenance', $error->get_error_message() );
		$this->assertTrue( $error->get_error_data()['retryable'] );
		$this->assertSame( 500, $error->get_error_data()['status'] );
	}

	public function test_every_handover_is_announced_and_so_is_the_send(): void {
		$this->email()->via( new Memory() )->send();

		$delivered = array_values( array_filter( $GLOBALS['re_fired'], static fn( array $fired ): bool => Runtime::hook( 'delivered' ) === $fired[0] ) );
		$sent      = array_values( array_filter( $GLOBALS['re_fired'], static fn( array $fired ): bool => Runtime::hook( 'sent' ) === $fired[0] ) );

		$this->assertCount( 1, $delivered );
		$this->assertInstanceOf( Result::class, $delivered[0][1][0] );
		$this->assertInstanceOf( Message::class, $delivered[0][1][1] );
		$this->assertSame( [ true, [ 'jane@example.test' ], 'Your receipt' ], array_slice( $sent[0][1], 0, 3 ) );
	}

	// Staging -----------------------------------------------------------------

	public function test_a_redirect_sends_everything_to_one_inbox_and_nobody_else(): void {
		$memory = new Memory();
		Delivery::redirect( 'dev@example.test' );

		$this->email()->cc( 'boss@example.test' )->bcc( 'audit@example.test' )->via( $memory )->send();

		$sent = $memory->last();

		$this->assertSame( [ 'dev@example.test' ], $sent->to );
		$this->assertSame( [], $sent->cc );
		$this->assertSame( [], $sent->bcc );
		$this->assertSame( '[jane@example.test, boss@example.test, audit@example.test] Your receipt', $sent->subject );
		$this->assertSame( 'jane@example.test, boss@example.test, audit@example.test', $sent->headers['X-Original-To'] );
		$this->assertFalse( $memory->sent_to( 'jane@example.test' ) );
	}

	public function test_a_redirect_can_come_from_a_filter_and_must_be_an_address(): void {
		$memory = new Memory();

		add_filter( Runtime::hook( 'redirect' ), static fn(): string => 'not an address' );
		$this->email()->via( $memory )->send();
		$this->assertSame( [ 'jane@example.test' ], $memory->last()->to );

		re_reset_globals();
		add_filter( Runtime::hook( 'redirect' ), static fn(): string => 'staging@example.test' );
		$this->email()->via( $memory )->send();
		$this->assertSame( [ 'staging@example.test' ], $memory->last()->to );

		Delivery::redirect( '' );
	}

	public function test_a_message_with_nobody_to_go_to_is_not_handed_over(): void {
		$memory = new Memory();
		$result = Delivery::send( new Message( [], 'S', '<p>x</p>' ), $memory );

		$this->assertFalse( $result->sent );
		$this->assertSame( [], $memory->messages() );
	}

	// The message ---------------------------------------------------------------

	public function test_the_message_carries_the_people_and_the_headers_apart(): void {
		$message = $this->email()
			->from( 'shop@example.test', "The\nShop" )
			->reply_to( 'help@example.test', 'Help' )
			->cc( 'boss@example.test' )
			->bcc( 'audit@example.test' )
			->header( 'X-Order', '42' )
			->header( "X-Bad\nBcc", 'evil@example.test' )
			->message();

		$this->assertSame( 'shop@example.test', $message->from_email );
		$this->assertSame( 'The Shop', $message->from_name );
		$this->assertSame( [ 'X-Order' => '42' ], $message->headers );
		$this->assertSame(
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: The Shop <shop@example.test>',
				'Reply-To: Help <help@example.test>',
				'Cc: boss@example.test',
				'Bcc: audit@example.test',
				'X-Order: 42',
			],
			$message->lines()
		);
	}

	public function test_a_second_from_replaces_the_first(): void {
		$message = $this->email()->from( 'one@example.test' )->from( 'two@example.test', 'Two' )->message();

		$this->assertSame( [ 'Content-Type: text/html; charset=UTF-8', 'From: Two <two@example.test>' ], $message->lines() );
	}

	public function test_the_text_part_keeps_links_and_drops_the_head(): void {
		$text = Message::text_of(
			'<html><head><title>Receipt</title><style>p{color:red}</style></head><body>'
			. '<h1>Thanks &amp; welcome</h1><p>Line one<br>Line two</p>'
			. '<p><a href="https://example.test/a">https://example.test/a</a></p>'
			. '<p><a href="https://example.test/b">Your <b>files</b></a></p><!-- note --></body></html>'
		);

		$this->assertSame( "Thanks & welcome\nLine one\nLine two\nhttps://example.test/a\nYour files (https://example.test/b)", $text );
		$this->assertSame( '', Message::text_of( '   ' ) );

		// The preheader is for the inbox list; table cells keep a space between.
		$this->assertSame(
			"Item Qty\nPack 2",
			Message::text_of( '<body><div style="display:none;max-height:0">Inbox line&#847;</div><table><tr><th>Item</th><th>Qty</th></tr><tr><td>Pack</td><td>2</td></tr></table></body>' )
		);
	}

	public function test_a_changed_message_works_its_text_out_again(): void {
		$message = new Message( [ 'a@example.test' ], 'S', '<p>One</p>' );

		$this->assertSame( 'Two', $message->with( [ 'html' => '<p>Two</p>' ] )->text );
		$this->assertSame( 'Own', $message->with( [ 'html' => '<p>Two</p>', 'text' => 'Own' ] )->text );
		$this->assertSame( 'One', $message->with( [ 'subject' => 'T' ] )->text );
	}

	// What the email says ---------------------------------------------------------

	public function test_the_preheader_is_hidden_after_the_body_opens_and_takes_tags_as_words(): void {
		Tags::add( 'shop', 'name', [ 'callback' => static fn(): string => 'Tom & <Jerry>' ] );

		$html = $this->email()->tags( 'shop' )->preheader( "Hello {name}\nthere" )->html();

		$this->assertMatchesRegularExpression( '/<body[^>]*><div style="display:none;[^"]*">Hello Tom &amp; &lt;Jerry&gt; there&#847;/', $html );
	}

	public function test_no_preheader_adds_nothing(): void {
		$this->assertStringNotContainsString( 'display:none;font-size:1px', $this->email()->html() );
	}

	public function test_the_document_says_its_language_and_direction(): void {
		$this->assertStringContainsString( '<html lang="en-GB" dir="ltr">', $this->email()->html() );

		$GLOBALS['re_language'] = 'ar';
		$GLOBALS['re_rtl']      = true;

		$this->assertStringContainsString( '<html lang="ar" dir="rtl">', $this->email()->html() );

		// Something that is not a language is not written into the page.
		$GLOBALS['re_language'] = '"><script>';

		$this->assertStringContainsString( '<html>', $this->email()->html() );
	}

	public function test_a_tag_nobody_registered_is_reported_and_not_sent(): void {
		$email = $this->email()->subject( 'Hi {nobody}' )->content( '<p>{typo} and {typo}</p>' );
		$html  = $email->html();

		$this->assertSame( [ 'nobody', 'typo' ], $email->unknown() );
		$this->assertStringNotContainsString( '{typo}', $html );
	}

	// APIs ----------------------------------------------------------------------

	public function test_postmark_is_sent_the_message_as_its_api_takes_it(): void {
		$GLOBALS['re_respond'] = [ 200, '{"MessageID":"pm-1","ErrorCode":0}' ];

		$file = sys_get_temp_dir() . '/sc-emails-invoice.pdf';
		file_put_contents( $file, '%PDF' );

		$result = Delivery::send(
			( new Message( [ 'jane@example.test' ], 'Receipt', '<p>Hi</p>', '', '', '', 'help@example.test', 'Help', [], [ 'audit@example.test' ], [ 'X-Order' => '42' ], [ $file ] ) ),
			new Postmark( 'pm-token', 'Shop <shop@example.test>' )
		);

		unlink( $file );

		$request = $GLOBALS['re_http'][0];
		$body    = json_decode( $request['args']['body'], true );

		$this->assertTrue( $result->sent );
		$this->assertSame( 'pm-1', $result->message_id );
		$this->assertSame( 'postmark', $result->transport );
		$this->assertSame( 'https://api.postmarkapp.com/email', $request['url'] );
		$this->assertSame( 'pm-token', $request['args']['headers']['X-Postmark-Server-Token'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 'Shop <shop@example.test>', $body['From'] );
		$this->assertSame( 'Help <help@example.test>', $body['ReplyTo'] );
		$this->assertSame( 'audit@example.test', $body['Bcc'] );
		$this->assertSame( 'Hi', $body['TextBody'] );
		$this->assertSame( [ [ 'Name' => 'X-Order', 'Value' => '42' ] ], $body['Headers'] );
		$this->assertSame( [ 'Name' => 'sc-emails-invoice.pdf', 'Content' => base64_encode( '%PDF' ), 'ContentType' => 'application/pdf' ], $body['Attachments'][0] );
	}

	public function test_resend_is_sent_the_message_as_its_api_takes_it(): void {
		$GLOBALS['re_respond'] = [ 200, '{"id":"rs-1"}' ];

		$result = Delivery::send(
			new Message( [ 'jane@example.test' ], 'Receipt', '<p>Hi</p>', from_email: 'shop@example.test', from_name: 'Shop' ),
			new Resend( 're_key', 'fallback@example.test' )
		);

		$request = $GLOBALS['re_http'][0];
		$body    = json_decode( $request['args']['body'], true );

		$this->assertSame( 'rs-1', $result->message_id );
		$this->assertSame( 'Bearer re_key', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'Shop <shop@example.test>', $body['from'] );
		$this->assertSame( [ 'jane@example.test' ], $body['to'] );
		$this->assertArrayNotHasKey( 'cc', $body );
	}

	/**
	 * Whether a refusal is worth trying again, by what the provider said.
	 *
	 * @return array<string, array{0: mixed, 1: bool, 2: string}>
	 */
	public static function answers(): array {
		return [
			'unreachable'   => [ new WP_Error( 'http_request_failed', 'cURL error 28' ), true, 'cURL error 28' ],
			'rate limited'  => [ [ 429, '{"message":"Slow down"}' ], true, 'Slow down' ],
			'provider down' => [ [ 503, '' ], true, 'HTTP 503' ],
			'bad key'       => [ [ 401, '{"Message":"Bad token"}' ], false, 'Bad token' ],
			'refused'       => [ [ 422, '{"error":{"message":"Invalid to"}}' ], false, 'Invalid to' ],
			'list of them'  => [ [ 400, '{"message":[{"message":"One"},{"message":"Two"}]}' ], false, 'One; Two' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'answers' )]
	public function test_a_refusal_is_read_the_same_way_from_every_provider( mixed $answer, bool $retryable, string $error ): void {
		$GLOBALS['re_respond'] = $answer;

		$result = ( new Postmark( 'token' ) )->send( new Message( [ 'a@example.test' ], 'S', '<p>x</p>' ) );

		$this->assertFalse( $result->sent );
		$this->assertSame( $retryable, $result->retryable );
		$this->assertSame( $error, $result->error );
	}

	public function test_a_key_with_a_line_break_is_refused_and_not_repeated(): void {
		$result = ( new Resend( "re_key\r\nBcc: x" ) )->send( new Message( [ 'a@example.test' ], 'S', '<p>x</p>' ) );

		$this->assertFalse( $result->sent );
		$this->assertSame( [], $GLOBALS['re_http'] );
		$this->assertStringNotContainsString( 're_key', $result->error );
	}

	public function test_a_dump_of_a_transport_does_not_show_its_key(): void {
		$this->assertStringNotContainsString( 'secret-token', print_r( new Postmark( 'secret-token' ), true ) );
		$this->assertStringNotContainsString( 'secret-key', print_r( new Resend( 'secret-key' ), true ) );
	}

	// A mailer ------------------------------------------------------------------

	/**
	 * A mailer with one email about an order.
	 *
	 * @param array<string, mixed> $config Anything else.
	 *
	 * @return Mailer
	 */
	private function mailer( array $config = [] ): Mailer {
		return Mailer::register(
			'shop',
			$config + [
				'contexts' => [
					'order' => [
						'load'   => static fn( $id ) => 7 === (int) $id ? (object) [ 'email' => 'buyer@example.test' ] : null,
						'latest' => static fn(): int => 0,
					],
				],
				'emails'   => static fn( Mailer $mailer ) => $mailer->register_email( 'receipt', [ 'subject' => 'Receipt', 'body' => 'Thanks.', 'preheader' => 'Your order is in.' ] ),
			]
		);
	}

	public function test_a_mailers_emails_and_their_tests_go_through_its_transport(): void {
		$memory = new Memory();
		$mailer = $this->mailer( [ 'transport' => $memory ] );

		$this->assertTrue( $mailer->send( 'receipt', 7 ) );
		$this->assertTrue( $mailer->actions()->test( 'receipt' )( [] )['success'] );

		$this->assertSame( [], $GLOBALS['re_mail'] );
		$this->assertTrue( $memory->sent_to( 'buyer@example.test' ) );
		$this->assertTrue( $memory->sent_to( 'tester@example.test' ) );
		$this->assertStringContainsString( 'Your order is in.', $memory->messages()[0]->html );

		// The test comes from the site, as the email does.
		$this->assertSame( 'Example Shop', $memory->last()->from_name );
	}

	public function test_a_mailers_transport_can_be_a_callable_or_come_from_its_filter(): void {
		$memory = new Memory();
		$mailer = $this->mailer( [ 'transport' => static fn( Mailer $mailer ): Transport => $memory ] );

		$this->assertSame( $memory, $mailer->transport() );

		Mailer::forget();
		$other  = new Memory();
		$mailer = $this->mailer();

		$this->assertNull( $mailer->transport() );

		add_filter( 'shop_email_transport', static fn() => $other );

		$this->assertSame( $other, $mailer->transport() );
	}

	public function test_a_failed_test_says_why(): void {
		$mailer = $this->mailer( [ 'transport' => new Memory( false, 'Provider said no' ) ] );

		$this->assertSame( 'Provider said no', $mailer->actions()->test( 'receipt' )( [] )['message'] );

		Mailer::forget();
		$GLOBALS['re_sent'] = false;

		// No reason given: the mailer's own words for it.
		$this->assertSame( 'WordPress could not send the email.', $this->mailer()->actions()->test( 'receipt' )( [] )['message'] );
	}

	// Assets ------------------------------------------------------------------

	public function test_an_asset_is_found_and_nothing_outside_assets_is(): void {
		$this->assertNull( Assets::resolve( '../src/Email.php' ) );
		$this->assertNull( Assets::resolve( 'css/nothing.css' ) );
		$this->assertNull( Assets::url_of( '/nowhere/file.css' ) );

		$this->assertSame( 'https://example.test/wp-content/plugins/x/a.css', Assets::url_of( WP_CONTENT_DIR . '/plugins/x/a.css' ) );

		// Here the library sits under the site root.
		$found = Assets::resolve( 'css/look-card.css' );

		$this->assertSame( 'https://example.test/assets/css/look-card.css', $found['url'] );
		$this->assertSame( (string) filemtime( dirname( __DIR__ ) . '/assets/css/look-card.css' ), $found['version'] );
	}
}
