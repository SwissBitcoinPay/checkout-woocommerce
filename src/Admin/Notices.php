<?php
/**
 * The plugin's notices in the admin.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Admin;

use Sattleme\WooCommerce\Plugin;
use Sattleme\WooCommerce\Requirements;
use Sattleme\WooCommerce\Sdk\Prices;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the shop's managers what keeps Bitcoin payments from being offered (admin_notices).
 */
final class Notices {

	/**
	 * The notices, to those who manage WooCommerce.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		foreach ( self::messages() as $message ) {
			printf( '<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s</p></div>', esc_attr( $message['type'] ), esc_html__( 'sattle.me for WooCommerce:', 'sattleme-for-woocommerce' ), esc_html( $message['text'] ) );
		}
	}

	/**
	 * What to tell: each a type (error, warning) and a text.
	 *
	 * @return list<array{type: string, text: string}>
	 */
	public static function messages(): array {
		$messages = array();
		if ( Plugin::is_testing() ) {
			$messages[] = array(
				'type' => 'warning',
				'text' => __( 'a test runs this site (the testing constant is defined): payments are not real, never use it for a shop.', 'sattleme-for-woocommerce' ),
			);
		}
		$missing = Requirements::missing();
		if ( array() !== $missing ) {
			$messages[] = array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: %s: what is missing, as a list. */
					__( 'Bitcoin payments are off: this site lacks %s.', 'sattleme-for-woocommerce' ),
					implode( ', ', array_map( array( self::class, 'describe' ), $missing ) )
				),
			);

			return $messages;
		}
		$services = Plugin::instance()->services();
		if ( $services->settings->is_enabled() && ! $services->keys->has_key() ) {
			$messages[] = array(
				'type' => 'warning',
				'text' => __( 'Bitcoin payments are not offered until you enter your integration key (WooCommerce → Settings → Payments → sattle.me).', 'sattleme-for-woocommerce' ),
			);
		}
		if ( $services->settings->is_enabled() && function_exists( 'get_woocommerce_currency' ) && ! Prices::is_supported_currency( get_woocommerce_currency() ) ) {
			$messages[] = array(
				'type' => 'warning',
				'text' => sprintf(
					/* translators: %s: the store's currency code. */
					__( 'Bitcoin payments are not offered: the store’s currency, %s, is not one sattle.me converts.', 'sattleme-for-woocommerce' ),
					get_woocommerce_currency()
				),
			);
		}

		return $messages;
	}

	/**
	 * A missing requirement, in words.
	 *
	 * @param string $code A code of Requirements::missing().
	 */
	public static function describe( string $code ): string {
		switch ( $code ) {
			case Requirements::WOOCOMMERCE:
				/* translators: %s: a WooCommerce version, such as 11.1. */
				return sprintf( __( 'WooCommerce %s or later', 'sattleme-for-woocommerce' ), Requirements::WOOCOMMERCE_VERSION );
			case Requirements::VENDOR:
				return __( 'the plugin’s embedded libraries (install the plugin from its ZIP)', 'sattleme-for-woocommerce' );
			case Requirements::BITS:
				return __( 'a 64-bit PHP', 'sattleme-for-woocommerce' );
			case Requirements::CURVE:
				return __( 'OpenSSL with the secp256k1 curve', 'sattleme-for-woocommerce' );
			case Requirements::CIPHER:
				return __( 'OpenSSL with the ChaCha20 cipher', 'sattleme-for-woocommerce' );
			default:
				/* translators: %s: the name of a PHP extension, such as bcmath. */
				return sprintf( __( 'the PHP extension %s', 'sattleme-for-woocommerce' ), substr( $code, 4 ) );
		}
	}
}
