<?php
/**
 * Ids from outside.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Support\Input;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * An id is a positive number, as an int or digits; anything else is 0.
 */
final class InputTest extends TestCase {

	public function test_ids(): void {
		self::assertSame( 42, Input::id( 42 ) );
		self::assertSame( 42, Input::id( '42' ) );
		self::assertSame( 0, Input::id( -3 ) );
		self::assertSame( 0, Input::id( '-3' ) );
		self::assertSame( 0, Input::id( '4 2' ) );
		self::assertSame( 0, Input::id( '42abc' ) );
		self::assertSame( 0, Input::id( 4.2 ) );
		self::assertSame( 0, Input::id( null ) );
		self::assertSame( 0, Input::id( array( 42 ) ) );
		self::assertSame( 0, Input::id( "42\n" ) );
	}
}
