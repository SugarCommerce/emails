<?php
/**
 * Address tests.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Emails\Models\Address;

/**
 * An address and its name: read the way people write them, written the way
 * a header needs them.
 */
final class AddressTest extends TestCase {

	/**
	 * What people write, and what it is.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function written(): array {
		return [
			'an address'                => [ 'jane@example.test', 'jane@example.test', '' ],
			'a name and an address'     => [ 'Jane Doe <jane@example.test>', 'jane@example.test', 'Jane Doe' ],
			'a quoted name with comma'  => [ '"Acme, Inc." <shop@example.test>', 'shop@example.test', 'Acme, Inc.' ],
			'escaped quotes in a name'  => [ '"Bob \"The\" Builder" <bob@example.test>', 'bob@example.test', 'Bob "The" Builder' ],
			'brackets and nothing else' => [ '<jane@example.test>', 'jane@example.test', '' ],
			'space around it'           => [ "  Jane\t<jane@example.test>  ", 'jane@example.test', 'Jane' ],
			'beyond ASCII'              => [ 'Zoë Ørsted <zoe@example.test>', 'zoe@example.test', 'Zoë Ørsted' ],
		];
	}

	/**
	 * Each way of writing one is read.
	 *
	 * @param string $written What was written.
	 * @param string $email   The address in it.
	 * @param string $name    The name in it.
	 */
	#[DataProvider( 'written' )]
	public function test_what_people_write_is_read( string $written, string $email, string $name ): void {
		$address = Address::parse( $written );

		$this->assertSame( $email, $address->email );
		$this->assertSame( $name, $address->name );
		$this->assertSame( $email, Address::email_of( $written ) );
	}

	/**
	 * Written back as a header and read again, it is the same address.
	 *
	 * @param string $written What was written.
	 * @param string $email   The address in it.
	 * @param string $name    The name in it.
	 */
	#[DataProvider( 'written' )]
	public function test_a_header_reads_back_the_same( string $written, string $email, string $name ): void {
		$again = Address::parse( Address::parse( $written )->header() );

		$this->assertSame( $email, $again->email );
		$this->assertSame( $name, $again->name );
	}

	/**
	 * A comma in a name is quoted, or an API reads two senders.
	 */
	public function test_a_name_is_quoted_and_what_the_quotes_need_escaped(): void {
		$this->assertSame( '"Acme, Inc." <shop@example.test>', ( new Address( 'shop@example.test', 'Acme, Inc.' ) )->header() );
		$this->assertSame( '"Bob \"The\" Builder" <b@example.test>', ( new Address( 'b@example.test', 'Bob "The" Builder' ) )->header() );
		$this->assertSame( '"Back\\\\slash" <b@example.test>', ( new Address( 'b@example.test', 'Back\\slash' ) )->header() );
		$this->assertSame( 'shop@example.test', ( new Address( 'shop@example.test' ) )->header() );
		$this->assertSame( '"Acme, Inc." <shop@example.test>', (string) new Address( 'shop@example.test', 'Acme, Inc.' ) );
	}

	/**
	 * wp_mail() reads its own lines: no quotes, and no comma where it splits.
	 */
	public function test_wp_mail_is_given_a_name_it_can_read(): void {
		$address = new Address( 'shop@example.test', '"Acme, Inc."' );

		$this->assertSame( 'Acme, Inc. <shop@example.test>', $address->for_wp_mail( false ), 'From is not split at commas.' );
		$this->assertSame( 'Acme Inc. <shop@example.test>', $address->for_wp_mail(), 'Reply-To is.' );
		$this->assertSame( 'shop@example.test', ( new Address( 'shop@example.test' ) )->for_wp_mail() );
	}

	/**
	 * Not an address, or a header somebody else would get to write.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused(): array {
		return [
			'empty'                  => [ '' ],
			'words'                  => [ 'not an address' ],
			'a name and no address'  => [ 'Jane Doe <>' ],
			'a line break'           => [ "jane@example.test\r\nBcc: x@evil.test" ],
			'a line break in a name' => [ "Jane\nBcc: x@evil.test <jane@example.test>" ],
			'a NUL'                  => [ "jane\0@example.test" ],
		];
	}

	/**
	 * Refused, not cleaned.
	 *
	 * @param string $written What was written.
	 */
	#[DataProvider( 'refused' )]
	public function test_what_is_not_an_address_is_refused( string $written ): void {
		$this->assertNull( Address::try_parse( $written ) );
		$this->assertSame( '', Address::email_of( $written ) );

		$this->expectException( InvalidArgumentException::class );

		Address::parse( $written );
	}

	/**
	 * A tab is space in a name; other control characters are not.
	 */
	public function test_a_tab_is_space_in_a_name_and_nothing_else(): void {
		$this->assertSame( "Jane\tDoe", ( new Address( 'jane@example.test', "Jane\tDoe" ) )->name );

		$this->expectException( InvalidArgumentException::class );

		new Address( 'jane@example.test', "Jane\x0BDoe" );
	}

	/**
	 * A list as somebody types it into a box: a comma inside quotes is part
	 * of a name, and what is not an address is left out.
	 */
	public function test_a_typed_list_is_split_where_the_addresses_end(): void {
		$list = Address::list( '"Accounts, Ltd" <accounts@example.test>, boss@example.test; Jane <jane@example.test>, not an address,' );

		$this->assertSame( [ 'accounts@example.test', 'boss@example.test', 'jane@example.test' ], array_map( static fn( Address $a ): string => $a->email, $list ) );
		$this->assertSame( 'Accounts, Ltd', $list[0]->name );
		$this->assertSame( [], Address::list( '' ) );
		$this->assertCount( 3, Address::list( [ 'a@example.test, b@example.test', 'c@example.test' ] ) );
	}

	/**
	 * Whatever shape it came in.
	 */
	public function test_an_address_from_any_shape(): void {
		$address = new Address( 'jane@example.test', 'Jane' );

		$this->assertSame( $address, Address::from( $address ) );
		$this->assertEquals( $address, Address::from( [ 'jane@example.test', 'Jane' ] ) );
		$this->assertEquals( $address, Address::from( 'Jane <jane@example.test>' ) );
		$this->assertSame( 'example.test', $address->domain() );
	}
}
