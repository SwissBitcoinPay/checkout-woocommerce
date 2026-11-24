<?php
/**
 * Plugin Name: sattle.me for WooCommerce
 * Plugin URI: https://github.com/sattleme/checkout-woocommerce
 * Description: Bitcoin payments, in Lightning or on-chain, paid straight to your sattle.me wallet.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: sattle.me
 * Author URI: https://sattle.me
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: sattleme-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 11.1
 * WC tested up to: 11.3
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'SATTLEME_FOR_WOOCOMMERCE_VERSION', '1.0.0' );
define( 'SATTLEME_FOR_WOOCOMMERCE_FILE', __FILE__ );

// WordPress refuses to activate the plugin on an older PHP ("Requires PHP"): this guard keeps a
// site that moved to an older PHP afterwards from a fatal error. This file parses on any PHP 7.
if ( PHP_VERSION_ID < 80200 ) {
	return;
}

require_once __DIR__ . '/src/autoload.php';

// HPOS and the Cart & Checkout blocks: declared as WooCommerce asks, from the main file.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( \Sattleme\WooCommerce\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Sattleme\WooCommerce\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Sattleme\WooCommerce\Plugin::class, 'boot' ), 20 );
