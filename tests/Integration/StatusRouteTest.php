<?php
/**
 * The payment page's status endpoint.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Page\StatusRoute;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;

/**
 * GET /sattleme-for-woocommerce/v1/orders/<id>/status?key=…: authorized by the order's key, it has
 * the invoice checked by the server at most every few seconds, and answers where the payment is.
 */
final class StatusRouteTest extends TestCase {

	public function test_the_status_of_an_open_invoice(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->clock()->advance( 10 );

		$response = $this->status( $order, $order->get_order_key() );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertSame( 'open', $data['phase'] );
		$version = $data['version'] ?? null;
		self::assertIsString( $version );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/D', $version );
		self::assertArrayNotHasKey( 'redirect', $data );
		self::assertSame( 'no-store, no-cache, must-revalidate, max-age=0, private', $response->get_headers()['Cache-Control'] );
		self::assertCount( 1, $this->sdk()->status_calls, 'checked by the server' );
	}

	public function test_an_invoice_that_ended_underpaid_sends_the_page_to_the_order_s_page(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->clock()->now    = $invoice->end();
		$this->sdk()->statuses = array( Sdk::expired( '20000' ) );

		$data = $this->status( $order, $order->get_order_key() )->get_data();

		self::assertIsArray( $data );
		self::assertSame( 'expired', $data['phase'] );
		self::assertSame( 'on-hold', $this->reload( $order )->get_status() );
		self::assertSame( $this->reload( $order )->get_checkout_order_received_url(), $data['redirect'] ?? null, 'which says what came' );
	}

	public function test_another_key_is_refused(): void {
		$order = $this->order();
		$this->invoice_for( $order );

		$response = $this->status( $order, 'wc_order_' . str_repeat( 'x', 13 ) );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( array(), $this->sdk()->status_calls );
	}

	public function test_no_key_is_refused(): void {
		$order   = $this->order();
		$request = new \WP_REST_Request( 'GET', '/' . StatusRoute::NAMESPACE . '/orders/' . $order->get_id() . '/status' );

		$response = rest_get_server()->dispatch( $request );

		self::assertSame( 400, $response->get_status() );
	}

	public function test_an_unknown_order_is_refused(): void {
		$request = new \WP_REST_Request( 'GET', '/' . StatusRoute::NAMESPACE . '/orders/999999/status' );
		$request->set_query_params( array( 'key' => 'wc_order_x' ) );

		self::assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_another_method_s_order_is_refused(): void {
		$order = $this->order();
		$order->set_payment_method( 'cheque' );
		$order->save();

		self::assertSame( 404, $this->status( $order, $order->get_order_key() )->get_status() );
	}

	public function test_checks_are_spaced_however_often_the_page_asks(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->clock()->advance( 10 );

		for ( $i = 0; $i < 5; $i++ ) {
			self::assertSame( 200, $this->status( $order, $order->get_order_key() )->get_status() );
		}
		self::assertCount( 1, $this->sdk()->status_calls );

		$this->clock()->advance( 6 );
		$this->status( $order, $order->get_order_key() );
		self::assertCount( 2, $this->sdk()->status_calls );
	}

	public function test_the_version_changes_with_what_the_page_shows(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->clock()->advance( 10 );
		$first = $this->status( $order, $order->get_order_key() )->get_data();

		$this->sdk()->statuses = array( Sdk::open( (int) $invoice->expires_at, '20000' ) );
		$this->clock()->advance( 10 );
		$second = $this->status( $order, $order->get_order_key() )->get_data();

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( 'underpaid', $second['phase'] );
		self::assertNotSame( $first['version'], $second['version'] );
	}

	public function test_once_paid_the_page_goes_to_the_order_received_page(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::paid() );
		$this->clock()->advance( 10 );

		$data = $this->status( $order, $order->get_order_key() )->get_data();

		self::assertIsArray( $data );
		self::assertSame( 'paid', $data['phase'] );
		self::assertSame( $this->reload( $order )->get_checkout_order_received_url(), $data['redirect'] );
		self::assertTrue( $this->reload( $order )->is_paid() );
	}

	public function test_an_order_cancelled_then_paid_goes_to_its_order_received_page_as_received(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->reload( $order )->update_status( 'cancelled' );
		$this->sdk()->statuses = array( Sdk::paid() );
		$this->clock()->advance( 10 );

		$data = $this->status( $order, $order->get_order_key() )->get_data();

		self::assertIsArray( $data );
		self::assertSame( 'on-hold', $this->reload( $order )->get_status() );
		self::assertSame( 'received', $data['phase'], 'never "paid": the merchant decides' );
		self::assertSame( $this->reload( $order )->get_checkout_order_received_url(), $data['redirect'] );
	}

	public function test_nothing_a_request_says_decides_the_payment(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->clock()->advance( 10 );
		$request = new \WP_REST_Request( 'GET', '/' . StatusRoute::NAMESPACE . '/orders/' . $order->get_id() . '/status' );
		$request->set_query_params(
			array(
				'key'    => $order->get_order_key(),
				'state'  => 'paid',
				'phase'  => 'paid',
				'status' => 'paid',
			)
		);

		$data = rest_get_server()->dispatch( $request )->get_data();

		self::assertIsArray( $data );
		self::assertSame( 'open', $data['phase'] );
		self::assertFalse( $this->reload( $order )->is_paid() );
	}

	public function test_the_page_s_status_url(): void {
		$order = $this->order();

		$url = StatusRoute::url( $order );

		self::assertStringContainsString( rawurlencode( '/' . StatusRoute::NAMESPACE . '/orders/' . $order->get_id() . '/status' ), $url );
		self::assertStringContainsString( 'key=' . $order->get_order_key(), $url );
	}

	/**
	 * The route's answer for $order, asked with $key: a request, which may check the invoice.
	 *
	 * @phpstan-impure
	 * @param \WC_Order $order The order.
	 * @param string    $key   The key.
	 */
	private function status( \WC_Order $order, string $key ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/' . StatusRoute::NAMESPACE . '/orders/' . $order->get_id() . '/status' );
		$request->set_query_params( array( 'key' => $key ) );

		return rest_get_server()->dispatch( $request );
	}
}
