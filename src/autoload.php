<?php
/**
 * The plugin's autoloaders: its own classes (Sattleme\WooCommerce\, in src/), and its
 * dependencies, prefixed by Strauss (Sattleme\WooCommerce\Vendor\, in vendor-prefixed/): the
 * sattle.me SDK and the QR code library. Composer's own autoloader is never part of the plugin.
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Sattleme\\WooCommerce\\';
		if ( ! str_starts_with( $class_name, $prefix ) || str_starts_with( $class_name, $prefix . 'Vendor\\' ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

$sattleme_for_woocommerce_vendor = dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
if ( is_file( $sattleme_for_woocommerce_vendor ) ) {
	require_once $sattleme_for_woocommerce_vendor;
}
unset( $sattleme_for_woocommerce_vendor );
