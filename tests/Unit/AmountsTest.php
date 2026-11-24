<?php
/**
 * Amounts in sats and in bitcoins.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Page\Amounts;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Amounts are strings of digits, never floats: converted and compared exactly.
 */
final class AmountsTest extends TestCase {

	public function test_sats_as_bitcoins(): void {
		self::assertSame( '0.00049', Amounts::btc( '49000' ) );
		self::assertSame( '1', Amounts::btc( '100000000' ) );
		self::assertSame( '21000000', Amounts::btc( '2100000000000000' ) );
		self::assertSame( '0.00000001', Amounts::btc( '1' ) );
		self::assertSame( '12.3456789', Amounts::btc( '1234567890' ) );
		self::assertSame( '0', Amounts::btc( '0' ) );
		self::assertSame( '0', Amounts::btc( '' ) );
		self::assertSame( '0', Amounts::btc( '1.5' ) );
		self::assertSame( '0', Amounts::btc( '-1' ) );
	}

	public function test_amounts_compared_exactly(): void {
		self::assertTrue( Amounts::is_less( '48999', '49000' ) );
		self::assertFalse( Amounts::is_less( '49000', '49000' ) );
		self::assertFalse( Amounts::is_less( '100000', '99999' ) );
		self::assertTrue( Amounts::is_less( '0099', '100' ) );
		self::assertTrue( Amounts::is_less( '999999999999999998', '999999999999999999' ) );
	}

	public function test_sats_grouped_by_three(): void {
		// Without WordPress's locale: a comma.
		self::assertSame( '49,000', Amounts::sats( '49000' ) );
		self::assertSame( '1,000,000', Amounts::sats( '1000000' ) );
		self::assertSame( '999', Amounts::sats( '999' ) );
		self::assertSame( '1,000', Amounts::sats( '0001000' ) );
		self::assertSame( '0', Amounts::sats( '000' ) );
		self::assertSame( '0', Amounts::sats( 'abc' ) );
	}
}
