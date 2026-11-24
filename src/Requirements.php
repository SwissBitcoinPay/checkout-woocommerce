<?php
/**
 * What the plugin needs of the server.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin needs, checked on each request (cheaply): WooCommerce 11.1 or later; a 64-bit
 * PHP with bcmath, hash, json, openssl (with the secp256k1 curve and the ChaCha20 cipher) and
 * pcre, for the SDK (which checks them again, authoritatively, when it makes a client); iconv,
 * for the QR codes; and the plugin's embedded dependencies. A WordPress plugin gets no check from
 * Composer: the merchant gets a notice instead (Admin\Notices), and the method is not offered.
 */
final class Requirements {

	public const WOOCOMMERCE_VERSION = '11.1';

	/** WooCommerce is missing, or older than WOOCOMMERCE_VERSION. */
	public const WOOCOMMERCE = 'woocommerce';

	/** The plugin's embedded dependencies are missing (a copy of the sources, not its ZIP). */
	public const VENDOR = 'vendor';

	/** PHP is 32-bit. */
	public const BITS = '64-bit';

	/** OpenSSL lacks the secp256k1 curve. */
	public const CURVE = 'secp256k1';

	/** OpenSSL lacks the ChaCha20 cipher. */
	public const CIPHER = 'chacha20';

	/** The PHP extensions needed. */
	public const EXTENSIONS = array( 'bcmath', 'hash', 'iconv', 'json', 'openssl', 'pcre' );

	/**
	 * What is missing: codes of this class, and "ext-<name>" for an extension. Empty when all is
	 * there.
	 *
	 * @return list<string>
	 */
	public static function missing(): array {
		$missing = array();
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, self::WOOCOMMERCE_VERSION, '<' ) ) {
			$missing[] = self::WOOCOMMERCE;
		}
		if ( ! class_exists( \Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Client::class ) || ! class_exists( \Sattleme\WooCommerce\Vendor\BaconQrCode\Encoder\Encoder::class ) ) {
			$missing[] = self::VENDOR;
		}
		if ( 8 !== PHP_INT_SIZE ) {
			$missing[] = self::BITS;
		}
		foreach ( self::EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = 'ext-' . $extension;
			}
		}
		if ( extension_loaded( 'openssl' ) ) {
			$curves = openssl_get_curve_names();
			if ( ! is_array( $curves ) || ! in_array( 'secp256k1', $curves, true ) ) {
				$missing[] = self::CURVE;
			}
			if ( ! in_array( 'chacha20', openssl_get_cipher_methods(), true ) ) {
				$missing[] = self::CIPHER;
			}
		}

		return $missing;
	}
}
