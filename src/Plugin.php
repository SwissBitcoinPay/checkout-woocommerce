<?php
/**
 * The plugin.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce;

use Sattleme\WooCommerce\Admin\Notices;
use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Blocks\PaymentMethod;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Starts the plugin (plugins_loaded) and wires its parts to WordPress's and WooCommerce's hooks.
 */
final class Plugin {

	public const TEXT_DOMAIN = 'sattleme-for-woocommerce';

	/**
	 * The constant that lets tests give the plugin a clock and SDK clients of their own (the
	 * filter TESTING_FILTER). Never defined by the plugin: only an end-to-end test's wp-config.php
	 * or a test suite defines it, and the admin then shows a notice on every page.
	 */
	public const TESTING_CONSTANT = 'SATTLEME_FOR_WOOCOMMERCE_TESTING';

	public const TESTING_FILTER = 'sattleme_for_woocommerce_testing_services';

	/**
	 * The plugin, once booted.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The plugin, with its parts.
	 *
	 * @param Services $services The parts.
	 */
	private function __construct( private Services $services ) {}

	/**
	 * Starts the plugin (plugins_loaded, after WooCommerce): its parts, its notices, its hooks.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self( self::make_services() );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );
		$missing = Requirements::missing();
		if ( in_array( Requirements::WOOCOMMERCE, $missing, true ) || in_array( Requirements::VENDOR, $missing, true ) ) {
			return; // Nothing can run: the notice says why.
		}
		self::$instance->register();
	}

	/**
	 * The plugin (boot() made it).
	 *
	 * @throws \LogicException Before boot().
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			throw new \LogicException( 'sattle.me for WooCommerce is not booted' );
		}

		return self::$instance;
	}

	/**
	 * Its parts.
	 */
	public function services(): Services {
		return $this->services;
	}

	/**
	 * Whether a test gave the plugin parts of its own (the testing constant is defined).
	 */
	public static function is_testing(): bool {
		return defined( self::TESTING_CONSTANT ) && true === constant( self::TESTING_CONSTANT );
	}

	/**
	 * Activation: the table, now.
	 */
	public static function activate(): void {
		Schema::install();
	}

	/**
	 * Deactivation: no more background checks (the sweep restarts them once active again).
	 */
	public static function deactivate(): void {
		Scheduler::unschedule_all();
	}

	/**
	 * The production parts, or a test's (the testing constant alone lets the filter replace them).
	 */
	private static function make_services(): Services {
		$services = Services::production();
		if ( ! self::is_testing() ) {
			return $services;
		}
		$replaced = apply_filters( 'sattleme_for_woocommerce_testing_services', $services );

		return $replaced instanceof Services ? $replaced : $services;
	}

	/**
	 * Wires the parts to the hooks.
	 */
	private function register(): void {
		$s = $this->services;

		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_block_method' ) );
		add_filter( 'woocommerce_rest_prepare_payment_gateway', array( $this, 'hide_key_in_rest' ), 10, 2 );
		add_filter( 'pre_update_option_' . GatewaySettings::OPTION, array( GatewaySettings::class, 'without_key' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SATTLEME_FOR_WOOCOMMERCE_FILE ), array( $this, 'action_links' ) );

		// The payment page and its status.
		add_action( 'woocommerce_receipt_' . GatewaySettings::GATEWAY_ID, array( $s->page, 'render' ) );
		add_action( 'woocommerce_thankyou_' . GatewaySettings::GATEWAY_ID, array( $s->page, 'order_notice' ) );
		add_action( 'woocommerce_view_order', array( $s->page, 'order_notice' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( $s->page, 'email_notice' ), 10, 4 );
		add_action( 'template_redirect', array( $s->page, 'before_page' ) );
		add_action( 'wp_enqueue_scripts', array( $s->page, 'enqueue' ) );
		add_filter( 'wp_headers', array( $s->page, 'headers' ) );
		add_filter( 'wp_robots', array( $s->page, 'robots' ) );
		add_action( 'rest_api_init', array( $s->status_route, 'register' ) );

		// One payable invoice per order; no cancellation while it may be paid or confirmed, nor
		// once bitcoin came; no "Pay" button that leads to no payment.
		add_filter( 'woocommerce_cancel_unpaid_order', array( $s->guards, 'may_cancel_unpaid' ), 100, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( $s->guards, 'statuses_for_cancel' ), 100, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $s->guards, 'account_actions' ), 100, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $s->guards, 'available_gateways' ), 100 );
		add_filter( 'rest_request_before_callbacks', array( $s->guards, 'remember_rest_order' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $s->guards, 'forget_rest_order' ) );
		add_action( 'before_woocommerce_pay_form', array( $s->guards, 'pay_form_notice' ) );

		// The background checks.
		add_action( 'action_scheduler_init', array( $s->scheduler, 'ensure_sweep' ) );
		add_action( Scheduler::CHECK_HOOK, array( $s->scheduler, 'run_chain' ), 10, 2 );
		add_action( Scheduler::SWEEP_HOOK, array( $s->scheduler, 'sweep' ) );

		// The merchant's view of a payment.
		add_action( 'add_meta_boxes', array( $s->order_box, 'register' ) );

		// Personal data.
		add_filter( 'wp_privacy_personal_data_exporters', array( $s->privacy, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $s->privacy, 'register_eraser' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $s->privacy, 'order_erased' ) );
		add_action( 'woocommerce_before_delete_order', array( $s->privacy, 'order_deleted' ) );
		add_action( 'before_delete_post', array( $s->privacy, 'post_deleted' ), 10, 2 );
		add_action( 'admin_init', array( $s->privacy, 'policy_content' ) );
	}

	/**
	 * At init: the translations, and the table when this version of it is not installed yet.
	 *
	 * The translations WordPress.org's language packs install come first: WordPress looks for
	 * them before the plugin's own languages/ folder, which load_plugin_textdomain() only adds as
	 * the fallback (WP_Textdomain_Registry, in WordPress 7.0 and 7.1). Plugin Check warns about it
	 * for a plugin of the directory; without it, the bundled translations would stay unused until
	 * each language has its pack.
	 */
	public function init(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( SATTLEME_FOR_WOOCOMMERCE_FILE ) ) . '/languages' );
		Schema::maybe_install();
	}

	/**
	 * Adds the payment method to WooCommerce's (woocommerce_payment_gateways).
	 *
	 * @param array<int|string, mixed>|mixed $gateways The gateways, as classes or objects.
	 * @return array<int|string, mixed>
	 */
	public function add_gateway( $gateways ): array {
		$gateways   = is_array( $gateways ) ? $gateways : array();
		$gateways[] = Gateway::class;

		return $gateways;
	}

	/**
	 * Registers the payment method with the Cart and Checkout blocks.
	 *
	 * @param mixed $registry The registry.
	 */
	public function register_block_method( $registry ): void {
		if ( $registry instanceof \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry ) {
			$registry->register( new PaymentMethod( $this->services->settings ) );
		}
	}

	/**
	 * WooCommerce's REST API returns a gateway's settings to administrators: the key never is one
	 * (KeyStore), and whatever a request wrote in its place is not returned either.
	 *
	 * @param \WP_REST_Response|mixed   $response The response.
	 * @param \WC_Payment_Gateway|mixed $gateway  The gateway.
	 * @return \WP_REST_Response|mixed
	 */
	public function hide_key_in_rest( $response, $gateway ) {
		if ( ! $response instanceof \WP_REST_Response || ! $gateway instanceof \WC_Payment_Gateway || GatewaySettings::GATEWAY_ID !== $gateway->id ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['settings'] ) && is_array( $data['settings'] ) && isset( $data['settings']['key'] ) && is_array( $data['settings']['key'] ) ) {
			$data['settings']['key']['value'] = '';
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * A "Settings" link on the Plugins screen.
	 *
	 * @param mixed $links The plugin's links.
	 * @return array<mixed>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		$url   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . GatewaySettings::GATEWAY_ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sattleme-for-woocommerce' ) . '</a>' );

		return $links;
	}
}
