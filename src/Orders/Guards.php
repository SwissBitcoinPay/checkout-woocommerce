<?php
/**
 * Keeps an order with a live invoice from being cancelled or paid twice.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Dates;
use Sattleme\WooCommerce\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * A Lightning invoice cannot be revoked, and a payment of its last seconds, or an on-chain one
 * dated in time, is known only from a check made after its end:
 *
 * - while an order's invoice may still be paid or confirmed (its final status unknown, whatever
 *   the time), and once bitcoin came for it (paid, an underpayment that ended with something
 *   received: the sats are in the merchant's wallet, the merchant settles), neither WooCommerce's
 *   "Hold stock" (woocommerce_cancel_unpaid_order) nor the customer (the "Cancel" button of their
 *   account, and WooCommerce's handler of its link: woocommerce_valid_order_statuses_for_cancel)
 *   cancels the order;
 * - WooCommerce's "Pay" button of the customer's account leads to the order's payment page only
 *   while its invoice can be paid (woocommerce_my_account_my_orders_actions): never once a payment
 *   is being confirmed or came, nor after the invoice's time is over;
 * - until it ended unpaid (final, expired, nothing received), the customer cannot pay the order
 *   with another method (woocommerce_available_payment_gateways): on its "pay for order" page,
 *   through the Store API's checkout of an existing order, or by checking out the same cart
 *   again (WooCommerce then pays the same pending order). One that ended underpaid keeps it from
 *   them too: the merchant settles it by hand.
 */
class Guards {

	/**
	 * The order of the Store API's "checkout an existing order" request under way, if any.
	 *
	 * @var int
	 */
	private int $rest_order_id = 0;

	/**
	 * Guards.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 * @param Clock             $clock    The clock.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly Clock $clock,
	) {}

	/**
	 * Whether WooCommerce may cancel the unpaid $order (woocommerce_cancel_unpaid_order): not
	 * while its invoice may still be paid or confirmed, nor before its final status is known, nor
	 * once something came for it (see InvoiceRecord::is_live).
	 *
	 * @param mixed $cancel Whether WooCommerce would.
	 * @param mixed $order  The order.
	 */
	public function may_cancel_unpaid( $cancel, $order ): bool {
		if ( true !== (bool) $cancel || ! $order instanceof \WC_Order ) {
			return (bool) $cancel;
		}

		return null === $this->live_invoice( $order->get_id() );
	}

	/**
	 * The statuses in which the customer may cancel $order (woocommerce_valid_order_statuses_for_cancel,
	 * which both the "Cancel" button of the customer's account and WooCommerce's handler of its
	 * link read): none while its invoice may still be paid or confirmed, or once something came.
	 *
	 * @param mixed $statuses The statuses WooCommerce would allow.
	 * @param mixed $order    The order.
	 * @return mixed
	 */
	public function statuses_for_cancel( $statuses, $order = null ) {
		if ( ! is_array( $statuses ) || ! $order instanceof \WC_Order ) {
			return $statuses;
		}

		return null === $this->live_invoice( $order->get_id() ) ? $statuses : array();
	}

	/**
	 * The buttons of $order in the customer's account (woocommerce_my_account_my_orders_actions):
	 * no "Pay" while the order has an invoice that cannot be paid now (being confirmed, paid,
	 * ended, given up on, or being made): it leads to a payment to make, or nowhere.
	 *
	 * @param mixed $actions The buttons.
	 * @param mixed $order   The order.
	 * @return mixed
	 */
	public function account_actions( $actions, $order = null ) {
		if ( ! is_array( $actions ) || ! isset( $actions['pay'] ) || ! $order instanceof \WC_Order ) {
			return $actions;
		}
		$live = $this->live_invoice( $order->get_id() );
		if ( null !== $live && ! $this->is_payable( $live ) ) {
			unset( $actions['pay'] );
		}

		return $actions;
	}

	/**
	 * Whether $invoice can be paid now: made, before its end, nothing being confirmed nor paid
	 * (an underpayment's rest can be).
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function is_payable( InvoiceRecord $invoice ): bool {
		return $invoice->has_invoice() && ! $invoice->is_final && null !== $invoice->expires_at
			&& $this->clock->now() < $invoice->expires_at
			&& ! in_array( $invoice->status_state(), array( 'unconfirmed', 'paid', 'expired' ), true );
	}

	/**
	 * The payment methods offered (woocommerce_available_payment_gateways): only this one while
	 * the order being paid has an invoice that did not end unpaid.
	 *
	 * @param array<string, \WC_Payment_Gateway>|mixed $gateways The gateways.
	 * @return array<string, \WC_Payment_Gateway>|mixed
	 */
	public function available_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways[ GatewaySettings::GATEWAY_ID ] ) ) {
			return $gateways;
		}
		$order_id = $this->order_being_paid();
		if ( 0 === $order_id || null === $this->live_invoice( $order_id ) ) {
			return $gateways;
		}

		return array( GatewaySettings::GATEWAY_ID => $gateways[ GatewaySettings::GATEWAY_ID ] );
	}

	/**
	 * The live invoice of order $order_id, if any.
	 *
	 * @param int $order_id The order.
	 */
	public function live_invoice( int $order_id ): ?InvoiceRecord {
		return $this->invoices->live_for_order( $order_id, $this->clock->now() );
	}

	/**
	 * The live invoice of the order this checkout would pay, if any (the description at checkout).
	 */
	public function live_invoice_of_checkout(): ?InvoiceRecord {
		$order_id = $this->order_being_paid();

		return 0 === $order_id ? null : $this->live_invoice( $order_id );
	}

	/**
	 * Remembers the order of a Store API request paying an existing order
	 * (rest_request_before_callbacks), for available_gateways().
	 *
	 * @param mixed $response The response so far.
	 * @param mixed $handler  The route's handler.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function remember_rest_order( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && 1 === preg_match( '#^/wc/store(?:/v\d+)?/checkout/(\d+)$#D', $request->get_route(), $match ) ) {
			$this->rest_order_id = Input::id( $match[1] );
		}

		return $response;
	}

	/**
	 * Forgets the order of the REST request that ended (rest_request_after_callbacks): the next
	 * request of the same PHP process (a batch of requests, /batch/v1) pays its own order, if any.
	 *
	 * @param mixed $response The response.
	 * @return mixed
	 */
	public function forget_rest_order( $response ) {
		$this->rest_order_id = 0;

		return $response;
	}

	/**
	 * Before the "pay for order" form (before_woocommerce_pay_form): tells the customer when
	 * another method becomes possible.
	 *
	 * @param \WC_Order|mixed $order The order.
	 */
	public function pay_form_notice( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$live = $this->live_invoice( $order->get_id() );
		if ( null === $live || ! $live->has_invoice() ) {
			return;
		}
		wc_print_notice( $this->wait_message( $live ), 'notice' );
	}

	/**
	 * What to tell a customer who would pay $live's order otherwise.
	 *
	 * @param InvoiceRecord $live The live invoice.
	 */
	public function wait_message( InvoiceRecord $live ): string {
		if ( $live->is_final ) {
			// Paid at another price, underpaid, or given up on: the merchant settles it.
			return __( 'This order’s Bitcoin payment needs the shop’s attention: please contact us to settle it.', 'sattleme-for-woocommerce' );
		}
		if ( $live->has_received() || in_array( $live->status_state(), array( 'unconfirmed', 'paid' ), true ) ) {
			return __( 'A Bitcoin payment of this order has arrived and is being checked: it cannot be paid with another method meanwhile.', 'sattleme-for-woocommerce' );
		}
		if ( $this->clock->now() >= $live->end() ) {
			return __( 'The time to pay this order’s Bitcoin invoice is over, and the invoice is being checked: another method is possible once the check found it unpaid, in a few minutes.', 'sattleme-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: the time the order's Bitcoin invoice can be paid until. */
			__( 'This order has a Bitcoin invoice that can be paid until %s. To pay with another method, please wait until then: a Lightning invoice cannot be withdrawn.', 'sattleme-for-woocommerce' ),
			Dates::time( $live->end() )
		);
	}

	/**
	 * The order this request would pay: its "pay for order" page, the Store API's checkout of an
	 * existing order, or the pending order that checking out the cart again would pay (the same
	 * cart, in WooCommerce's session). 0 for none.
	 */
	private function order_being_paid(): int {
		global $wp;

		if ( 0 !== $this->rest_order_id ) {
			return $this->rest_order_id;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) && $wp instanceof \WP ) {
			return Input::id( $wp->query_vars['order-pay'] ?? 0 );
		}
		if ( ! function_exists( 'WC' ) || null === WC()->session || null === WC()->cart ) {
			return 0;
		}
		foreach ( array( 'order_awaiting_payment', 'store_api_draft_order' ) as $name ) {
			$order_id = Input::id( WC()->session->get( $name, 0 ) );
			$order    = 0 === $order_id ? false : wc_get_order( $order_id );
			// WooCommerce pays it again when it needs payment and the cart did not change.
			if ( $order instanceof \WC_Order && $order->needs_payment() && $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) {
				return $order_id;
			}
		}

		return 0;
	}
}
