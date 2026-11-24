<?php
/**
 * What a check finds, applied to the order.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Support\Db;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Tests\Support\Arrays;
use Sattleme\WooCommerce\Tests\Support\Records;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * Every state of an invoice: open, open with a rest, unconfirmed, paid (in Lightning, on-chain,
 * both), expired, expired with something received. Only a paid status of the server's own
 * check completes the order, exactly once, with the reference as its transaction id.
 */
final class SettlementTest extends TestCase {

	/**
	 * The order of the test.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	/**
	 * Its invoice.
	 *
	 * @var InvoiceRecord
	 */
	private InvoiceRecord $invoice;

	/**
	 * How many more times refuse() fails.
	 *
	 * @var int
	 */
	private int $refusals = 0;

	public function set_up(): void {
		parent::set_up();
		$this->order   = $this->order( $this->product( '24.50', 10 ) );
		$this->invoice = $this->invoice_for( $this->order );
	}

	public function test_open_changes_nothing_but_the_status_stored(): void {
		$this->check( Sdk::open( (int) $this->invoice->expires_at ) );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( 'open', $attempt->status_state() );
		self::assertSame( 1, $attempt->checks );
		self::assertSame( $this->clock()->now(), $attempt->checked_at );
		self::assertFalse( $attempt->is_final );
		self::assertNull( $attempt->lease_until, 'the check let it go' );
		self::assertSame( 'pending', $this->reload( $this->order )->get_status() );
		self::assertCount( 1, $this->invoice_notes(), 'the invoice\'s note alone' );
	}

	public function test_an_underpayment_is_noted_once_and_its_rest_kept(): void {
		$this->check( Sdk::open( (int) $this->invoice->expires_at, '20000' ) );
		$this->check( Sdk::open( (int) $this->invoice->expires_at, '20000' ) );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( '20000', $attempt->received() );
		$rest = $attempt->rest();
		self::assertNotNull( $rest );
		self::assertSame( '29000', $rest['sats'] );
		$notes = $this->invoice_notes();
		self::assertCount( 2, $notes );
		$this->assertNoteContains( '20,000 of 49,000 sats came for invoice ' . $this->invoice->ref . ': the customer is asked for the rest until the invoice ends.', $notes );
		self::assertSame( 'pending', $this->reload( $this->order )->get_status() );
	}

	public function test_a_payment_being_confirmed_is_noted_once_and_its_order_kept(): void {
		$this->check( Sdk::unconfirmed( 0 ) );
		$this->clock()->advance( 600 );
		$this->check( Sdk::unconfirmed( 1 ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'pending', $order->get_status() );
		$notes = $this->invoice_notes();
		self::assertCount( 2, $notes );
		$this->assertNoteContains( 'Bitcoin invoice ' . $this->invoice->ref . ': 49,000 sats came in time for its 49,000 sats; its on-chain payment is being confirmed (0 of 3 confirmations, about 30 minutes).', $notes );
		self::assertFalse( $this->services()->guards->may_cancel_unpaid( true, $order ) );
		self::assertFalse( $this->attempt( $this->invoice->id )->is_final );
	}

	public function test_a_payment_being_confirmed_holds_its_stock_again(): void {
		$wpdb = Db::wpdb();
		$wpdb->query( (string) $wpdb->prepare( 'UPDATE %i SET `expires` = DATE_ADD(NOW(), INTERVAL 300 SECOND) WHERE order_id = %d', $wpdb->prefix . 'wc_reserved_stock', $this->order->get_id() ) );

		$this->check( Sdk::unconfirmed( 1 ) );

		$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(`expires`)) FROM %i WHERE order_id = %d', $wpdb->prefix . 'wc_reserved_stock', $this->order->get_id() ) );
		self::assertGreaterThan( 3500, $left );
	}

	/**
	 * @dataProvider paid
	 *
	 * @param PaymentStatus $status The status.
	 * @param string        $note   The order's note.
	 */
	public function test_paid_completes_the_order_with_the_reference( PaymentStatus $status, string $note ): void {
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( $status );

		$order   = $this->reload( $this->order );
		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( 'processing', $order->get_status() );
		self::assertTrue( $order->is_paid() );
		self::assertSame( $this->invoice->ref, $order->get_transaction_id() );
		self::assertSame( 'sattleme', $order->get_payment_method() );
		self::assertSame( $completed + 1, did_action( 'woocommerce_payment_complete' ) );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $attempt->outcome );
		self::assertNull( $attempt->next_check_at );
		$this->assertNoteContains( sprintf( $note, $this->invoice->ref ), $this->notes( $order ) );
	}

	/**
	 * The paid statuses, and their notes.
	 *
	 * @return array<string, array{PaymentStatus, string}>
	 */
	public static function paid(): array {
		return array(
			'in Lightning'             => array( Sdk::paid( 'lightning' ), 'Bitcoin invoice %s paid in Lightning: 49,000 sats.' ),
			'on-chain'                 => array( Sdk::paid( 'onchain' ), 'Bitcoin invoice %s paid on-chain (3 confirmations): 49,000 sats came in time, for its 49,000 sats.' ),
			'on-chain and a rest'      => array( Sdk::paid( 'mixed', '49000' ), 'Bitcoin invoice %s paid on-chain (3 confirmations) and in Lightning (the rest of an underpayment): 49,000 sats came in time in all, for its 49,000 sats.' ),
			// SPEC.md §3: a mixed payment without transactions, its rest's invoices alone paid it.
			'the rest alone'           => array( PaymentStatus::paidMixed( '49000', array(), array( Records::PREIMAGE ) ), 'Bitcoin invoice %s paid in Lightning, by invoices of the rest of an underpayment: 49,000 sats came in time, for its 49,000 sats.' ),
			'within the tolerance'     => array( Sdk::paid( 'onchain', '48500' ), 'Bitcoin invoice %s paid on-chain (3 confirmations): 48,500 sats came in time, for its 49,000 sats. Less than its amount: accepted in the sattle.me app as paying it in full, by your tolerance or by your acceptance of what came.' ),
			// The merchant accepted, in the sattle.me app, what an underpaid order received.
			'accepted by the merchant' => array( Sdk::paid( 'onchain', '30000' ), 'Bitcoin invoice %s paid on-chain (3 confirmations): 30,000 sats came in time, for its 49,000 sats. Less than its amount: accepted in the sattle.me app as paying it in full, by your tolerance or by your acceptance of what came.' ),
			'accepted, with a rest'    => array( Sdk::paid( 'mixed', '41000' ), 'Bitcoin invoice %s paid on-chain (3 confirmations) and in Lightning (the rest of an underpayment): 41,000 sats came in time in all, for its 49,000 sats. Less than its amount' ),
			'more on-chain'            => array( Sdk::paid( 'onchain', '50000' ), 'Bitcoin invoice %s paid on-chain (3 confirmations): 50,000 sats came in time, for its 49,000 sats. 1,000 sats more than its amount reached your wallet: refund them to the customer if needed.' ),
			'more, with a rest'        => array( Sdk::paid( 'mixed', '49500' ), '49,500 sats came in time in all, for its 49,000 sats. 500 sats more than its amount reached your wallet' ),
		);
	}

	public function test_an_order_the_merchant_accepted_underpaid_is_completed_never_on_hold(): void {
		// The merchant's acceptance (SPEC.md §31): being confirmed, then paid, received below sats.
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( Sdk::unconfirmed( 1, '30000' ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'pending', $order->get_status() );
		$this->assertNoteContains( 'Bitcoin invoice ' . $this->invoice->ref . ': 30,000 sats came in time for its 49,000 sats; its on-chain payment is being confirmed', $this->notes( $order ) );

		$this->check( Sdk::paid( 'onchain', '30000' ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'processing', $order->get_status() );
		self::assertTrue( $order->is_paid() );
		self::assertSame( $completed + 1, did_action( 'woocommerce_payment_complete' ) );
		self::assertSame( $this->invoice->ref, $order->get_transaction_id() );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $this->attempt( $this->invoice->id )->outcome );
		$this->assertNoteContains( 'Bitcoin invoice ' . $this->invoice->ref . ' paid on-chain (3 confirmations): 30,000 sats came in time, for its 49,000 sats. Less than its amount: accepted in the sattle.me app as paying it in full, by your tolerance or by your acceptance of what came.', $this->notes( $order ) );
		foreach ( $this->notes( $order ) as $note ) {
			self::assertStringNotContainsString( 'on hold', $note );
		}
	}

	public function test_completed_exactly_once(): void {
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( Sdk::paid() );
		$this->check( Sdk::paid() );
		$this->services()->checker->apply_known( $this->invoice->id, Sdk::paid() );
		$this->services()->settlement->apply( $this->attempt( $this->invoice->id ), $this->clock()->now() + 60, Sdk::paid() );

		self::assertSame( $completed + 1, did_action( 'woocommerce_payment_complete' ) );
		self::assertCount( 1, $this->sdk()->status_calls, 'a final invoice is never checked again' );
		$paid_notes = array_filter( $this->notes( $this->order ), static fn ( string $note ): bool => str_contains( $note, 'paid in Lightning' ) );
		self::assertCount( 1, $paid_notes );
	}

	public function test_a_check_that_stopped_after_completing_never_completes_twice(): void {
		// An earlier check completed the order, then failed before its invoice was final.
		$this->reload( $this->order )->payment_complete( $this->invoice->ref );
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( Sdk::paid() );

		self::assertSame( $completed, did_action( 'woocommerce_payment_complete' ) );
		self::assertTrue( $this->attempt( $this->invoice->id )->is_final );
	}

	public function test_a_lightning_payment_never_reads_received_zero(): void {
		$this->check( Sdk::paid( 'lightning' ) );

		$notes = $this->notes( $this->order );
		$paid  = array_values( array_filter( $notes, static fn ( string $note ): bool => str_contains( $note, ' paid ' ) ) );
		self::assertSame( array( 'Bitcoin invoice ' . $this->invoice->ref . ' paid in Lightning: 49,000 sats.' ), $paid, 'what came is the invoice\'s amount, never on-chain' );
		foreach ( $notes as $note ) {
			self::assertStringNotContainsString( ' 0 sats', $note );
		}
	}

	public function test_a_completion_that_failed_before_the_order_was_saved_is_done_by_a_later_check_its_note_once(): void {
		// A plugin's hook fails as WooCommerce begins completing the payment: nothing is saved.
		$failures = 1;
		$fail     = static function () use ( &$failures ): void {
			if ( $failures-- > 0 ) {
				throw new \RuntimeException( 'The database went away' );
			}
		};
		add_action( 'woocommerce_pre_payment_complete', $fail );

		$this->check( Sdk::paid( 'onchain' ) );

		self::assertSame( 'pending', $this->reload( $this->order )->get_status() );
		self::assertFalse( $this->attempt( $this->invoice->id )->is_final );
		self::assertSame( Errors::APPLY, $this->attempt( $this->invoice->id )->error );

		$this->clock()->advance( 10 );
		$this->check( Sdk::paid( 'onchain' ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'processing', $order->get_status() );
		self::assertTrue( $this->attempt( $this->invoice->id )->is_final );
		self::assertCount( 1, array_filter( $this->notes( $order ), static fn ( string $note ): bool => str_contains( $note, 'paid on-chain (3 confirmations)' ) ), 'noted once' );
		remove_action( 'woocommerce_pre_payment_complete', $fail );
	}

	public function test_a_completion_that_failed_after_the_order_was_saved_is_finished_never_paid_twice(): void {
		// An error (not an exception: WooCommerce catches those) after the order was completed:
		// as a fatal error in an email sent from a background check would.
		$completed = did_action( 'woocommerce_payment_complete' );
		$failures  = 1;
		$fail      = static function () use ( &$failures ): void {
			if ( $failures-- > 0 ) {
				throw new \Error( 'An email failed' );
			}
		};
		add_action( 'woocommerce_payment_complete', $fail );

		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'processing', $order->get_status() );
		self::assertSame( $this->invoice->ref, $order->get_transaction_id() );
		self::assertFalse( $this->attempt( $this->invoice->id )->is_final, 'the check failed' );

		$this->clock()->advance( 10 );
		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertTrue( $this->attempt( $this->invoice->id )->is_final, 'finished by the later check' );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $this->attempt( $this->invoice->id )->outcome );
		self::assertSame( $completed + 1, did_action( 'woocommerce_payment_complete' ) );
		$notes = $this->notes( $order );
		self::assertCount( 1, array_filter( $notes, static fn ( string $note ): bool => str_contains( $note, 'paid in Lightning' ) ), 'its note once' );
		foreach ( $notes as $note ) {
			self::assertStringNotContainsString( 'paid otherwise', $note, 'never "paid twice"' );
		}
		remove_action( 'woocommerce_payment_complete', $fail );
	}

	public function test_a_note_whose_status_was_stored_by_a_check_that_failed_is_written_by_the_next(): void {
		// A check stored "being confirmed", then failed before its note (the lock, the database).
		$this->services()->invoices->claim( $this->invoice->id, $this->clock()->now(), $this->clock()->now() + 60, 0 );
		$this->services()->invoices->store_status( $this->invoice->id, $this->clock()->now() + 60, Sdk::unconfirmed( 1 )->toArray(), $this->clock()->now() );
		$this->clock()->advance( 120 );

		$this->check( Sdk::unconfirmed( 2 ) );

		$this->assertNoteContains( 'Bitcoin invoice ' . $this->invoice->ref . ': 49,000 sats came in time for its 49,000 sats', $this->notes( $this->order ) );
	}

	public function test_paid_at_a_price_the_order_no_longer_has_puts_it_on_hold(): void {
		// The merchant added an item to the order after its invoice was made: 34.50 CHF now.
		$order = $this->reload( $this->order );
		$order->add_product( $this->product( '10.00' ), 1 );
		$order->calculate_totals( false );
		$order->save();
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( Sdk::paid() );

		$order   = $this->reload( $this->order );
		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( 'on-hold', $order->get_status() );
		self::assertFalse( $order->is_paid(), 'never completed at another price' );
		self::assertSame( $completed, did_action( 'woocommerce_payment_complete' ) );
		self::assertSame( $this->invoice->ref, $order->get_transaction_id() );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $attempt->outcome );
		$this->assertNoteContains( 'Bitcoin invoice ' . $this->invoice->ref . ' was paid, for a price of 24.50 CHF. The order now owes 34.50 CHF (its total, less what was refunded): it changed after the invoice was made, so the order is on hold. Settle the difference with the customer, then update the order. It was paid in Lightning: 49,000 sats, its amount.', $this->notes( $order ) );
		self::assertStringContainsString( 'warning Invoice ' . $this->invoice->ref . ' paid, but its order ' . $order->get_id() . ' is no longer of its price (24.50 CHF): on hold', $this->log()->text() );
	}

	public function test_the_on_hold_note_gives_what_was_received(): void {
		$order = $this->reload( $this->order );
		$order->add_product( $this->product( '10.00' ), 1 );
		$order->calculate_totals( false );
		$order->save();

		// The merchant's tolerance accepted less than the invoice's amount.
		$this->check( Sdk::paid( 'onchain', '48500' ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status() );
		$this->assertNoteContains( 'was paid, for a price of 24.50 CHF. The order now owes 34.50 CHF', $this->notes( $order ) );
		$this->assertNoteContains( 'It was paid on-chain (3 confirmations): 48,500 sats came in time, for its 49,000 sats. Less than its amount: accepted in the sattle.me app as paying it in full, by your tolerance or by your acceptance of what came.', $this->notes( $order ) );
	}

	public function test_a_refund_recorded_meanwhile_puts_the_order_on_hold(): void {
		// WooCommerce records a refund on a pending order too; its total stays as it was.
		$refund = wc_create_refund(
			array(
				'order_id' => $this->order->get_id(),
				'amount'   => '10.00',
				'reason'   => 'An item removed by the merchant',
			)
		);
		self::assertInstanceOf( \WC_Order_Refund::class, $refund );
		self::assertSame( '24.50', wc_format_decimal( $this->reload( $this->order )->get_total( 'edit' ), 2 ) );

		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status(), 'paid 24.50 for an order that owes 14.50 now' );
		self::assertFalse( $order->is_paid() );
		$this->assertNoteContains( 'for a price of 24.50 CHF. The order now owes 14.50 CHF (its total, less what was refunded)', $this->notes( $order ) );
	}

	public function test_a_coupon_applied_meanwhile_puts_the_order_on_hold(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'minus10' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '10' );
		$coupon->save();
		$order = $this->reload( $this->order );
		self::assertTrue( $order->apply_coupon( 'minus10' ) );
		$order->save();

		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status() );
		$this->assertNoteContains( 'The order now owes 14.50 CHF', $this->notes( $order ) );
	}

	public function test_a_check_that_stopped_after_holding_an_order_never_holds_it_twice(): void {
		$order = $this->reload( $this->order );
		$order->add_product( $this->product( '10.00' ), 1 );
		$order->calculate_totals( false );
		$order->set_transaction_id( $this->invoice->ref );
		$order->update_status( 'on-hold', 'Put on hold by an earlier check.' );
		$notes = count( $this->notes( $order ) );

		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status() );
		self::assertCount( $notes, $this->notes( $order ) );
		self::assertTrue( $this->attempt( $this->invoice->id )->is_final );
	}

	public function test_a_hold_woocommerce_refused_is_applied_again_later(): void {
		$order = $this->reload( $this->order );
		$order->add_product( $this->product( '10.00' ), 1 );
		$order->calculate_totals( false );
		$order->save();
		// A filter run as the status changes fails, once: WooCommerce changes nothing, and says so.
		$this->refusals = 1;
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'refuse' ) );

		$this->check( Sdk::paid() );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertFalse( $attempt->is_final, 'a later check applies it' );
		self::assertSame( Errors::APPLY, $attempt->error );
		self::assertSame( 'pending', $this->reload( $this->order )->get_status() );

		$this->clock()->advance( 10 );
		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status() );
		self::assertSame( $this->invoice->ref, $order->get_transaction_id() );
		self::assertTrue( $this->attempt( $this->invoice->id )->is_final );
		self::assertCount( 1, array_filter( $this->notes( $order ), static fn ( string $note ): bool => str_contains( $note, 'so the order is on hold' ) ) );
	}

	public function test_paid_in_another_currency_than_the_order_s_puts_it_on_hold(): void {
		$order = $this->reload( $this->order );
		$order->set_currency( 'EUR' );
		$order->save();

		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status() );
		$this->assertNoteContains( 'for a price of 24.50 CHF. The order now owes 24.50 EUR', $this->notes( $order ) );
	}

	public function test_paid_after_the_order_was_cancelled_puts_it_on_hold(): void {
		$this->reload( $this->order )->update_status( 'cancelled' );
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( Sdk::paid() );

		$order   = $this->reload( $this->order );
		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( 'on-hold', $order->get_status() );
		self::assertFalse( $order->is_paid() );
		self::assertSame( $completed, did_action( 'woocommerce_payment_complete' ), 'never completed' );
		self::assertNull( $order->get_date_paid( 'edit' ), 'WooCommerce dates a payment once the order is processing or completed' );
		self::assertSame( $this->invoice->ref, $order->get_transaction_id() );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $attempt->outcome );
		$this->assertNoteContains( 'Bitcoin invoice ' . $this->invoice->ref . ' was paid after the order was cancelled, for a price of 24.50 CHF: the bitcoin reached your wallet. The order is on hold: reinstate it, or refund the customer from your wallet. It was paid in Lightning: 49,000 sats, its amount.', $this->notes( $order ) );
		self::assertStringContainsString( 'warning Invoice ' . $this->invoice->ref . ' paid, but its order ' . $order->get_id() . ' had been cancelled (24.50 CHF): on hold', $this->log()->text() );
	}

	public function test_an_order_cancelled_then_paid_takes_its_stock_and_its_customer_is_told(): void {
		$items = $this->order->get_items();
		$item  = reset( $items );
		self::assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$this->reload( $this->order )->update_status( 'cancelled' );
		self::assertSame( 10, $this->stock( $item->get_product_id() ), 'a pending order holds its stock, never takes it' );
		// WooCommerce's emails hook their triggers as WC()->mailer() first makes them, and the test
		// library removes the hooks a test added: the two this transition sends, made again.
		new \WC_Email_Customer_On_Hold_Order();
		new \WC_Email_New_Order();
		reset_phpmailer_instance();
		$on_hold = did_action( 'woocommerce_order_status_cancelled_to_on-hold_notification' );

		$this->check( Sdk::paid() );

		// WooCommerce takes an order's stock as it goes on hold (wc_maybe_reduce_stock_levels),
		// sends its customer the "on hold" email, and the shop its "new order" email (never sent
		// for an order that was never paid nor held).
		self::assertSame( 9, $this->stock( $item->get_product_id() ) );
		self::assertSame( $on_hold + 1, did_action( 'woocommerce_order_status_cancelled_to_on-hold_notification' ) );
		$mailer = tests_retrieve_phpmailer_instance();
		self::assertInstanceOf( \MockPHPMailer::class, $mailer );
		self::assertIsArray( $mailer->mock_sent );
		$sent = array();
		foreach ( $mailer->mock_sent as $mail ) {
			$to      = Arrays::at( $mail, 'to', 0, 0 );
			$subject = Arrays::at( $mail, 'subject' );
			$sent[]  = ( is_string( $to ) ? $to : '' ) . ': ' . ( is_string( $subject ) ? $subject : '' );
		}
		$shop = get_option( 'admin_email' );
		self::assertIsString( $shop );
		self::assertContains( 'alice@example.com: Your Test Shop order has been received!', $sent );
		self::assertContains( $shop . ': [Test Shop]: New order #' . $this->order->get_order_number(), $sent );
	}

	public function test_an_order_cancelled_then_paid_with_more_is_noted(): void {
		$this->reload( $this->order )->update_status( 'cancelled' );

		$this->check( Sdk::paid( 'onchain', '50000' ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status() );
		$this->assertNoteContains( 'was paid after the order was cancelled, for a price of 24.50 CHF: the bitcoin reached your wallet. The order is on hold: reinstate it, or refund the customer from your wallet. It was paid on-chain (3 confirmations): 50,000 sats came in time, for its 49,000 sats. 1,000 sats more than its amount reached your wallet: refund them to the customer if needed.', $this->notes( $order ) );
	}

	public function test_an_order_cancelled_then_paid_is_held_once_whatever_comes_after(): void {
		$this->reload( $this->order )->update_status( 'cancelled' );
		$this->check( Sdk::paid() );
		$held  = static fn ( string $note ): bool => str_contains( $note, 'was paid after the order was cancelled' );
		$notes = count( array_filter( $this->notes( $this->order ), $held ) );

		// A check that applied the status, then stopped before its invoice was final, runs again:
		// once as the order is still on hold, once after the merchant cancelled it again.
		$this->services()->settlement->settle( $this->attempt( $this->invoice->id ), Sdk::paid() );
		$this->reload( $this->order )->update_status( 'cancelled', 'Refunded from the wallet.' );
		$this->services()->settlement->settle( $this->attempt( $this->invoice->id ), Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 1, $notes );
		self::assertCount( 1, array_filter( $this->notes( $order ), $held ), 'noted once' );
		self::assertSame( 'cancelled', $order->get_status(), 'what the merchant decided stands' );
	}

	public function test_paid_after_the_order_was_paid_otherwise_is_noted(): void {
		$this->reload( $this->order )->payment_complete( 'card-123' );
		$completed = did_action( 'woocommerce_payment_complete' );

		$this->check( Sdk::paid() );

		$order = $this->reload( $this->order );
		self::assertSame( 'card-123', $order->get_transaction_id() );
		self::assertSame( $completed, did_action( 'woocommerce_payment_complete' ) );
		$this->assertNoteContains( 'This order was paid otherwise already, and its Bitcoin invoice ' . $this->invoice->ref . ' was paid as well: the bitcoin reached your wallet. Refund the customer one of the payments if needed. It was paid in Lightning: 49,000 sats, its amount.', $this->notes( $order ) );
		self::assertStringContainsString( 'warning Invoice ' . $this->invoice->ref . ' paid, its order ' . $order->get_id() . ' was paid already', $this->log()->text() );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $this->attempt( $this->invoice->id )->outcome );
	}

	public function test_expired_fails_the_order(): void {
		$this->clock()->advance( 1100 );

		$this->check( Sdk::expired() );

		$order   = $this->reload( $this->order );
		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( 'failed', $order->get_status() );
		self::assertTrue( $order->needs_payment(), 'the customer can pay again' );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_EXPIRED, $attempt->outcome );
		$this->assertNoteContains( 'The Bitcoin invoice ' . $this->invoice->ref . ' expired unpaid.', $this->notes( $order ) );
	}

	public function test_expired_with_something_received_is_noted_for_the_merchant(): void {
		$this->clock()->advance( 1100 );

		$this->check( Sdk::expired( '20000' ) );

		$order = $this->reload( $this->order );
		self::assertSame( 'on-hold', $order->get_status(), 'the merchant settles what came' );
		self::assertFalse( $order->needs_payment(), 'no "Pay" button' );
		$this->assertNoteContains( 'The Bitcoin invoice ' . $this->invoice->ref . ' ended underpaid: 20,000 of its 49,000 sats came, and reached your wallet. Please settle the difference with the customer.', $this->notes( $order ) );
		self::assertStringContainsString( 'warning Invoice ' . $this->invoice->ref . ' (order ' . $order->get_id() . ') ended underpaid: 20000 of 49000 sats', $this->log()->text() );
	}

	public function test_an_order_whose_invoice_ended_underpaid_takes_its_stock_and_its_customer_is_told(): void {
		$items = $this->order->get_items();
		$item  = reset( $items );
		self::assertInstanceOf( \WC_Order_Item_Product::class, $item );
		self::assertSame( 10, $this->stock( $item->get_product_id() ), 'a pending order holds its stock, never takes it' );
		// The emails this transition sends, made again (the test library removes their hooks).
		self::emails_with_order_details();
		$on_hold = new \WC_Email_Customer_On_Hold_Order();
		new \WC_Email_New_Order();
		reset_phpmailer_instance();
		$this->clock()->advance( 1100 );

		$this->check( Sdk::expired( '20000' ) );

		// WooCommerce takes the stock of an order put on hold (wc_maybe_reduce_stock_levels), and
		// emails its customer that it waits for its payment to be confirmed: the plugin's text
		// follows, saying what came and that the shop will contact them.
		self::assertSame( 9, $this->stock( $item->get_product_id() ) );
		$mailer = tests_retrieve_phpmailer_instance();
		self::assertInstanceOf( \MockPHPMailer::class, $mailer );
		self::assertIsArray( $mailer->mock_sent );
		$subject = $on_hold->format_string( $on_hold->get_default_subject() );
		$bodies  = array();
		foreach ( $mailer->mock_sent as $mail ) {
			if ( 'alice@example.com' === Arrays::at( $mail, 'to', 0, 0 ) && Arrays::at( $mail, 'subject' ) === $subject ) {
				$body     = Arrays::at( $mail, 'body' );
				$bodies[] = is_string( $body ) ? $body : '';
			}
		}
		self::assertNotSame( array(), $bodies, 'WooCommerce\'s "on hold" email' );
		foreach ( $bodies as $body ) {
			self::assertMatchesRegularExpression( '#<p[^>]*>\s*Your Bitcoin payment ended before it was complete: we received 20,000 of the 49,000 sats asked\. We will contact you to settle the difference\.\s*</p>#', $body );
		}
		foreach ( $mailer->mock_sent as $mail ) {
			if ( 'alice@example.com' !== Arrays::at( $mail, 'to', 0, 0 ) ) {
				$body = Arrays::at( $mail, 'body' );
				self::assertStringNotContainsString( 'We will contact you', is_string( $body ) ? $body : '', 'never in the shop\'s own email' );
			}
		}
	}

	public function test_the_on_hold_email_of_an_order_cancelled_then_paid_says_the_payment_was_received(): void {
		$this->reload( $this->order )->update_status( 'cancelled' );
		self::emails_with_order_details();
		new \WC_Email_Customer_On_Hold_Order();
		reset_phpmailer_instance();

		$this->check( Sdk::paid() );

		$mailer = tests_retrieve_phpmailer_instance();
		self::assertInstanceOf( \MockPHPMailer::class, $mailer );
		self::assertIsArray( $mailer->mock_sent );
		$bodies = array();
		foreach ( $mailer->mock_sent as $mail ) {
			$body = Arrays::at( $mail, 'body' );
			if ( 'alice@example.com' === Arrays::at( $mail, 'to', 0, 0 ) && is_string( $body ) ) {
				$bodies[] = $body;
			}
		}
		self::assertNotSame( array(), $bodies, 'WooCommerce\'s "on hold" email to the customer' );
		foreach ( $bodies as $body ) {
			self::assertMatchesRegularExpression( '#<p[^>]*>\s*Your Bitcoin payment has been received\. We will contact you about your order\.\s*</p>#', $body );
		}
	}

	public function test_expired_after_the_order_was_paid_otherwise_leaves_it(): void {
		$this->reload( $this->order )->payment_complete( 'card-123' );

		$this->check( Sdk::expired() );

		$order = $this->reload( $this->order );
		self::assertSame( 'processing', $order->get_status() );
		$this->assertNoteContains( 'expired unpaid', $this->notes( $order ) );
	}

	public function test_a_final_invoice_s_personal_data_is_removed_once_asked(): void {
		$this->services()->invoices->shred_order( $this->order->get_id() );
		self::assertSame( Records::ADDRESS, $this->attempt( $this->invoice->id )->onchain_address(), 'kept while it may be paid' );

		$this->check( Sdk::paid( 'onchain' ) );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( '█', $attempt->onchain_address() );
		self::assertSame( array(), $attempt->txids() );
		self::assertSame( 'processing', $this->reload( $this->order )->get_status() );
	}

	public function test_a_failed_check_is_recorded_and_logged_once(): void {
		$this->sdk()->statuses = array( new NetworkException(), new NetworkException(), Sdk::open( (int) $this->invoice->expires_at ) );

		$this->services()->checker->check( $this->invoice->id, 0, 10 );
		$this->clock()->advance( 10 );
		$this->services()->checker->check( $this->invoice->id, 0, 10 );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( 'network', $attempt->error );
		self::assertSame( 2, $attempt->errors );
		self::assertNull( $attempt->lease_until );
		self::assertSame( 1, substr_count( $this->log()->text(), 'The check of invoice ' . $this->invoice->ref ) );

		$this->clock()->advance( 10 );
		$this->services()->checker->check( $this->invoice->id, 0, 10 );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertSame( '', $attempt->error );
		self::assertSame( 0, $attempt->errors );
		self::assertSame( 'open', $attempt->status_state() );
	}

	public function test_an_update_woocommerce_refused_is_applied_again_later(): void {
		$refuse = static function (): void {
			throw new \RuntimeException( 'The database went away' );
		};
		add_action( 'woocommerce_pre_payment_complete', $refuse );

		$this->check( Sdk::paid() );

		$attempt = $this->attempt( $this->invoice->id );
		self::assertFalse( $attempt->is_final );
		self::assertSame( Errors::APPLY, $attempt->error );
		self::assertStringContainsString( 'error The check of invoice ' . $this->invoice->ref, $this->log()->text() );
		self::assertFalse( $this->reload( $this->order )->is_paid() );

		remove_action( 'woocommerce_pre_payment_complete', $refuse );
		$this->clock()->advance( 10 );
		$this->check( Sdk::paid() );

		self::assertTrue( $this->reload( $this->order )->is_paid() );
		self::assertTrue( $this->attempt( $this->invoice->id )->is_final );
	}

	public function test_a_status_of_an_order_gone_without_its_hooks_is_kept(): void {
		// Removed without WordPress's hooks (a direct cleanup of the database, a filter
		// suppressing them): its invoices stay, and their checks still record what they find.
		remove_action( 'woocommerce_before_delete_order', array( $this->services()->privacy, 'order_deleted' ) );
		remove_action( 'before_delete_post', array( $this->services()->privacy, 'post_deleted' ) );
		$this->reload( $this->order )->delete( true );

		$this->check( Sdk::paid() );

		self::assertSame( InvoiceRecord::OUTCOME_PAID, $this->attempt( $this->invoice->id )->outcome );
		self::assertStringContainsString( 'its order ' . $this->order->get_id() . ' no longer exists (paid)', $this->log()->text() );
	}

	public function test_two_checks_never_run_at_once(): void {
		$now = $this->clock()->now();
		self::assertTrue( $this->services()->invoices->claim( $this->invoice->id, $now, $now + 60, 0 ) );

		self::assertFalse( $this->services()->checker->check( $this->invoice->id, 0, 10 ) );
		self::assertSame( array(), $this->sdk()->status_calls );
	}

	public function test_checks_in_a_web_request_are_spaced(): void {
		$this->check( Sdk::open( (int) $this->invoice->expires_at ) );

		self::assertFalse( $this->services()->checker->check( $this->invoice->id, 6, 10 ) );
		$this->clock()->advance( 6 );
		self::assertTrue( $this->services()->checker->check( $this->invoice->id, 6, 10 ) );
		self::assertCount( 2, $this->sdk()->status_calls );
	}

	/**
	 * A filter of WooCommerce's that fails while refusals are left (another plugin's, its database
	 * gone), then lets $value through.
	 *
	 * @param mixed $value The filtered value.
	 * @return mixed
	 * @throws \RuntimeException While refusals are left.
	 */
	public function refuse( mixed $value ): mixed {
		if ( $this->refusals > 0 ) {
			--$this->refusals;
			throw new \RuntimeException( 'The database went away' );
		}

		return $value;
	}

	/**
	 * WooCommerce's emails with their order details (and the hook where payment methods add their
	 * words, woocommerce_email_before_order_table), as a shop sends them: WC()->mailer() hooks them
	 * as it is first made, and the test library removes the hooks an earlier test added.
	 */
	private static function emails_with_order_details(): void {
		$mailer = WC()->mailer();
		if ( false === has_action( 'woocommerce_email_order_details', array( $mailer, 'order_details' ) ) ) {
			add_action( 'woocommerce_email_order_details', array( $mailer, 'order_details' ), 10, 4 );
		}
	}

	/**
	 * The stock of product $id, as the database holds it now.
	 *
	 * @param int $id The product.
	 */
	private function stock( int $id ): ?int {
		$product = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $product );

		return $product->get_stock_quantity();
	}

	/**
	 * The order's notes about its invoice.
	 *
	 * @return list<string>
	 */
	private function invoice_notes(): array {
		return array_values( array_filter( $this->notes( $this->order ), fn ( string $note ): bool => str_contains( $note, $this->invoice->ref ) ) );
	}

	/**
	 * Checks the invoice, the SDK answering $status.
	 *
	 * @param PaymentStatus $status The status.
	 */
	private function check( PaymentStatus $status ): void {
		$this->sdk()->statuses = array( $status );
		$this->services()->checker->check( $this->invoice->id, 0, 10 );
	}
}
