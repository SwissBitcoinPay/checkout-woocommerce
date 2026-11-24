<?php
/**
 * The payment method.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce;

use Sattleme\WooCommerce\Payments\PaymentFailure;
use Sattleme\WooCommerce\Sdk\Prices;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Settings\KeyRejected;
use Sattleme\WooCommerce\Support\Assets;
use Sattleme\WooCommerce\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Bitcoin, with sattle.me: WooCommerce's payment gateway (WC_Payment_Gateway). The customer
 * chooses it at checkout (classic, or the Checkout block: Blocks\PaymentMethod), then pays on the
 * order's payment page (PaymentPage), in the shop's theme. The bitcoin goes straight to the
 * merchant's wallet.
 */
class Gateway extends \WC_Payment_Gateway {

	/**
	 * The payment method, as WooCommerce makes it (by its class name).
	 */
	public function __construct() {
		$this->id                 = GatewaySettings::GATEWAY_ID;
		$this->method_title       = __( 'sattle.me (Bitcoin)', 'sattleme-for-woocommerce' );
		$this->method_description = __( 'Accept Bitcoin payments, in Lightning or on-chain, paid straight to your sattle.me wallet. No sattle.me server holds your funds: the integration key makes invoices that pay you, and can never spend your bitcoin.', 'sattleme-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = Assets::url( 'assets/images/sattleme-icon.svg' );

		$this->init_form_fields();
		$this->init_settings();

		$settings          = Plugin::instance()->services()->settings;
		$this->title       = $settings->title();
		$this->description = $settings->description();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_settings' ) );
	}

	/**
	 * Saves the settings form (woocommerce_update_options_payment_gateways_sattleme): each field
	 * validated, the key checked with sattle.me (validate_key_field()).
	 */
	public function save_settings(): void {
		$this->process_admin_options();
	}

	/**
	 * The settings form.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'sattleme-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept Bitcoin payments with sattle.me', 'sattleme-for-woocommerce' ),
				'default' => 'no',
			),
			'key'             => array(
				'title'       => __( 'Integration key', 'sattleme-for-woocommerce' ),
				'type'        => 'sattleme_key',
				'description' => __( 'In the sattle.me app, create an integration for this shop and enter its secret key (sattleme_ck_…). It is checked when you save it, and never shown again. Whoever holds it can create invoices that pay you, never spend your funds: keep it secret all the same. Change it only while no Bitcoin payment is pending: those are checked with the key set here.', 'sattleme-for-woocommerce' ),
				'default'     => '',
			),
			'invoice_minutes' => array(
				'title'             => __( 'Time to pay', 'sattleme-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'How many minutes the customer has to pay a Bitcoin invoice, from 1 to 60. An invoice never outlives the order’s stock hold (WooCommerce → Settings → Products → Inventory → Hold stock).', 'sattleme-for-woocommerce' ),
				'default'           => (string) GatewaySettings::DEFAULT_MINUTES,
				'custom_attributes' => array(
					'min'  => (string) GatewaySettings::MIN_MINUTES,
					'max'  => (string) GatewaySettings::MAX_MINUTES,
					'step' => '1',
				),
			),
			'title'           => array(
				'title'       => __( 'Title', 'sattleme-for-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'The payment method’s name at checkout. Empty: “Bitcoin”, in the customer’s language.', 'sattleme-for-woocommerce' ),
				'default'     => '',
				'placeholder' => __( 'Bitcoin', 'sattleme-for-woocommerce' ),
				'desc_tip'    => false,
			),
			'description'     => array(
				'title'       => __( 'Description', 'sattleme-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'What the customer reads at checkout. Empty: the text shown here, in the customer’s language.', 'sattleme-for-woocommerce' ),
				'default'     => '',
				'placeholder' => GatewaySettings::default_description(),
			),
			'min_total'       => array(
				'title'       => __( 'Minimum order total', 'sattleme-for-woocommerce' ),
				'type'        => 'price',
				'description' => __( 'Offer Bitcoin for orders of at least this total (in the store’s currency). Empty: any.', 'sattleme-for-woocommerce' ),
				'default'     => '',
			),
			'max_total'       => array(
				'title'       => __( 'Maximum order total', 'sattleme-for-woocommerce' ),
				'type'        => 'price',
				'description' => __( 'Offer Bitcoin for orders of at most this total (in the store’s currency). Empty: any.', 'sattleme-for-woocommerce' ),
				'default'     => '',
			),
		);
	}

	/**
	 * The settings page: the logo, the connection, the fields.
	 */
	public function admin_options(): void {
		Page\PaymentPage::template(
			'admin-settings.php',
			new Admin\SettingsView(
				Assets::url( 'assets/images/sattleme-logo.svg' ),
				Plugin::instance()->services()->keys->has_key(),
				Plugin::instance()->services()->merchant_info->get(),
				get_woocommerce_currency(),
				Prices::is_supported_currency( get_woocommerce_currency() )
			)
		);
		parent::admin_options();
	}

	/**
	 * The integration key's field: a password field always empty (the key saved is never sent to
	 * the browser), saying whether one is saved.
	 *
	 * @param string       $key  The field's key.
	 * @param array<mixed> $data The field, as WooCommerce's settings API passes it.
	 */
	public function generate_sattleme_key_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		$has_key   = Plugin::instance()->services()->keys->has_key();
		$title     = is_string( $data['title'] ?? null ) ? $data['title'] : '';
		ob_start();
		Page\PaymentPage::template(
			'admin-key-field.php',
			new Admin\KeyFieldView(
				$field_key,
				$title,
				is_string( $data['description'] ?? null ) ? $data['description'] : '',
				$has_key
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * The integration key entered: saved once the SDK checked it, else refused with why. Empty:
	 * the key saved is kept. The gateway's settings never hold it (KeyStore).
	 *
	 * @param string $key   The field's key.
	 * @param mixed  $value What was entered.
	 */
	public function validate_key_field( $key, #[\SensitiveParameter] $value ): string {
		$entered = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
		if ( '' === $entered ) {
			return '';
		}
		$services = Plugin::instance()->services();
		try {
			$merchant = $services->key_verifier->verify( $entered );
		} catch ( KeyRejected $e ) {
			\WC_Admin_Settings::add_error( $e->getMessage() );

			return '';
		}
		$services->keys->save( $entered );
		$services->merchant_info->save( $merchant, $services->clock->now() );

		return '';
	}

	/**
	 * The time to pay: 1 to 60 minutes, else the previous value is kept.
	 *
	 * @param string $key   The field's key.
	 * @param mixed  $value What was entered.
	 */
	public function validate_invoice_minutes_field( $key, $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( 1 === preg_match( '/^[0-9]{1,2}$/D', $value ) && (int) $value >= GatewaySettings::MIN_MINUTES && (int) $value <= GatewaySettings::MAX_MINUTES ) {
			return (string) (int) $value;
		}
		\WC_Admin_Settings::add_error( __( 'The time to pay is a number of minutes from 1 to 60.', 'sattleme-for-woocommerce' ) );
		return $this->get_option( 'invoice_minutes', (string) GatewaySettings::DEFAULT_MINUTES );
	}

	/**
	 * Whether the method is offered: enabled, a key saved, what the SDK needs present, the store's
	 * currency one the SDK converts, a total the SDK takes as a price, within the merchant's
	 * limits.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		$services = Plugin::instance()->services();
		if ( ! $services->keys->has_key() || array() !== Requirements::missing() ) {
			return false;
		}
		$currency = get_woocommerce_currency();
		if ( ! Prices::is_supported_currency( $currency ) ) {
			return false;
		}
		$total = $this->get_order_total();
		if ( $total <= 0 ) {
			return true; // Nothing to pay: WooCommerce asks for no payment method.
		}
		// A price the SDK takes: no more decimals than the currency has.
		if ( null === Prices::amount( wc_format_decimal( $total, wc_get_price_decimals() ), $currency ) ) {
			return false;
		}
		$min = $services->settings->min_total();
		$max = $services->settings->max_total();

		return ( null === $min || $total >= $min ) && ( null === $max || $total <= $max );
	}

	/**
	 * What the customer reads when choosing the method (classic checkout): its description, and,
	 * when the order being paid has an invoice that may still be paid, that it is the one paid.
	 */
	public function payment_fields(): void {
		$description = $this->get_description();
		if ( '' !== $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
		$guards = Plugin::instance()->services()->guards;
		$live   = $guards->live_invoice_of_checkout();
		if ( null !== $live && $live->has_invoice() ) {
			echo '<p>' . esc_html( $guards->wait_message( $live ) ) . '</p>';
		}
	}

	/**
	 * The invoice of the order (the one it has that may still be paid, else a new one), then the
	 * payment page. A failure throws its message, which WooCommerce shows the customer.
	 *
	 * @param int $order_id The order.
	 * @return array{result: string, redirect: string}
	 * @throws PaymentFailure With what to tell the customer.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( Input::id( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			throw PaymentFailure::not_payable();
		}
		Plugin::instance()->services()->creator->invoice_for( $order );
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		if ( ! $order->needs_payment() ) {
			// An invoice of this attempt was paid already (the SDK's OrderPaid): the order is
			// paid, or on hold when it was paid at an earlier price of the order.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}
}
