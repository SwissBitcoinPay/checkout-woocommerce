<?php
/**
 * What the key's check found.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Settings;

use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant as the integration saw it when its key was last checked (the SDK's merchant()):
 * its business's name, the sats its Lightning address takes, its deposit addresses. Nothing
 * secret: shown on the settings page.
 */
class MerchantInfo {

	public const OPTION = 'sattleme_for_woocommerce_merchant';

	/**
	 * Remembers $merchant, as checked at $now.
	 *
	 * @param Merchant $merchant The merchant.
	 * @param int      $now      The time, in Unix seconds.
	 */
	public function save( Merchant $merchant, int $now ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The SDK's Merchant.
		update_option(
			self::OPTION,
			array(
				'businessName'     => $merchant->businessName,
				'minSats'          => $merchant->minSats,
				'maxSats'          => $merchant->maxSats,
				'onchainAddresses' => $merchant->onchainAddresses,
				'checkedAt'        => $now,
			),
			false
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * What the last check found, or null.
	 *
	 * @return array{businessName: string, minSats: string, maxSats: string, onchainAddresses: int, checkedAt: int}|null
	 */
	public function get(): ?array {
		$info = get_option( self::OPTION );
		if ( ! is_array( $info ) || ! is_string( $info['businessName'] ?? null ) || ! is_string( $info['minSats'] ?? null ) || ! is_string( $info['maxSats'] ?? null ) || ! is_int( $info['onchainAddresses'] ?? null ) || ! is_int( $info['checkedAt'] ?? null ) ) {
			return null;
		}

		return array(
			'businessName'     => $info['businessName'],
			'minSats'          => $info['minSats'],
			'maxSats'          => $info['maxSats'],
			'onchainAddresses' => $info['onchainAddresses'],
			'checkedAt'        => $info['checkedAt'],
		);
	}

	/**
	 * Forgets it.
	 */
	public function delete(): void {
		delete_option( self::OPTION );
	}
}
