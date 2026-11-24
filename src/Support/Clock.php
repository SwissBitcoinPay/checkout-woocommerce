<?php
/**
 * The plugin's clock.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Now, in Unix seconds (the server's clock, synchronized: the SDK refuses invoices more than 300 s
 * off). The tests give a clock of their own.
 */
interface Clock {

	/**
	 * Now, in Unix seconds.
	 */
	public function now(): int;
}
