<?php
/**
 * The head of the settings page.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What the head of the payment method's settings page shows (templates/admin-settings.php).
 */
final class SettingsView {

	/**
	 * A view.
	 *
	 * @param string                                                                                                    $logo     The logo's URL.
	 * @param bool                                                                                                      $has_key  Whether a key is saved.
	 * @param array{businessName: string, minSats: string, maxSats: string, onchainAddresses: int, checkedAt: int}|null $merchant What the key's last check found.
	 * @param string                                                                                                    $currency The store's currency.
	 * @param bool                                                                                                      $supports Whether the SDK converts it.
	 */
	public function __construct(
		public readonly string $logo,
		public readonly bool $has_key,
		public readonly ?array $merchant,
		public readonly string $currency,
		public readonly bool $supports,
	) {}
}
