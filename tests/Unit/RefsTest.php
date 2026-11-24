<?php
/**
 * References of attempts.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Payments\Refs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * 128 random bits per attempt, made on the server.
 */
final class RefsTest extends TestCase {

	public function test_a_reference_is_the_order_and_128_random_bits(): void {
		$ref = Refs::new_ref( 1042 );

		self::assertMatchesRegularExpression( '/^wc-1042-[0-9a-f]{32}$/D', $ref );
	}

	public function test_no_two_references_are_the_same(): void {
		$refs = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$refs[] = Refs::new_ref( 7 );
		}

		self::assertCount( 1000, array_unique( $refs ) );
	}
}
