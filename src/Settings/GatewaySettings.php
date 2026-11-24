<?php
/**
 * The payment method's settings.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The settings WooCommerce keeps for the payment method (the option
 * woocommerce_sattleme_settings), read with their defaults. The integration key is not one of
 * them: KeyStore.
 */
class GatewaySettings {

	/** The payment method's id in WooCommerce (its settings, its hooks, the Checkout block). */
	public const GATEWAY_ID = 'sattleme';

	public const OPTION = 'woocommerce_sattleme_settings';

	/** How many minutes the customer has to pay an invoice, unless the merchant chose otherwise. */
	public const DEFAULT_MINUTES = 15;

	public const MIN_MINUTES = 1;

	public const MAX_MINUTES = 60;

	/**
	 * Whether the merchant enabled the payment method.
	 */
	public function is_enabled(): bool {
		return 'yes' === $this->value( 'enabled' );
	}

	/**
	 * The time to pay an invoice, in minutes (1 to 60).
	 */
	public function minutes(): int {
		$minutes = $this->value( 'invoice_minutes' );
		if ( ! is_numeric( $minutes ) ) {
			return self::DEFAULT_MINUTES;
		}

		return max( self::MIN_MINUTES, min( self::MAX_MINUTES, (int) $minutes ) );
	}

	/**
	 * The title at checkout: the merchant's, else "Bitcoin" in the customer's language.
	 */
	public function title(): string {
		$title = trim( $this->value( 'title' ) );

		return '' === $title ? __( 'Bitcoin', 'sattleme-for-woocommerce' ) : $title;
	}

	/**
	 * The description at checkout: the merchant's, else ours in the customer's language.
	 */
	public function description(): string {
		$description = trim( $this->value( 'description' ) );

		return '' === $description ? self::default_description() : $description;
	}

	/**
	 * The description shown when the merchant wrote none.
	 */
	public static function default_description(): string {
		return __( 'Pay with Bitcoin, in Lightning or on-chain, from your wallet: the next page shows the QR code to scan.', 'sattleme-for-woocommerce' );
	}

	/**
	 * The least order total the method is offered for (the store's currency), or null.
	 */
	public function min_total(): ?float {
		return self::total( $this->value( 'min_total' ) );
	}

	/**
	 * The largest order total the method is offered for (the store's currency), or null.
	 */
	public function max_total(): ?float {
		return self::total( $this->value( 'max_total' ) );
	}

	/**
	 * The settings as they are saved (pre_update_option_woocommerce_sattleme_settings), whichever
	 * path saves them (the settings page, WooCommerce's REST API): never with a key in them. The
	 * key is KeyStore's alone, set on the settings page once the SDK checked it.
	 *
	 * @param mixed $value The settings.
	 * @return mixed
	 */
	public static function without_key( $value ) {
		if ( is_array( $value ) && array_key_exists( 'key', $value ) ) {
			$value['key'] = '';
		}

		return $value;
	}

	/**
	 * A setting, as stored ("" when absent).
	 *
	 * @param string $name The name.
	 */
	private function value( string $name ): string {
		$settings = get_option( self::OPTION, array() );
		$value    = is_array( $settings ) ? ( $settings[ $name ] ?? '' ) : '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A total setting ("" for none) as a float, or null.
	 *
	 * @param string $value The value.
	 */
	private static function total( string $value ): ?float {
		$value = trim( $value );
		if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
			return null;
		}

		return (float) $value;
	}
}
