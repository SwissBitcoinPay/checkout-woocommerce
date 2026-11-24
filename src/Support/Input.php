<?php
/**
 * Values that come from outside the plugin.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validation of what a request, a hook or a stored action gives the plugin.
 */
final class Input {

	/**
	 * $value as an id (an order's, an invoice's): a positive number, given as an int or as a
	 * string of digits, else 0.
	 *
	 * @param mixed $value The value.
	 */
	public static function id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/D', $value ) ) {
			return (int) $value;
		}

		return 0;
	}
}
