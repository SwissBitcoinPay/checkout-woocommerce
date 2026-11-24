<?php
/**
 * The schedule of background checks.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Payments\Schedule;
use Sattleme\WooCommerce\Tests\Support\Records;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every minute while payable, a check just before and just after the end of the grace period,
 * then every five minutes, less often after failures.
 */
final class ScheduleTest extends TestCase {

	private const EXPIRES = 1_800_000_000;

	private const END = self::EXPIRES + 120;

	public function test_every_minute_while_payable(): void {
		$invoice = Records::row( array( 'expires_at' => self::EXPIRES ) );

		self::assertSame( 60, Schedule::next_delay( $invoice, self::EXPIRES - 600 ) );
		self::assertSame( 60, Schedule::next_delay( $invoice, self::EXPIRES ) );
	}

	public function test_a_check_just_before_the_end_of_the_grace_period(): void {
		$invoice = Records::row( array( 'expires_at' => self::EXPIRES ) );

		self::assertSame( 20, Schedule::next_delay( $invoice, self::END - 30 ) );
		self::assertSame( 6, Schedule::next_delay( $invoice, self::END - 12 ), 'never sooner than a web check would run' );
	}

	public function test_a_check_just_after_the_end_of_the_grace_period(): void {
		$invoice = Records::row( array( 'expires_at' => self::EXPIRES ) );

		self::assertSame( 15, Schedule::next_delay( $invoice, self::END - 5 ) );
		self::assertSame( 6, Schedule::next_delay( $invoice, self::END + 9 ) );
	}

	public function test_every_five_minutes_once_ended(): void {
		$invoice = Records::row( array( 'expires_at' => self::EXPIRES ) );

		self::assertSame( 300, Schedule::next_delay( $invoice, self::END + 10 ) );
		self::assertSame( 300, Schedule::next_delay( $invoice, self::END + 86400 ) );
	}

	public function test_failures_back_off_up_to_half_an_hour(): void {
		$delays = array();
		foreach ( array( 1, 2, 3, 4, 5, 6, 30 ) as $errors ) {
			$delays[] = Schedule::next_delay(
				Records::row(
					array(
						'expires_at' => self::EXPIRES,
						'errors'     => (string) $errors,
					)
				),
				self::END + 3600
			);
		}

		self::assertSame( array( 60, 120, 240, 480, 960, 1800, 1800 ), $delays );
	}

	public function test_a_background_check_counts_its_wait_from_its_action_s_start(): void {
		$invoice = Records::row( array( 'expires_at' => self::EXPIRES ) );
		$run     = self::EXPIRES - 600;

		// A check that took 20 seconds: due 55 seconds after its action's start, so that the queue
		// runner's next start, a minute after, runs it.
		self::assertSame( 35, Schedule::next_delay( $invoice, $run + 20, $run ) );
		self::assertSame( 55, Schedule::next_delay( $invoice, $run, $run ) );
		self::assertSame( 295, Schedule::next_delay( $invoice, self::END + 3600, self::END + 3600 ) );
		$failed = Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'errors'     => '2',
			)
		);
		self::assertSame( 110, Schedule::next_delay( $failed, self::END + 3605, self::END + 3600 ) );
	}

	public function test_the_checks_around_the_end_keep_their_moments(): void {
		$invoice = Records::row( array( 'expires_at' => self::EXPIRES ) );

		self::assertSame( 15, Schedule::next_delay( $invoice, self::END - 25, self::END - 30 ), 'just before the end' );
		self::assertSame( 12, Schedule::next_delay( $invoice, self::END - 2, self::END - 9 ), 'just after the end' );
		self::assertSame( 6, Schedule::next_delay( $invoice, self::END + 5, self::END - 30 ), 'never sooner than a web check would run' );
	}

	public function test_failures_never_slow_the_checks_while_payable(): void {
		$invoice = Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'errors'     => '5',
			)
		);

		self::assertSame( 60, Schedule::next_delay( $invoice, self::EXPIRES - 600 ) );
	}
}
