<?php
/**
 * Values in nested arrays.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Support;

/**
 * A value deep in nested arrays (decoded JSON, WordPress's globals), whatever their types: the
 * tests assert what they find there.
 */
final class Arrays {

	/**
	 * The value at $keys in $data, or null when one of them is missing.
	 *
	 * @param mixed      $data    Nested arrays.
	 * @param int|string ...$keys The keys, the outermost first.
	 */
	public static function at( mixed $data, int|string ...$keys ): mixed {
		foreach ( $keys as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}

		return $data;
	}
}
