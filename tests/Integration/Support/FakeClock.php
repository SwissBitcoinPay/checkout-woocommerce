<?php
/**
 * A clock the tests set.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use Sattleme\WooCommerce\Support\Clock;

/**
 * The plugin's time: the real time unless a test moves it (the database's clock, which
 * WooCommerce's stock holds use, never moves).
 */
final class FakeClock implements Clock {

	/**
	 * Unix seconds.
	 *
	 * @var int
	 */
	public int $now;

	/**
	 * A clock at the real time.
	 */
	public function __construct() {
		$this->now = time();
	}

	/**
	 * Now.
	 */
	public function now(): int {
		return $this->now;
	}

	/**
	 * Moves the clock $seconds on.
	 *
	 * @param int $seconds Seconds.
	 */
	public function advance( int $seconds ): void {
		$this->now += $seconds;
	}
}
