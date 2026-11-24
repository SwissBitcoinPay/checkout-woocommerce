<?php
/**
 * Uninstalling the plugin.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Settings\KeyStore;
use Sattleme\WooCommerce\Settings\MerchantInfo;
use Sattleme\WooCommerce\Storage\Schema;
use Sattleme\WooCommerce\Tests\Integration\Support\Queries;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;

/**
 * The settings, the key and the checks go; the merchant's records of Bitcoin payments stay,
 * unless WooCommerce's WC_REMOVE_ALL_DATA asks to remove everything.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class UninstallTest extends TestCase {

	public function test_uninstalling_removes_the_settings_and_the_checks_and_keeps_the_payments(): void {
		$this->services()->merchant_info->save( Sdk::merchant(), $this->clock()->now() );
		$this->invoice_for( $this->order() );
		$this->services()->scheduler->ensure_sweep();
		$queries = Queries::record();

		define( 'WP_UNINSTALL_PLUGIN', 'sattleme-for-woocommerce/sattleme-for-woocommerce.php' );
		require SATTLEME_TESTS_PLUGIN_DIR . '/uninstall.php';

		self::assertFalse( get_option( KeyStore::OPTION ) );
		self::assertFalse( get_option( MerchantInfo::OPTION ) );
		self::assertFalse( get_option( GatewaySettings::OPTION ) );
		self::assertFalse( as_has_scheduled_action( Scheduler::CHECK_HOOK ) );
		self::assertFalse( as_has_scheduled_action( Scheduler::SWEEP_HOOK ) );
		self::assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		self::assertSame( array(), array_filter( $queries->list, static fn ( string $query ): bool => str_starts_with( $query, 'DROP' ) ) );
	}

	public function test_with_wc_remove_all_data_the_payments_go_too(): void {
		$queries = Queries::record();

		define( 'WP_UNINSTALL_PLUGIN', 'sattleme-for-woocommerce/sattleme-for-woocommerce.php' );
		define( 'WC_REMOVE_ALL_DATA', true );
		require SATTLEME_TESTS_PLUGIN_DIR . '/uninstall.php';

		self::assertContains( 'DROP TABLE IF EXISTS `' . Schema::table() . '`', $queries->list );
		self::assertFalse( get_option( Schema::VERSION_OPTION ) );
	}
}
