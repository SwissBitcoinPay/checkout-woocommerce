<?php
/**
 * The payment method in the Cart and Checkout blocks.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The method's integration with WooCommerce's Checkout block (the default checkout), through its
 * payment method registry: a small script of the plugin, without a build (assets/js/blocks.js,
 * on WooCommerce's wc-blocks-registry and wc-settings), registers it; its title and description
 * come from here. The payment itself goes through the gateway's process_payment(), which the
 * Store API calls (WooCommerce's legacy payment processing), then to the payment page.
 */
final class PaymentMethod extends AbstractPaymentMethodType {

	public const SCRIPT_HANDLE = 'sattleme-for-woocommerce-blocks';

	/**
	 * The method's name: the gateway's id, as the script registers it.
	 *
	 * @var string
	 */
	protected $name = GatewaySettings::GATEWAY_ID;

	/**
	 * An integration.
	 *
	 * @param GatewaySettings $gateway_settings The payment method's settings.
	 */
	public function __construct( private readonly GatewaySettings $gateway_settings ) {}

	/**
	 * Nothing to do on each request: the settings are read when needed.
	 */
	public function initialize(): void {}

	/**
	 * Whether the method's script loads: when the merchant enabled the method (WooCommerce then
	 * asks the gateway whether it is available for the cart).
	 */
	public function is_active(): bool {
		return $this->gateway_settings->is_enabled();
	}

	/**
	 * The method's script, registered.
	 *
	 * @return list<string>
	 */
	public function get_payment_method_script_handles(): array {
		wp_register_script(
			self::SCRIPT_HANDLE,
			Assets::url( 'assets/js/blocks.js' ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			SATTLEME_FOR_WOOCOMMERCE_VERSION,
			true
		);

		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * What the script reads (wc.wcSettings.getSetting( 'sattleme_data' )): nothing secret.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->gateway_settings->title(),
			'description' => $this->gateway_settings->description(),
			'icon'        => Assets::url( 'assets/images/sattleme-icon.svg' ),
			'supports'    => array( 'products' ),
		);
	}
}
