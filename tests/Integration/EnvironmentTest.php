<?php
/**
 * The environment the tests run in.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Sattleme\WooCommerce\Plugin;
use Sattleme\WooCommerce\Requirements;
use Sattleme\WooCommerce\Storage\Schema;
use Sattleme\WooCommerce\Support\Db;

/**
 * WordPress, WooCommerce and the plugin under test, as the run asked for them.
 */
final class EnvironmentTest extends TestCase {

	public function test_the_plugin_under_test_is_loaded(): void {
		self::assertSame( realpath( SATTLEME_TESTS_PLUGIN_DIR . '/sattleme-for-woocommerce.php' ), realpath( SATTLEME_FOR_WOOCOMMERCE_FILE ) );
		self::assertSame( 'sattleme-for-woocommerce/sattleme-for-woocommerce.php', plugin_basename( SATTLEME_FOR_WOOCOMMERCE_FILE ) );
		self::assertTrue( Plugin::is_testing() );
		self::assertSame( array(), Requirements::missing() );
	}

	public function test_orders_are_stored_as_the_run_asked(): void {
		self::assertSame( SATTLEME_TESTS_HPOS, OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_the_plugin_s_table_exists(): void {
		$wpdb = Db::wpdb();
		self::assertSame( Schema::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) ) );
		self::assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_the_versions(): void {
		self::assertNotSame( '', get_bloginfo( 'version' ) );
		self::assertTrue( defined( 'WC_VERSION' ) );
		fwrite( STDERR, sprintf( "\nWordPress %s, WooCommerce %s, PHP %s, %s, orders in %s\n", get_bloginfo( 'version' ), WC_VERSION, PHP_VERSION, Db::wpdb()->db_server_info(), SATTLEME_TESTS_HPOS ? 'HPOS tables' : 'posts' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The run's versions, for its log.
	}
}
