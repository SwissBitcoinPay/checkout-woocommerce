<?php
/**
 * The plugin's files, by URL.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * URLs of the plugin's own files (assets/): its scripts, its style and its images are served
 * from the shop, never from a CDN.
 */
final class Assets {

	/**
	 * The URL of the plugin's file $path.
	 *
	 * @param non-empty-string $path The file, from the plugin's folder (assets/…).
	 * @return non-empty-string
	 * @throws \LogicException When WordPress gives no URL.
	 */
	public static function url( string $path ): string {
		$url = plugins_url( $path, SATTLEME_FOR_WOOCOMMERCE_FILE );
		if ( '' === $url ) {
			throw new \LogicException( 'The plugin has no URL' );
		}

		return $url;
	}
}
