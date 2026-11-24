<?php
/**
 * The plugin's notes on an order, each written once.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

use Sattleme\WooCommerce\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * A note of the plugin on an order (a WooCommerce order note, for the merchant), written once per
 * key: the key goes in the note's own meta (add_order_note() stores it with the note), and a note
 * whose key the order has already is not written again. So a check that stopped half-way (a
 * plugin or an email failing as WooCommerce completes the order) is done again by a later check
 * without a second note, and the note is written before the order's status changes: never lost
 * when what follows fails.
 */
final class Notes {

	/** The note's meta holding its key. */
	public const META = '_sattleme_note';

	/**
	 * Writes $note on $order, unless a note of $key is there already.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $key   What the note is of: an invoice's reference and the event.
	 * @param string    $note  The note.
	 */
	public static function once( \WC_Order $order, string $key, string $note ): void {
		if ( self::has( $order->get_id(), $key ) ) {
			return;
		}
		$order->add_order_note(
			$note,
			0,
			false,
			array(
				'note_group' => 'payment',
				self::META   => $key,
			)
		);
	}

	/**
	 * Whether order $order_id has a note of $key.
	 *
	 * @param int    $order_id The order.
	 * @param string $key      The note's key.
	 */
	public static function has( int $order_id, string $key ): bool {
		$wpdb = Db::wpdb();

		// The order's notes are comments of the order (with HPOS too); WooCommerce hides them from
		// comment queries, so they are read here directly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Never cached: a check must see the note an earlier one wrote.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT c.comment_ID FROM %i c INNER JOIN %i m ON m.comment_id = c.comment_ID WHERE c.comment_post_ID = %d AND c.comment_type = %s AND m.meta_key = %s AND m.meta_value = %s LIMIT 1',
				$wpdb->comments,
				$wpdb->commentmeta,
				$order_id,
				'order_note',
				self::META,
				$key
			)
		);

		return null !== $found;
	}
}
