<?php
/**
 * Uninstalling the plugin: its settings, its key and its scheduled checks go. Its table of
 * invoices (the merchant's records of Bitcoin payments) stays, as WooCommerce keeps its orders,
 * unless WC_REMOVE_ALL_DATA is true (WooCommerce's own switch for removing all its data).
 *
 * @package Sattleme\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sattleme_for_woocommerce_integration_key' );
delete_option( 'sattleme_for_woocommerce_merchant' );
delete_option( 'woocommerce_sattleme_settings' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'sattleme_for_woocommerce_check' );
	as_unschedule_all_actions( 'sattleme_for_woocommerce_sweep' );
}

$sattleme_for_woocommerce_db = $GLOBALS['wpdb'] ?? null;
if ( defined( 'WC_REMOVE_ALL_DATA' ) && true === WC_REMOVE_ALL_DATA && $sattleme_for_woocommerce_db instanceof wpdb ) {
	$sattleme_for_woocommerce_drop = $sattleme_for_woocommerce_db->prepare( 'DROP TABLE IF EXISTS %i', $sattleme_for_woocommerce_db->prefix . 'sattleme_invoices' );
	if ( is_string( $sattleme_for_woocommerce_drop ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table, removed on request; prepared just above.
		$sattleme_for_woocommerce_db->query( $sattleme_for_woocommerce_drop );
	}
	delete_option( 'sattleme_for_woocommerce_db_version' );
}
