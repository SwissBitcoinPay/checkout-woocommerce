<?php
/**
 * One request paying an order, in a process of its own (LockTest runs two at once): WordPress
 * and the plugin loaded as the tests load them, without installing anything again; the SDK faked
 * by a client whose createInvoice waits until another connection waits for the order's lock,
 * then records its call.
 *
 * Usage: php tests/Integration/Concurrent/pay.php <order id> <file of the calls>
 * Prints the invoice it got: {"id": …, "ref": …}.
 *
 * @package Sattleme\WooCommerce
 */

use Sattleme\WooCommerce\Plugin;
use Sattleme\WooCommerce\Services;
use Sattleme\WooCommerce\Support\Db;
use Sattleme\WooCommerce\Support\SystemClock;
use Sattleme\WooCommerce\Tests\Integration\Support\FakeCheckout;
use Sattleme\WooCommerce\Tests\Integration\Support\FakeClients;
use Sattleme\WooCommerce\Tests\Integration\Support\FakeClock;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery -- A test's process.

if ( 'cli' !== PHP_SAPI || 3 !== $argc ) {
	exit( 2 );
}
$sattleme_order_id = (int) $argv[1];
$sattleme_calls    = $argv[2];
$sattleme_root     = dirname( __DIR__, 3 );
$sattleme_env      = getenv( 'SATTLEME_TEST_ENV' );
$sattleme_env      = is_string( $sattleme_env ) && '' !== $sattleme_env ? rtrim( $sattleme_env, '/' ) : $sattleme_root . '/build/test-env/wp-7.1.5-wc-11.3.0';

require $sattleme_root . '/vendor/autoload.php';
require $sattleme_env . '/wp-tests-config.php';
require $sattleme_env . '/tests/includes/functions.php';
if ( ! isset( $table_prefix ) || ! is_string( $table_prefix ) ) {
	fwrite( STDERR, "No table prefix in the test library's wp-tests-config.php\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = WP_TESTS_DOMAIN;
$_SERVER['SERVER_NAME']    = WP_TESTS_DOMAIN;
$_SERVER['SERVER_PORT']    = '80';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$GLOBALS['table_prefix']   = $table_prefix;
define( 'SATTLEME_FOR_WOOCOMMERCE_TESTING', true );

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		wp_register_plugin_realpath( WP_PLUGIN_DIR . '/sattleme-for-woocommerce/sattleme-for-woocommerce.php' );
		require WP_PLUGIN_DIR . '/sattleme-for-woocommerce/sattleme-for-woocommerce.php';
	}
);
tests_add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
tests_add_filter(
	'sattleme_for_woocommerce_testing_services',
	static function () use ( $sattleme_calls ) {
		$checkout            = new FakeCheckout( new FakeClock() );
		$checkout->creates[] = static function ( array $params ) use ( $sattleme_calls ) {
			$ref = $params['ref'] ?? null;
			if ( ! is_string( $ref ) ) {
				throw new \LogicException( 'No reference' );
			}
			// Holding the order's lock: wait until the other request waits for it.
			$wpdb     = Db::wpdb();
			$waiting  = 0;
			$deadline = microtime( true ) + 30;
			while ( 0 === $waiting && microtime( true ) < $deadline ) {
				$waiting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE 'SELECT GET_LOCK(''sattleme-wc:%'" );
				if ( 0 === $waiting ) {
					usleep( 20000 );
				}
			}
			file_put_contents( $sattleme_calls, $ref . ' ' . $waiting . "\n", FILE_APPEND | LOCK_EX );

			return Sdk::invoice( $params, time() );
		};

		return new Services( new SystemClock(), new FakeClients( $checkout ) );
	}
);

require ABSPATH . 'wp-settings.php';

$sattleme_order = wc_get_order( $sattleme_order_id );
if ( ! $sattleme_order instanceof WC_Order ) {
	fwrite( STDERR, "No order {$sattleme_order_id}\n" );
	exit( 1 );
}
try {
	$sattleme_invoice = Plugin::instance()->services()->creator->invoice_for( $sattleme_order );
} catch ( Throwable $e ) {
	fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" );
	exit( 1 );
}
echo wp_json_encode(
	array(
		'id'  => $sattleme_invoice->id,
		'ref' => $sattleme_invoice->ref,
	)
), "\n";
