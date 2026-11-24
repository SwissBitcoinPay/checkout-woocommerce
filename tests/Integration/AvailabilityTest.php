<?php
/**
 * When the method is offered.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Blocks\Registry\Container;
use Sattleme\WooCommerce\Blocks\PaymentMethod;
use Sattleme\WooCommerce\Settings\GatewaySettings;

/**
 * Bitcoin is offered when enabled, with a key, in a currency of the SDK's table, for a total the
 * SDK takes as a price, within the merchant's minimum and maximum; in the classic checkout and in
 * the Checkout block alike.
 */
final class AvailabilityTest extends TestCase {

	public function test_offered_for_an_order_it_can_take(): void {
		self::assertTrue( $this->available_for( $this->order() ) );
	}

	public function test_not_offered_when_disabled(): void {
		$this->settings( array( 'enabled' => 'no' ) );

		self::assertFalse( $this->available_for( $this->order() ) );
	}

	public function test_not_offered_without_a_key(): void {
		$this->services()->keys->delete();

		self::assertFalse( $this->available_for( $this->order() ) );
	}

	public function test_not_offered_in_a_currency_the_sdk_does_not_convert(): void {
		update_option( 'woocommerce_currency', 'BTC' ); // WooCommerce lists it; the SDK converts no price in it.

		self::assertFalse( $this->available_for( $this->order() ) );
	}

	public function test_not_offered_for_a_total_the_currency_cannot_hold(): void {
		update_option( 'woocommerce_currency', 'JPY' );

		self::assertFalse( $this->available_for( $this->order( $this->product( '1500.50' ) ) ) );
		self::assertTrue( $this->available_for( $this->order( $this->product( '1500' ) ) ) );
	}

	public function test_offered_within_the_merchant_s_limits(): void {
		$this->settings(
			array(
				'min_total' => '10',
				'max_total' => '100',
			)
		);

		self::assertTrue( $this->available_for( $this->order( $this->product( '10.00' ) ) ) );
		self::assertTrue( $this->available_for( $this->order( $this->product( '100.00' ) ) ) );
		self::assertFalse( $this->available_for( $this->order( $this->product( '9.99' ) ) ) );
		self::assertFalse( $this->available_for( $this->order( $this->product( '100.01' ) ) ) );
	}

	public function test_the_checkout_block_has_the_method(): void {
		$container = Package::container();
		self::assertInstanceOf( Container::class, $container );
		$registry = $container->get( PaymentMethodRegistry::class );
		self::assertInstanceOf( PaymentMethodRegistry::class, $registry );
		$method = $registry->get_registered( 'sattleme' );

		self::assertInstanceOf( PaymentMethod::class, $method );
		self::assertTrue( $method->is_active() );
		self::assertSame( array( PaymentMethod::SCRIPT_HANDLE ), $method->get_payment_method_script_handles() );
		$script = wp_scripts()->query( PaymentMethod::SCRIPT_HANDLE );
		self::assertInstanceOf( \_WP_Dependency::class, $script );
		self::assertSame( plugins_url( 'assets/js/blocks.js', SATTLEME_FOR_WOOCOMMERCE_FILE ), $script->src );
		self::assertSame( array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), $script->deps );
		self::assertSame(
			array(
				'title'       => 'Bitcoin',
				'description' => GatewaySettings::default_description(),
				'icon'        => plugins_url( 'assets/images/sattleme-icon.svg', SATTLEME_FOR_WOOCOMMERCE_FILE ),
				'supports'    => array( 'products' ),
			),
			$method->get_payment_method_data()
		);
		self::assertStringNotContainsString( '17wserpSaW', (string) wp_json_encode( $method->get_payment_method_data() ) );
	}

	public function test_the_checkout_block_s_script_needs_no_build(): void {
		$script = (string) file_get_contents( SATTLEME_TESTS_PLUGIN_DIR . '/assets/js/blocks.js' );

		self::assertStringContainsString( "registry.registerPaymentMethod( {\n\t\tname: 'sattleme',", $script );
		self::assertStringNotContainsString( 'import ', $script );
		self::assertStringNotContainsString( 'require(', $script );
		self::assertStringNotContainsString( '<', preg_replace( '#/\*.*?\*/#s', '', $script ) ?? '', 'no JSX' );
	}

	public function test_the_checkout_block_offers_it_once_enabled_only(): void {
		$this->settings( array( 'enabled' => 'no' ) );

		$method = new PaymentMethod( $this->services()->settings );

		self::assertFalse( $method->is_active() );
	}

	/**
	 * Whether the method is offered to pay $order (its pay page).
	 *
	 * @param \WC_Order $order The order.
	 */
	private function available_for( \WC_Order $order ): bool {
		set_query_var( 'order-pay', $order->get_id() );

		return $this->gateway()->is_available();
	}

	/**
	 * Changes the method's settings.
	 *
	 * @param array<string, string> $settings The settings changed.
	 */
	private function settings( array $settings ): void {
		$current = get_option( GatewaySettings::OPTION );
		update_option( GatewaySettings::OPTION, array_merge( is_array( $current ) ? $current : array(), $settings ) );
	}
}
