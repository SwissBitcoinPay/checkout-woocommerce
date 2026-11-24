<?php
/**
 * WordPress's database.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's database connection ($wpdb: wpdb, or a subclass of it such as HyperDB's).
 */
final class Db {

	/**
	 * The connection.
	 *
	 * @throws \LogicException When WordPress has none.
	 */
	public static function wpdb(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \LogicException( 'WordPress has no database connection' );
		}

		return $wpdb;
	}
}
