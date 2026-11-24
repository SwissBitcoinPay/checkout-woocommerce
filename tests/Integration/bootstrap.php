<?php
/**
 * The integration tests: WordPress's PHPUnit test library, WordPress and WooCommerce at the
 * versions of the environment tools/tests/wordpress.sh made, a MySQL or MariaDB server
 * (tools/tests/mariadb.sh), and the plugin, its SDK faked at its seam (Sdk\ClientFactory) by
 * the testing filter, which only the testing constant lets replace its parts.
 *
 * Environment:
 * - SATTLEME_TEST_ENV: the environment's directory (default: build/test-env/wp-7.1.5-wc-11.3.0);
 * - SATTLEME_PLUGIN_DIR: the plugin under test (default: this repository; the built archive's
 *   files, extracted, to test what is shipped);
 * - SATTLEME_HPOS: "no" to store orders as posts (default: WooCommerce's order tables, HPOS);
 * - WP_TESTS_DB_HOST, WP_TESTS_DB_NAME, WP_TESTS_DB_USER, WP_TESTS_DB_PASSWORD: the database.
 *
 * @package Sattleme\WooCommerce
 */

use Sattleme\WooCommerce\Tests\Integration\Support\Fakes;

$sattleme_root = dirname( __DIR__, 2 );
$sattleme_env  = getenv( 'SATTLEME_TEST_ENV' );
$sattleme_env  = is_string( $sattleme_env ) && '' !== $sattleme_env ? rtrim( $sattleme_env, '/' ) : $sattleme_root . '/build/test-env/wp-7.1.5-wc-11.3.0';
$sattleme_dir  = getenv( 'SATTLEME_PLUGIN_DIR' );
$sattleme_dir  = realpath( is_string( $sattleme_dir ) && '' !== $sattleme_dir ? $sattleme_dir : $sattleme_root );
if ( ! is_file( $sattleme_env . '/wp-tests-config.php' ) || false === $sattleme_dir ) {
	fwrite( STDERR, "No test environment: run sh tools/tests/wordpress.sh <WordPress> <WooCommerce> first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The test runner's output.
	exit( 1 );
}

define( 'SATTLEME_TESTS_ENV', $sattleme_env );
define( 'SATTLEME_TESTS_PLUGIN_DIR', $sattleme_dir );
define( 'SATTLEME_TESTS_HPOS', 'no' !== getenv( 'SATTLEME_HPOS' ) );
define( 'WP_TESTS_CONFIG_FILE_PATH', $sattleme_env . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $sattleme_root . '/vendor/yoast/phpunit-polyfills' );
// The plugin takes its parts from the testing filter (Plugin::make_services()).
define( 'SATTLEME_FOR_WOOCOMMERCE_TESTING', true );

require $sattleme_root . '/vendor/autoload.php';
require $sattleme_env . '/tests/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		// The plugin, in WordPress's plugins directory as a site has it (its URLs, its
		// translations): a link to the plugin under test.
		$link = WP_PLUGIN_DIR . '/sattleme-for-woocommerce';
		if ( realpath( $link ) !== SATTLEME_TESTS_PLUGIN_DIR ) {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			symlink( SATTLEME_TESTS_PLUGIN_DIR, $link );
		}
		wp_register_plugin_realpath( $link . '/sattleme-for-woocommerce.php' );
		require $link . '/sattleme-for-woocommerce.php';
	}
);

tests_add_filter( 'sattleme_for_woocommerce_testing_services', array( Fakes::class, 'services' ) );

// The tests print before WooCommerce's session would set its cookie: no cookie.
tests_add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

tests_add_filter(
	'setup_theme',
	static function () {
		// WooCommerce's tables and options, as WooCommerce's own tests install them; the order
		// storage the run asked for. WooCommerce unschedules actions as these options change,
		// before Action Scheduler's store runs at this hook: not a fault.
		$quiet = static fn ( $trigger, $function_name ) => 'as_unschedule_all_actions' === $function_name ? false : $trigger;
		add_filter( 'doing_it_wrong_trigger_error', $quiet, 10, 2 );
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Capabilities read again after the install (core ticket 28374).
		wp_roles();
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', SATTLEME_TESTS_HPOS ? 'yes' : 'no' );
		remove_filter( 'doing_it_wrong_trigger_error', $quiet, 10 );
		// The plugin, activated.
		\Sattleme\WooCommerce\Plugin::activate();
	}
);

require $sattleme_env . '/tests/includes/bootstrap.php';

// Installed once: the tests run in processes of their own (UninstallTest) use it as it is.
putenv( 'WP_TESTS_SKIP_INSTALL=1' );

unset( $sattleme_root, $sattleme_env, $sattleme_dir );
