<?php
/**
 * What the payment page asks for news of the payment.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Page;

use Sattleme\WooCommerce\Payments\Checker;
use Sattleme\WooCommerce\Payments\Schedule;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/sattleme-for-woocommerce/v1/orders/<id>/status?key=<the order's key>: has the
 * order's invoice checked by the server (the SDK's getStatus, never what a browser says) when its
 * last check is older than a few seconds and no other check runs, and answers where its payment
 * is: {"phase", "version"} (the page reloads when the version changed), with "redirect" once paid.
 *
 * Authorized as the payment page is, by the order's key (compared in constant time); no cookie
 * is involved, so no request can be forged on someone's behalf. It changes nothing a request
 * chooses: a check finds what the SDK finds, and runs at most every WEB_INTERVAL seconds per
 * invoice, however often it is asked.
 */
class StatusRoute {

	public const NAMESPACE = 'sattleme-for-woocommerce/v1';

	public const ROUTE = '/orders/(?P<order_id>\d+)/status';

	/**
	 * A route.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 * @param Checker           $checker  The checks.
	 * @param Clock             $clock    The clock.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly Checker $checker,
		private readonly Clock $clock,
	) {}

	/**
	 * Registers the route (rest_api_init).
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'respond' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'order_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'key'      => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 100,
					),
				),
			)
		);
	}

	/**
	 * The URL of the status of $order (its key in it, as in the payment page's).
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function url( \WC_Order $order ): string {
		return add_query_arg( 'key', rawurlencode( $order->get_order_key() ), rest_url( self::NAMESPACE . '/orders/' . $order->get_id() . '/status' ) );
	}

	/**
	 * Whether the request names an order of this payment method, with its key.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function authorize( $request ) {
		$order = $this->order_of( $request );
		if ( null === $order ) {
			return new \WP_Error( 'sattleme_not_found', __( 'Unknown order.', 'sattleme-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Where the payment of the order is, its invoice checked first when due.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function respond( $request ): \WP_REST_Response {
		$order = $this->order_of( $request );
		if ( null === $order ) {
			return self::no_store( new \WP_REST_Response( array( 'code' => 'sattleme_not_found' ), 404 ) );
		}
		$invoice = $this->invoices->latest_for_order( $order->get_id() );
		if ( null !== $invoice && $invoice->has_invoice() && ! $invoice->is_final ) {
			$this->checker->check( $invoice->id, Schedule::WEB_INTERVAL, PaymentPage::WEB_TIMEOUT );
			$invoice = $this->invoices->find( $invoice->id );
			$fresh   = wc_get_order( $order->get_id() );
			$order   = $fresh instanceof \WC_Order ? $fresh : $order;
		}
		$phase  = Phase::of( $invoice, $order->get_status(), $order->is_paid(), $this->clock->now() );
		$answer = array(
			'phase'   => $phase,
			'version' => Phase::version( $invoice, $phase ),
		);
		if ( Phase::goes_to_order( $phase, $invoice ) && ! $order->needs_payment() ) {
			$answer['redirect'] = $order->get_checkout_order_received_url();
		}

		return self::no_store( new \WP_REST_Response( $answer, 200 ) );
	}

	/**
	 * The order the request names, when its key is the request's and the plugin takes its payment.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private function order_of( $request ): ?\WC_Order {
		$order_id = Input::id( $request->get_param( 'order_id' ) );
		$key      = $request->get_param( 'key' );
		if ( 0 === $order_id || ! is_string( $key ) || '' === $key ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		if ( GatewaySettings::GATEWAY_ID !== $order->get_payment_method() && null === $this->invoices->latest_for_order( $order_id ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * $response, never stored by a browser or a proxy.
	 *
	 * @param \WP_REST_Response $response The response.
	 */
	private static function no_store( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}
}
