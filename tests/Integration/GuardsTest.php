<?php
/**
 * One payable invoice per order, and no cancellation while it may be paid.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Orders\Guards;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;

/**
 * While an order's invoice may still be paid or be confirmed, WooCommerce does not cancel the
 * order ("Hold stock"), and the order takes no other payment method.
 */
final class GuardsTest extends TestCase {

	public function test_an_order_with_a_live_invoice_is_not_cancelled(): void {
		$order = $this->order();
		$this->invoice_for( $order );

		self::assertFalse( $this->services()->guards->may_cancel_unpaid( true, $this->reload( $order ) ) );
		self::assertFalse( $this->services()->guards->may_cancel_unpaid( false, $this->reload( $order ) ) );
	}

	public function test_an_order_without_an_invoice_is_cancelled_as_woocommerce_decides(): void {
		$order = $this->order();

		self::assertTrue( $this->services()->guards->may_cancel_unpaid( true, $order ) );
		self::assertFalse( $this->services()->guards->may_cancel_unpaid( false, $order ) );
		self::assertTrue( $this->services()->guards->may_cancel_unpaid( 1, 'not an order' ) );
	}

	public function test_an_invoice_past_its_end_keeps_the_order_until_its_status_is_final(): void {
		$order              = $this->order();
		$invoice            = $this->invoice_for( $order );
		$this->clock()->now = $invoice->end() + 3600;

		self::assertFalse( $this->services()->guards->may_cancel_unpaid( true, $this->reload( $order ) ) );

		$this->sdk()->statuses = array( Sdk::expired() );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertTrue( $this->services()->guards->may_cancel_unpaid( true, $this->reload( $order ) ) );
	}

	public function test_woocommerce_s_cancellation_of_unpaid_orders_spares_an_order_being_paid(): void {
		$paying                = $this->order();
		$attempt               = $this->invoice_for( $paying );
		$this->sdk()->statuses = array( Sdk::unconfirmed( 1 ) );
		$this->services()->checker->check( $attempt->id, 0, 10 );
		$unpaid = $this->order();
		$this->backdate( $paying, 2 * HOUR_IN_SECONDS );
		$this->backdate( $unpaid, 2 * HOUR_IN_SECONDS );

		wc_cancel_unpaid_orders();

		self::assertSame( 'pending', $this->reload( $paying )->get_status() );
		self::assertSame( 'cancelled', $this->reload( $unpaid )->get_status() );
	}

	public function test_the_filter_runs_after_woocommerce_s_own(): void {
		self::assertSame( 100, has_filter( 'woocommerce_cancel_unpaid_order', array( $this->services()->guards, 'may_cancel_unpaid' ) ) );
	}

	public function test_on_the_pay_page_of_an_order_with_a_live_invoice_only_bitcoin_is_offered(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->go_to( $this->reload( $order )->get_checkout_payment_url() );

		$gateways = $this->services()->guards->available_gateways( $this->gateways() );

		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme' ), array_keys( $gateways ) );
	}

	public function test_on_the_pay_page_of_an_order_without_one_every_method_is_offered(): void {
		$order = $this->order();
		$this->go_to( $order->get_checkout_payment_url() );

		$gateways = $this->services()->guards->available_gateways( $this->gateways() );

		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme', 'cheque' ), array_keys( $gateways ) );
	}

	public function test_the_store_api_s_payment_of_an_order_with_a_live_invoice_offers_only_bitcoin(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout/' . $order->get_id() );
		$this->services()->guards->remember_rest_order( null, null, $request );

		$gateways = $this->services()->guards->available_gateways( $this->gateways() );

		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme' ), array_keys( $gateways ) );

		// The request ended: the next one of this process pays no order of its.
		apply_filters( 'rest_request_after_callbacks', null, array(), $request );
		$gateways = $this->services()->guards->available_gateways( $this->gateways() );
		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme', 'cheque' ), array_keys( $gateways ) );
	}

	public function test_another_method_once_the_invoice_ended_unpaid(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->clock()->now    = $invoice->end();
		$this->sdk()->statuses = array( Sdk::expired() );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		$this->go_to( $this->reload( $order )->get_checkout_payment_url() );

		$gateways = $this->services()->guards->available_gateways( $this->gateways() );

		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme', 'cheque' ), array_keys( $gateways ) );
	}

	public function test_the_pay_form_says_why(): void {
		$order = $this->order();
		$this->invoice_for( $order );

		ob_start();
		$this->services()->guards->pay_form_notice( $this->reload( $order ) );
		wc_print_notices();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'This order has a Bitcoin invoice that can be paid until', $html );
	}

	public function test_what_the_customer_is_told(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$guards = $this->services()->guards;

		self::assertStringStartsWith( 'This order has a Bitcoin invoice that can be paid until ', $guards->wait_message( $invoice ) );

		$this->clock()->now = $invoice->end();
		self::assertSame( 'The time to pay this order’s Bitcoin invoice is over, and the invoice is being checked: another method is possible once the check found it unpaid, in a few minutes.', $guards->wait_message( $invoice ) );

		$this->sdk()->statuses = array( Sdk::unconfirmed( 1 ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertSame( 'A Bitcoin payment of this order has arrived and is being checked: it cannot be paid with another method meanwhile.', $guards->wait_message( $this->attempt( $invoice->id ) ) );

		$this->sdk()->statuses = array( Sdk::expired( '20000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertSame( 'This order’s Bitcoin payment needs the shop’s attention: please contact us to settle it.', $guards->wait_message( $this->attempt( $invoice->id ) ) );
	}

	public function test_past_its_end_an_invoice_not_checked_since_keeps_another_method_away(): void {
		$order              = $this->order();
		$invoice            = $this->invoice_for( $order );
		$this->clock()->now = $invoice->end() + 1;
		$this->go_to( $this->reload( $order )->get_checkout_payment_url() );

		$gateways = $this->services()->guards->available_gateways( $this->gateways() );

		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme' ), array_keys( $gateways ), 'a payment of its last seconds is found by a check only' );
		self::assertNotNull( $this->services()->guards->live_invoice( $order->get_id() ) );
	}

	public function test_an_invoice_that_ended_underpaid_keeps_another_method_away(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->clock()->now    = $invoice->end();
		$this->sdk()->statuses = array( Sdk::expired( '20000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		$this->go_to( $this->reload( $order )->get_checkout_payment_url() );

		$gateways = $this->services()->guards->available_gateways( $this->gateways() );

		self::assertIsArray( $gateways );
		self::assertSame( array( 'sattleme' ), array_keys( $gateways ) );
	}

	public function test_the_customer_cannot_cancel_an_order_while_bitcoin_may_come_nor_once_it_came(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		self::assertSame( array(), $this->statuses_for_cancel( $order ), 'an invoice that can be paid' );
		self::assertArrayNotHasKey( 'cancel', wc_get_account_orders_actions( $this->reload( $order ) ) );

		$this->sdk()->statuses = array( Sdk::open( (int) $invoice->expires_at, '20000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertSame( array(), $this->statuses_for_cancel( $order ), 'an underpayment, its rest asked' );

		$this->clock()->advance( 10 );
		$this->sdk()->statuses = array( Sdk::unconfirmed( 1 ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertSame( array(), $this->statuses_for_cancel( $order ), 'a payment being confirmed' );
	}

	public function test_nor_once_an_invoice_ended_underpaid_nor_given_up_on(): void {
		$underpaid             = $this->order();
		$invoice               = $this->invoice_for( $underpaid );
		$this->clock()->now    = $invoice->end();
		$this->sdk()->statuses = array( Sdk::expired( '20000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertSame( array(), $this->statuses_for_cancel( $underpaid ) );

		$abandoned = $this->order();
		$attempt   = $this->invoice_for( $abandoned );
		self::assertTrue( $this->services()->invoices->give_up( $attempt->id, $this->clock()->now() ) );
		self::assertSame( array(), $this->statuses_for_cancel( $abandoned ), 'its payment may have come' );
		self::assertFalse( $this->services()->guards->may_cancel_unpaid( true, $this->reload( $abandoned ) ), 'nor by "Hold stock"' );
	}

	public function test_the_customer_can_cancel_once_the_invoice_ended_unpaid(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->clock()->now    = $invoice->end();
		$this->sdk()->statuses = array( Sdk::expired() );
		$this->services()->checker->check( $invoice->id, 0, 10 );

		self::assertSame( array( 'pending', 'failed' ), $this->statuses_for_cancel( $order ) );
		$actions = wc_get_account_orders_actions( $this->reload( $order ) );
		self::assertArrayHasKey( 'cancel', $actions );
		self::assertArrayHasKey( 'pay', $actions, 'and pay again' );
	}

	public function test_woocommerce_s_cancel_link_is_refused_on_the_server(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$order = $this->reload( $order );
		if ( null === WC()->session ) {
			WC()->initialize_session();
		}
		$url = $order->get_cancel_order_url_raw();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET = $query;

		\WC_Form_Handler::cancel_order();

		$_GET = array();
		self::assertSame( 'pending', $this->reload( $order )->get_status(), 'never cancelled' );
		self::assertSame( array( 'Your order can no longer be cancelled. Please contact us if you need assistance.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		wc_clear_notices();
	}

	public function test_the_pay_button_leads_to_a_payment_to_make_only(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		self::assertArrayHasKey( 'pay', wc_get_account_orders_actions( $this->reload( $order ) ), 'its invoice can be paid' );

		$this->sdk()->statuses = array( Sdk::open( (int) $invoice->expires_at, '20000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertArrayHasKey( 'pay', wc_get_account_orders_actions( $this->reload( $order ) ), 'the rest of an underpayment can be paid' );

		$this->clock()->advance( 10 );
		$this->sdk()->statuses = array( Sdk::unconfirmed( 1 ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertArrayNotHasKey( 'pay', wc_get_account_orders_actions( $this->reload( $order ) ), 'a payment being confirmed' );

		$ended              = $this->order();
		$late               = $this->invoice_for( $ended );
		$this->clock()->now = (int) $late->expires_at;
		self::assertArrayNotHasKey( 'pay', wc_get_account_orders_actions( $this->reload( $ended ) ), 'its time is over, its final status unknown' );
	}

	/**
	 * The statuses in which the customer may cancel $order, as WooCommerce asks.
	 *
	 * @param \WC_Order $order The order.
	 * @return mixed
	 */
	private function statuses_for_cancel( \WC_Order $order ) {
		return apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $this->reload( $order ) );
	}

	/**
	 * The method and another one, as WooCommerce lists them.
	 *
	 * @return array<string, \WC_Payment_Gateway>
	 */
	private function gateways(): array {
		$all      = WC()->payment_gateways()->payment_gateways();
		$sattleme = $all['sattleme'] ?? null;
		$cheque   = $all['cheque'] ?? null;
		self::assertInstanceOf( \WC_Payment_Gateway::class, $sattleme );
		self::assertInstanceOf( \WC_Payment_Gateway::class, $cheque );

		return array(
			'sattleme' => $sattleme,
			'cheque'   => $cheque,
		);
	}
}
