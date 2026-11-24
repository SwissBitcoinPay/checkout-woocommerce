<?php
/**
 * Durations of invoices.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Payments\Durations;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * An invoice's duration never takes it, with the grace period and the time createInvoice may
 * take, past the order's deadline.
 */
final class DurationsTest extends TestCase {

	public function test_the_time_to_pay_is_asked_without_a_deadline(): void {
		self::assertSame( 900, Durations::seconds( 15, null ) );
		self::assertSame( 60, Durations::seconds( 1, null ) );
		self::assertSame( 3600, Durations::seconds( 60, null ) );
	}

	public function test_the_sdk_s_bounds_hold(): void {
		self::assertSame( 60, Durations::seconds( 0, null ) );
		self::assertSame( 3600, Durations::seconds( 61, null ) );
	}

	public function test_a_far_deadline_leaves_the_time_to_pay_as_it_is(): void {
		self::assertSame( 900, Durations::seconds( 15, 3600 ) );
		self::assertSame( 900, Durations::seconds( 15, 900 + 120 + 20 ) );
	}

	public function test_a_near_deadline_shortens_the_invoice_by_the_grace_period_and_the_call(): void {
		self::assertSame( 760, Durations::seconds( 15, 900 ) );
		self::assertSame( 60, Durations::seconds( 15, 200 ) );
	}

	public function test_too_little_time_left_makes_no_invoice(): void {
		self::assertNull( Durations::seconds( 15, 199 ) );
		self::assertNull( Durations::seconds( 15, 0 ) );
		self::assertNull( Durations::seconds( 15, -50 ) );
	}

	public function test_the_hold_wanted_covers_the_invoice_its_grace_and_the_call(): void {
		self::assertSame( 900 + 120 + 20, Durations::wanted( 15 ) );
		self::assertSame( 60 + 140, Durations::wanted( 1 ) );
		self::assertSame( 3600 + 140, Durations::wanted( 60 ) );
	}

	public function test_an_invoice_ends_in_time_when_its_grace_period_ends_by_the_deadline(): void {
		self::assertTrue( Durations::ends_in_time( 1000, null ) );
		self::assertTrue( Durations::ends_in_time( 1000, 1120 ) );
		self::assertFalse( Durations::ends_in_time( 1001, 1120 ) );
	}
}
