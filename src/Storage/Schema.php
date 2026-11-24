<?php
/**
 * The plugin's table.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Storage;

use Sattleme\WooCommerce\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * The table of the invoices, one row per attempt to pay an order: {prefix}sattleme_invoices.
 *
 * Not an order table: WooCommerce's orders (HPOS or posts) are only ever read and written through
 * its CRUD API. Times are Unix seconds; the invoice and its last status are the SDK's JSON
 * (checkout-spec SPEC.md §3).
 */
final class Schema {

	/** The schema's version: a new one runs dbDelta again. */
	public const VERSION = '1';

	/** The option that holds the version installed. */
	public const VERSION_OPTION = 'sattleme_for_woocommerce_db_version';

	/**
	 * The table's name, with the site's prefix.
	 */
	public static function table(): string {
		$wpdb = Db::wpdb();

		return $wpdb->prefix . 'sattleme_invoices';
	}

	/**
	 * Creates or updates the table when this version of it is not installed yet.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Creates or updates the table (dbDelta), then records its version.
	 */
	public static function install(): void {
		$wpdb = Db::wpdb();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		// dbDelta's format: one column per line, two spaces after PRIMARY KEY, KEY not INDEX.
		// state: creating (row stored, createInvoice under way or interrupted), retry (it failed
		// and an invoice may exist for the reference: the next attempt asks for it again),
		// failed (no invoice, for good), invoice (the SDK's invoice is stored).
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  ref varchar(64) NOT NULL,
  state varchar(20) NOT NULL,
  invoice longtext NULL,
  status longtext NULL,
  expires_at bigint(20) NULL,
  final tinyint(1) NOT NULL DEFAULT 0,
  outcome varchar(20) NOT NULL DEFAULT '',
  error varchar(64) NOT NULL DEFAULT '',
  errors int(10) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL,
  checked_at bigint(20) NULL,
  checks int(10) unsigned NOT NULL DEFAULT 0,
  next_check_at bigint(20) NULL,
  chain char(32) NOT NULL DEFAULT '',
  lease_until bigint(20) NULL,
  shredded tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY ref (ref),
  KEY order_id (order_id),
  KEY due (final,next_check_at)
) {$collate};";
		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Drops the table and forgets its version (uninstall, with WC_REMOVE_ALL_DATA).
	 */
	public static function drop(): void {
		$wpdb = Db::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The plugin's own table, uninstalled.
		$wpdb->query( (string) $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
		delete_option( self::VERSION_OPTION );
	}
}
