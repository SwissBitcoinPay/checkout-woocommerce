<?php
/**
 * What an order still owes.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * What an order still owes, in its currency: its total less what was refunded of it.
 * WooCommerce records a refund on any order, a pending one too ("Refund" in its edit screen),
 * and a refund never changes the order's total. Other payments of an order WooCommerce does not
 * have: a gift card, as WooCommerce's own extension applies one, lowers its total.
 */
final class Owed {

	/**
	 * What $order owes, a decimal string (negative or zero when refunds cover it), never rounded
	 * to the shop's price decimals: an amount in more decimals than its currency has is refused
	 * rather than changed (Prices::amount()).
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function amount( \WC_Order $order ): string {
		$owed  = self::decimal( $order->get_total( 'edit' ) );
		$scale = self::places( $owed );
		foreach ( $order->get_refunds() as $refund ) {
			$amount = self::decimal( $refund->get_amount( 'edit' ) );
			$scale  = max( $scale, self::places( $amount ) );
			$owed   = bcsub( $owed, $amount, $scale );
		}

		return $owed;
	}

	/**
	 * $value as WooCommerce stores amounts (wc_format_decimal), "0" when it is none.
	 *
	 * @param mixed $value An amount.
	 * @return numeric-string
	 */
	private static function decimal( mixed $value ): string {
		$decimal = wc_format_decimal( is_int( $value ) || is_float( $value ) || is_string( $value ) ? (string) $value : '0' );

		return is_numeric( $decimal ) ? $decimal : '0';
	}

	/**
	 * The decimal places of $decimal.
	 *
	 * @param string $decimal A decimal string.
	 */
	private static function places( string $decimal ): int {
		$point = strpos( $decimal, '.' );

		return false === $point ? 0 : strlen( $decimal ) - $point - 1;
	}
}
