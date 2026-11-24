<?php
/**
 * The stock an order holds.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

use Sattleme\WooCommerce\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's "Hold stock" (Settings → Products → Inventory, woocommerce_hold_stock_minutes):
 * at checkout, the stock of an order's products managing stock is held for that many minutes
 * (wc_reserve_stock_for_order(), rows of {prefix}wc_reserved_stock that expire), while the order
 * is pending; and an unpaid pending order is cancelled once it was last modified that long ago
 * (wc_cancel_unpaid_orders()).
 *
 * An invoice never outlives the order's hold: its end and the SDK's grace period come before the
 * moment its stock would be free for other customers. This class reads when that is, from the
 * database's own clock, and holds the stock again (wc_reserve_stock_for_order(), which fails
 * when it is no longer in stock) when what is left is too short. WooCommerce has no API to read
 * a hold's end: it is read from its table, the hold of this order alone.
 */
class StockHold {

	/**
	 * The stock is held again only when that gains this much at least (seconds): a hold just
	 * made cannot be made longer, and WooCommerce refuses to hold an order's stock again within
	 * the second it held it (its row would not change).
	 */
	public const MIN_GAIN = 60;

	/**
	 * How long WooCommerce holds $order's stock, in seconds, as wc_reserve_stock_for_order()
	 * reckons it (woocommerce_hold_stock_for_checkout, woocommerce_hold_stock_minutes and
	 * woocommerce_order_hold_stock_minutes): null when it holds none.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function hold_seconds( \WC_Order $order ): ?int {
		/** This filter is documented in WooCommerce's includes/wc-stock-functions.php. */
		$holding = (bool) apply_filters( 'woocommerce_hold_stock_for_checkout', wc_string_to_bool( self::option( 'woocommerce_manage_stock', 'yes' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read as WooCommerce reads it.
		if ( ! $holding ) {
			return null;
		}
		/** This filter is documented in WooCommerce's src/Checkout/Helpers/ReserveStock.php. */
		$minutes = apply_filters( 'woocommerce_order_hold_stock_minutes', (int) self::option( 'woocommerce_hold_stock_minutes', '60' ), $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read as WooCommerce reads it.
		$minutes = is_numeric( $minutes ) ? (int) $minutes : 0;

		return $minutes >= 1 ? $minutes * 60 : null;
	}

	/**
	 * Seconds left until the order's stock is free for others: null when it holds none (no
	 * product managing stock, or no hold). When fewer than $wanted are left, the stock is held
	 * again first, from now, as checkout does (when that makes the hold longer).
	 *
	 * @param \WC_Order $order  The order.
	 * @param int       $wanted Seconds wanted.
	 * @throws StockUnavailable When some of it is no longer in stock.
	 */
	public function seconds_left( \WC_Order $order, int $wanted ): ?int {
		$left = $this->left( $order->get_id() );
		if ( null !== $left && $left >= $wanted ) {
			return $left;
		}
		$hold = $this->hold_seconds( $order );
		if ( null === $hold || ( null !== $left && $left > $hold - self::MIN_GAIN ) ) {
			return $left;
		}
		try {
			wc_reserve_stock_for_order( $order );
		} catch ( \Exception $e ) {
			// ReserveStockException: not enough of a product is left.
			throw new StockUnavailable( esc_html( wp_strip_all_tags( $e->getMessage() ) ) );
		}

		return $this->left( $order->get_id() );
	}

	/**
	 * Seconds until WooCommerce may cancel the order unpaid, counted from its last change (the
	 * plugin saves the order as it makes the invoice): null when WooCommerce cancels no unpaid
	 * order. While an invoice may still be paid or confirmed, the plugin keeps the order from
	 * being cancelled all the same (Guards); this bound holds even should another plugin decide.
	 */
	public function cancellation_left(): ?int {
		$minutes = self::option( 'woocommerce_hold_stock_minutes', '60' );
		// As wc_cancel_unpaid_orders() reads them.
		if ( 'yes' !== self::option( 'woocommerce_manage_stock', '' ) || ! is_numeric( $minutes ) || (int) $minutes < 1 ) {
			return null;
		}

		return (int) $minutes * 60;
	}

	/**
	 * Holds the order's stock again from now when its hold ends within $within seconds (or
	 * ended): while an on-chain payment made in time is being confirmed, its products stay its
	 * own. Whether the stock is held (false when it could not be held again).
	 *
	 * @param \WC_Order $order  The order.
	 * @param int       $within Seconds.
	 */
	public function extend( \WC_Order $order, int $within ): bool {
		$hold = $this->hold_seconds( $order );
		if ( null === $hold || ! $order->has_status( 'pending' ) ) {
			return true;
		}
		$left = $this->left( $order->get_id() );
		if ( null !== $left && ( $left >= $within || $left > $hold - self::MIN_GAIN ) ) {
			return true;
		}
		try {
			wc_reserve_stock_for_order( $order );
		} catch ( \Exception ) {
			return false;
		}

		return true;
	}

	/**
	 * Seconds left of the order's hold (the earliest of its rows that did not expire), by the
	 * database's clock: null without one. A row that expired holds nothing.
	 *
	 * @param int $order_id The order.
	 */
	private function left( int $order_id ): ?int {
		$wpdb = Db::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce's hold, as it stands now.
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(`expires`)) FROM %i WHERE order_id = %d AND `expires` > NOW()', $wpdb->prefix . 'wc_reserved_stock', $order_id ) );

		return null === $left || ! is_numeric( $left ) ? null : (int) $left;
	}

	/**
	 * An option of WooCommerce, as a string.
	 *
	 * @param string $name     The option.
	 * @param string $fallback What it is when unset.
	 */
	private static function option( string $name, string $fallback ): string {
		$value = get_option( $name, $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}
}
