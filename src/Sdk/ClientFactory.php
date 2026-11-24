<?php
/**
 * Makes the SDK's clients.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Sdk;

use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

defined( 'ABSPATH' ) || exit;

/**
 * A client of an integration key. A client keeps nothing between calls but its options: one per
 * call costs little, and a key changed in the settings is used at once.
 */
interface ClientFactory {

	/**
	 * A client of $key.
	 *
	 * @param string $key The integration key (a secret: never logged, never shown).
	 * @throws InvalidKeyException             When it is not an integration key.
	 * @throws UnsupportedEnvironmentException When PHP lacks what the SDK needs.
	 */
	public function for_key( #[\SensitiveParameter] string $key ): CheckoutClient;
}
