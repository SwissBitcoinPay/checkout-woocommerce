<?php
/**
 * The server's clock.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The server's clock: time().
 */
final class SystemClock implements Clock {

	/**
	 * Now, in Unix seconds.
	 */
	public function now(): int {
		return time();
	}
}
