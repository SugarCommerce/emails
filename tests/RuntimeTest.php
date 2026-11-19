<?php
/**
 * Runtime name derivation tests.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Emails\Utils\Runtime;

/**
 * Routes, handles, hooks and keys come from the namespace this copy is
 * compiled under, so two plugins bundling the library never share them.
 */
final class RuntimeTest extends TestCase {

	/**
	 * A plugin that bundles the packages into its own Lib namespace is named
	 * for every segment before Lib, so two plugins of one vendor are two
	 * builds; a one-segment Lib, a prefix and an unprefixed copy keep their
	 * names.
	 */
	public function test_a_plugins_own_lib_namespace_names_the_build(): void {
		$this->assertSame( 'arraypress-edd-fraudfilter-emails', Runtime::prefix_for( 'ArrayPress\\EDD\\FraudFilter\\Lib\\Emails\\Utils' ) );
		$this->assertNotSame(
			Runtime::prefix_for( 'ArrayPress\\EDD\\FraudFilter\\Lib\\Emails\\Utils' ),
			Runtime::prefix_for( 'ArrayPress\\EDD\\SalePrices\\Lib\\Emails\\Utils' ),
			'Two plugins of one vendor share a name.'
		);
		$this->assertSame( 'playground-emails', Runtime::prefix_for( 'Playground\\Lib\\Emails\\Utils' ), 'A one-segment Lib changed its name.' );
		$this->assertSame( 'sugarcart-emails', Runtime::prefix_for( 'SugarCart\\Vendor\\SugarCommerce\\Emails\\Utils' ), 'A prefix changed its name.' );
		$this->assertSame( 'emails', Runtime::prefix_for( 'SugarCommerce\\Emails\\Utils' ), 'The unprefixed copy changed its name.' );
	}
}
