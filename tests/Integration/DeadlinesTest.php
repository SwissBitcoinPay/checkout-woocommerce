<?php
/**
 * An invoice never outlives its order's stock hold or cancellation.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Payments\PaymentFailure;
use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Support\Db;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;

/**
 * WooCommerce frees an order's stock when its hold ends (woocommerce_hold_stock_minutes, rows of
 * wc_reserved_stock), and cancels it unpaid once it was last changed that long ago: the invoice,
 * with the SDK's 120 s grace period, ends before either.
 */
final class DeadlinesTest extends TestCase {

	public function test_a_long_hold_leaves_the_time_to_pay_as_set(): void {
		$order = $this->order( $this->product( '24.50', 10 ) );

		$attempt = $this->invoice_for( $order );

		self::assertSame( 900, $this->sdk()->create_calls[0]['durationSeconds'] );
		self::assertSame( InvoiceRecord::STATE_INVOICE, $attempt->state );
	}

	public function test_a_short_hold_shortens_the_invoice_by_its_grace_period_and_the_call(): void {
		update_option( 'woocommerce_hold_stock_minutes', '10' );
		$order = $this->order( $this->product( '24.50', 10 ) );

		$attempt = $this->invoice_for( $order );

		$duration = $this->sdk()->create_calls[0]['durationSeconds'];
		self::assertGreaterThanOrEqual( 600 - 140 - 3, $duration );
		self::assertLessThanOrEqual( 600 - 140, $duration );
		self::assertNotNull( $attempt->expires_at );
		self::assertLessThanOrEqual( $this->clock()->now() + 600, $attempt->end() + 20, 'its grace period ends with the hold, the call\'s time aside' );
		self::assertLessThanOrEqual( 600, $this->hold_left( $order ) );
	}

	public function test_the_cancellation_bounds_an_order_holding_no_stock(): void {
		update_option( 'woocommerce_hold_stock_minutes', '10' );
		$order = $this->order( $this->product( '24.50' ) );

		$this->invoice_for( $order );

		self::assertSame( 600 - 140, $this->sdk()->create_calls[0]['durationSeconds'] );
		self::assertNull( $this->hold_left( $order ) );
	}

	public function test_no_bound_when_woocommerce_holds_nothing_and_cancels_nothing(): void {
		update_option( 'woocommerce_hold_stock_minutes', '' );
		$order = $this->order( $this->product( '24.50', 10 ) );

		$this->invoice_for( $order );

		self::assertSame( 900, $this->sdk()->create_calls[0]['durationSeconds'] );
	}

	public function test_no_bound_without_stock_management(): void {
		update_option( 'woocommerce_hold_stock_minutes', '2' );
		update_option( 'woocommerce_manage_stock', 'no' );
		$order = $this->order( $this->product( '24.50' ) );

		$this->invoice_for( $order );

		self::assertSame( 900, $this->sdk()->create_calls[0]['durationSeconds'] );
	}

	public function test_a_hold_too_short_makes_no_invoice(): void {
		update_option( 'woocommerce_hold_stock_minutes', '3' );
		$order = $this->order( $this->product( '24.50', 10 ) );

		try {
			$this->invoice_for( $order );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure $e ) {
			self::assertSame( 'The time to pay this order is almost over: it cannot be paid with Bitcoin.', $e->getMessage() );
		}

		self::assertSame( array(), $this->sdk()->create_calls );
		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertSame( InvoiceRecord::STATE_FAILED, $attempt->state );
		self::assertSame( Errors::DEADLINE, $attempt->error );
	}

	public function test_a_hold_that_ran_short_is_renewed_for_the_full_time(): void {
		$order = $this->order( $this->product( '24.50', 10 ) );
		$this->expire_hold( $order, 300 );
		self::assertLessThanOrEqual( 300, $this->hold_left( $order ) );

		$this->invoice_for( $order );

		self::assertSame( 900, $this->sdk()->create_calls[0]['durationSeconds'] );
		self::assertGreaterThan( 3500, $this->hold_left( $order ) );
	}

	public function test_a_hold_that_ended_is_taken_again(): void {
		$order = $this->order( $this->product( '24.50', 10 ) );
		$this->expire_hold( $order, -60 );
		self::assertNull( $this->hold_left( $order ) );

		$this->invoice_for( $order );

		self::assertGreaterThan( 3500, $this->hold_left( $order ) );
	}

	public function test_products_no_longer_in_stock_make_no_invoice(): void {
		$product = $this->product( '24.50', 1 );
		$order   = $this->order( $product );
		$this->expire_hold( $order, -60 );
		$product->set_stock_quantity( 0 );
		$product->save();

		try {
			$this->invoice_for( $this->reload( $order ) );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure $e ) {
			self::assertStringContainsString( 'Hodl mug', $e->getMessage() );
			self::assertStringContainsString( 'out of stock', $e->getMessage() );
		}
		self::assertSame( array(), $this->sdk()->create_calls );
		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertSame( 'stock', $attempt->error );
	}

	public function test_an_invoice_that_ends_after_the_deadline_is_never_shown(): void {
		update_option( 'woocommerce_hold_stock_minutes', '10' );
		$order = $this->order( $this->product( '24.50', 10 ) );
		$now   = $this->clock()->now();
		// The clock of the merchant's Lightning address is 90 s ahead.
		$this->sdk()->creates[] = static fn ( array $params ): Invoice => Sdk::invoice( $params, $now + 90 );

		try {
			$this->invoice_for( $order );
			self::fail( 'The invoice was shown' );
		} catch ( PaymentFailure $e ) {
			self::assertSame( 'The time to pay this order is almost over: it cannot be paid with Bitcoin.', $e->getMessage() );
		}

		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertSame( InvoiceRecord::STATE_FAILED, $attempt->state );
		self::assertFalse( $attempt->has_invoice() );
		self::assertStringContainsString( 'after the order\'s deadline', $this->log()->text() );
	}

	public function test_the_order_is_saved_as_the_invoice_is_made_so_that_its_cancellation_counts_from_then(): void {
		$order = $this->order();
		$this->backdate( $order, 3000 );
		$before = $this->reload( $order )->get_date_modified();
		self::assertNotNull( $before );

		$this->invoice_for( $this->reload( $order ) );

		$after = $this->reload( $order )->get_date_modified();
		self::assertNotNull( $after );
		self::assertGreaterThan( $before->getTimestamp() + 2900, $after->getTimestamp() );
	}

	/**
	 * Seconds left of $order's stock hold, by the database's clock; null without one.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function hold_left( \WC_Order $order ): ?int {
		$wpdb = Db::wpdb();
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(`expires`)) FROM %i WHERE order_id = %d AND `expires` > NOW()', $wpdb->prefix . 'wc_reserved_stock', $order->get_id() ) );

		return null === $left ? null : (int) $left;
	}

	/**
	 * Makes $order's stock hold end in $seconds (negative: ended).
	 *
	 * @param \WC_Order $order   The order.
	 * @param int       $seconds Seconds.
	 */
	private function expire_hold( \WC_Order $order, int $seconds ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( (string) $wpdb->prepare( 'UPDATE %i SET `expires` = DATE_ADD(NOW(), INTERVAL %d SECOND) WHERE order_id = %d', $wpdb->prefix . 'wc_reserved_stock', $seconds, $order->get_id() ) );
	}
}
