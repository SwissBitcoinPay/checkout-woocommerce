<?php
/**
 * Paying an order: its invoice.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Payments\PaymentFailure;
use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Tests\Support\Arrays;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\CheckoutUnavailableException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidOptionException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RateException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;

/**
 * Checkout makes one invoice per order: its reference stored first, the SDK's invoice stored as
 * its JSON, its checks scheduled; every error of the SDK becomes what the customer is told.
 */
final class CheckoutTest extends TestCase {

	public function test_checkout_makes_an_invoice_and_goes_to_the_payment_page(): void {
		$order = $this->order();

		$result = $this->gateway()->process_payment( $order->get_id() );

		$order = $this->reload( $order );
		self::assertSame( 'success', $result['result'] );
		self::assertSame( $order->get_checkout_payment_url( true ), $result['redirect'] );
		self::assertCount( 1, $this->sdk()->create_calls );
		$params = $this->sdk()->create_calls[0];
		$ref    = $params['ref'] ?? null;
		self::assertIsString( $ref );
		self::assertMatchesRegularExpression( '/^wc-' . $order->get_id() . '-[0-9a-f]{32}$/D', $ref );
		self::assertSame(
			array(
				'amount'   => '24.5',
				'currency' => 'CHF',
			),
			$params['fiat']
		);
		self::assertSame( 900, $params['durationSeconds'] );
		self::assertSame( 'Test Shop · Order ' . $order->get_order_number(), $params['note'] );
		self::assertArrayNotHasKey( 'sats', $params );
		self::assertArrayNotHasKey( 'checkout', $params, 'no redirect to a checkout link' );

		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertSame( $params['ref'], $attempt->ref );
		self::assertSame( InvoiceRecord::STATE_INVOICE, $attempt->state );
		self::assertSame( $params['ref'], $order->get_meta( '_sattleme_ref' ) );
		self::assertSame( 'pending', $order->get_status() );
		self::assertSame( 'sattleme', $order->get_payment_method() );
		$this->assertNoteContains( 'Bitcoin invoice ' . $attempt->ref . ' created: 49,000 sats, payable in Lightning or on-chain', $this->notes( $order ) );
	}

	public function test_the_invoice_is_for_what_the_order_owes(): void {
		$order  = $this->order();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10.00',
				'reason'   => 'An item removed by the merchant',
			)
		);
		self::assertInstanceOf( \WC_Order_Refund::class, $refund );

		$this->invoice_for( $this->reload( $order ) );

		self::assertSame(
			array(
				'amount'   => '14.5',
				'currency' => 'CHF',
			),
			$this->sdk()->create_calls[0]['fiat'] ?? null,
			'its total less what was refunded of it'
		);
	}

	public function test_an_order_refunded_in_full_is_not_payable_in_bitcoin(): void {
		$order  = $this->order();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '24.50',
			)
		);
		self::assertInstanceOf( \WC_Order_Refund::class, $refund );

		try {
			$this->invoice_for( $this->reload( $order ) );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure ) {
			self::assertSame( array(), $this->sdk()->create_calls );
		}
	}

	public function test_the_invoice_is_stored_as_the_sdk_s_json(): void {
		$order                  = $this->order();
		$made                   = null;
		$now                    = $this->clock()->now();
		$this->sdk()->creates[] = static function ( array $params ) use ( &$made, $now ): Invoice {
			$made = Sdk::invoice( $params, $now );

			return $made;
		};

		$attempt = $this->invoice_for( $order );

		self::assertInstanceOf( Invoice::class, $made );
		self::assertSame( $made->toArray(), $attempt->invoice );
		self::assertSame( $made->expiresAt, $attempt->expires_at );
		self::assertTrue( $attempt->has_invoice() );
		self::assertFalse( $attempt->is_declared() );
	}

	public function test_the_reference_is_stored_before_the_sdk_is_asked(): void {
		$order                  = $this->order();
		$stored                 = null;
		$now                    = $this->clock()->now();
		$this->sdk()->creates[] = function ( array $params ) use ( &$stored, $now ): Invoice {
			$stored = $this->services()->invoices->find_by_ref( is_string( $params['ref'] ?? null ) ? $params['ref'] : '' );

			return Sdk::invoice( $params, $now );
		};

		$this->invoice_for( $order );

		self::assertInstanceOf( InvoiceRecord::class, $stored );
		self::assertSame( InvoiceRecord::STATE_CREATING, $stored->state );
		self::assertNull( $stored->invoice );
	}

	public function test_the_checks_of_the_invoice_are_scheduled(): void {
		$attempt = $this->invoice_for( $this->order() );

		self::assertSame( 32, strlen( $attempt->chain ) );
		self::assertNotNull( $attempt->next_check_at );
		self::assertSame( $this->clock()->now() + 60, $attempt->next_check_at );
		self::assertTrue( as_has_scheduled_action( Scheduler::CHECK_HOOK, array( $attempt->id, $attempt->chain ), Scheduler::GROUP ) );
	}

	public function test_an_order_keeps_its_invoice_while_it_may_be_paid(): void {
		$order = $this->order();
		$first = $this->invoice_for( $order );

		$again = $this->invoice_for( $this->reload( $order ) );
		$this->gateway()->process_payment( $order->get_id() );

		self::assertSame( $first->id, $again->id );
		self::assertCount( 1, $this->sdk()->create_calls );
		self::assertCount( 1, $this->services()->invoices->for_order( $order->get_id() ) );
	}

	public function test_past_its_end_an_invoice_is_checked_before_another_is_made(): void {
		$order              = $this->order();
		$first              = $this->invoice_for( $order );
		$this->clock()->now = $first->end() + 1;
		// No check ran since (WP-Cron late, the page closed): it was paid in its last seconds.
		$this->sdk()->statuses = array( Sdk::paid() );

		$again = $this->invoice_for( $this->reload( $order ) );

		self::assertSame( $first->id, $again->id, 'no second invoice' );
		self::assertCount( 1, $this->sdk()->create_calls );
		self::assertCount( 1, $this->sdk()->status_calls, 'checked first' );
		self::assertTrue( $this->reload( $order )->is_paid() );
	}

	public function test_past_its_end_an_invoice_found_unpaid_gives_way_to_a_new_one(): void {
		$order                 = $this->order();
		$first                 = $this->invoice_for( $order );
		$this->clock()->now    = $first->end() + 1;
		$this->sdk()->statuses = array( Sdk::expired() );

		$second = $this->invoice_for( $this->reload( $order ) );

		self::assertNotSame( $first->id, $second->id );
		self::assertTrue( $this->attempt( $first->id )->ended_unpaid() );
		self::assertCount( 2, $this->sdk()->create_calls );
	}

	public function test_past_its_end_an_invoice_whose_check_failed_is_shown_as_it_is(): void {
		$order                 = $this->order();
		$first                 = $this->invoice_for( $order );
		$this->clock()->now    = $first->end() + 1;
		$this->sdk()->statuses = array( new NetworkException() );

		$again = $this->invoice_for( $this->reload( $order ) );

		self::assertSame( $first->id, $again->id, 'never a second invoice before its final status is known' );
		self::assertFalse( $again->is_final );
		self::assertCount( 1, $this->sdk()->create_calls );
	}

	public function test_an_invoice_that_ended_underpaid_gives_no_new_one(): void {
		$order                 = $this->order();
		$first                 = $this->invoice_for( $order );
		$this->clock()->now    = $first->end();
		$this->sdk()->statuses = array( Sdk::expired( '20000' ) );
		$this->services()->checker->check( $first->id, 0, 10 );
		self::assertSame( 'on-hold', $this->reload( $order )->get_status(), 'for the merchant to settle' );
		self::assertFalse( $this->reload( $order )->needs_payment() );

		try {
			$this->invoice_for( $this->reload( $order ) );
			self::fail( 'An order on hold takes no payment' );
		} catch ( \Sattleme\WooCommerce\Payments\PaymentFailure $e ) {
			self::assertSame( 'This order can no longer be paid.', $e->getMessage() );
		}

		self::assertSame( $first->id, $this->services()->invoices->latest_for_order( $order->get_id() )?->id, 'what came is the merchant\'s to settle' );
		self::assertCount( 1, $this->sdk()->create_calls );
	}

	public function test_a_new_invoice_once_the_last_one_ended_unpaid(): void {
		$order                   = $this->order();
		$first                   = $this->invoice_for( $order );
		$this->sdk()->statuses[] = Sdk::expired();
		$this->clock()->advance( 900 + 120 );
		$this->services()->checker->check( $first->id, 0, 10 );
		$order = $this->reload( $order );
		self::assertSame( 'failed', $order->get_status() );

		$second = $this->invoice_for( $order );

		self::assertNotSame( $first->id, $second->id );
		self::assertNotSame( $first->ref, $second->ref );
		self::assertCount( 2, $this->sdk()->create_calls );
		$order = $this->reload( $order );
		self::assertSame( 'pending', $order->get_status() );
		self::assertSame( $second->ref, $order->get_meta( '_sattleme_ref' ) );
	}

	public function test_an_order_already_paid_is_not_payable(): void {
		$order = $this->order();
		$order->payment_complete( 'other' );

		$this->expectException( PaymentFailure::class );
		$this->expectExceptionMessage( 'This order can no longer be paid.' );

		$this->invoice_for( $this->reload( $order ) );
	}

	public function test_without_a_key_bitcoin_is_unavailable(): void {
		$order = $this->order();
		$this->services()->keys->delete();

		try {
			$this->invoice_for( $order );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure $e ) {
			self::assertSame( 'Bitcoin payments are not available right now. Please choose another payment method or contact us.', $e->getMessage() );
		}
		self::assertSame( array(), $this->sdk()->create_calls );
		self::assertStringContainsString( 'error No invoice for order ' . $order->get_id() . ': no integration key is set', $this->log()->text() );
	}

	public function test_a_total_the_currency_cannot_hold_is_refused(): void {
		update_option( 'woocommerce_currency', 'JPY' );
		$order = $this->order( $this->product( '1500.50' ) );

		try {
			$this->invoice_for( $order );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure $e ) {
			self::assertSame( 'This order cannot be paid with Bitcoin. Please choose another payment method.', $e->getMessage() );
		}
		self::assertSame( array(), $this->sdk()->create_calls );
	}

	/**
	 * @dataProvider errors
	 *
	 * @param \Throwable $error   What createInvoice throws.
	 * @param string     $message What the customer is told.
	 * @param string     $state   The attempt's state.
	 * @param string     $level   The log's level.
	 */
	public function test_each_error_of_the_sdk( \Throwable $error, string $message, string $state, string $level ): void {
		$order                  = $this->order();
		$this->sdk()->creates[] = $error;

		try {
			$this->invoice_for( $order );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure $e ) {
			self::assertSame( $message, $e->getMessage() );
		}

		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertSame( $state, $attempt->state );
		self::assertFalse( $attempt->has_invoice() );
		self::assertSame( 'pending', $this->reload( $order )->get_status() );
		self::assertStringContainsString( $level . ' No invoice for order ' . $order->get_id() . ' (' . $attempt->ref . '): ', $this->log()->text() );
		self::assertStringNotContainsString( self::KEY, $this->log()->text() );
		self::assertStringNotContainsString( '17wserpSaW', $e->getMessage() );
	}

	/**
	 * Every error createInvoice may throw, and what the customer is told.
	 *
	 * @return array<string, array{\Throwable, string, string, string}>
	 */
	public static function errors(): array {
		$again    = 'We could not create your Bitcoin invoice right now. Please try again in a moment.';
		$order    = 'This order cannot be paid with Bitcoin. Please choose another payment method.';
		$merchant = 'Bitcoin payments are not available right now. Please choose another payment method or contact us.';

		return array(
			'network'                  => array( new NetworkException(), $again, InvoiceRecord::STATE_RETRY, 'warning' ),
			'rate'                     => array( new RateException(), $again, InvoiceRecord::STATE_RETRY, 'warning' ),
			'onchain_unavailable'      => array( new OnchainUnavailableException(), $again, InvoiceRecord::STATE_RETRY, 'warning' ),
			'channel_not_found'        => array( new ChannelNotFoundException(), $again, InvoiceRecord::STATE_RETRY, 'warning' ),
			'a call out of time'       => array( new CancelledException(), $again, InvoiceRecord::STATE_RETRY, 'warning' ),
			'invalid_order'            => array( new InvalidOrderException( 'fiat' ), $order, InvoiceRecord::STATE_FAILED, 'warning' ),
			'unsupported_currency'     => array( new UnsupportedCurrencyException( 'CHF' ), $order, InvoiceRecord::STATE_FAILED, 'warning' ),
			'invalid_key'              => array( new InvalidKeyException(), $merchant, InvoiceRecord::STATE_FAILED, 'error' ),
			'revoked'                  => array( new RevokedException(), $merchant, InvoiceRecord::STATE_FAILED, 'error' ),
			'channel_not_found, newer' => array( new ChannelNotFoundException( true ), $merchant, InvoiceRecord::STATE_FAILED, 'error' ),
			'invalid_invoice'          => array( new InvalidInvoiceException(), $merchant, InvoiceRecord::STATE_FAILED, 'error' ),
			'invalid_option'           => array( new InvalidOptionException( 'durationSeconds' ), $merchant, InvoiceRecord::STATE_FAILED, 'error' ),
			'checkout_unavailable'     => array( new CheckoutUnavailableException(), $merchant, InvoiceRecord::STATE_FAILED, 'error' ),
			'the server\'s PHP'        => array( new UnsupportedEnvironmentException( 'ext-bcmath is missing' ), $merchant, InvoiceRecord::STATE_RETRY, 'error' ),
			'a fault'                  => array( new \RuntimeException( 'sattleme_ck_' . str_repeat( 'x', 20 ) ), $merchant, InvoiceRecord::STATE_RETRY, 'error' ),
		);
	}

	public function test_a_retry_asks_again_with_the_same_reference(): void {
		$order                  = $this->order();
		$this->sdk()->creates[] = new NetworkException();
		$this->sdk()->creates[] = static fn ( array $params ): Invoice => Sdk::invoice( $params, time() );
		try {
			$this->invoice_for( $order );
		} catch ( PaymentFailure ) {
			// Told to try again.
		}

		$attempt = $this->invoice_for( $this->reload( $order ) );

		self::assertCount( 2, $this->sdk()->create_calls );
		self::assertSame( $this->sdk()->create_calls[0]['ref'], $this->sdk()->create_calls[1]['ref'] );
		self::assertSame( $this->sdk()->create_calls[0]['ref'], $attempt->ref );
		self::assertCount( 1, $this->services()->invoices->for_order( $order->get_id() ) );
	}

	public function test_after_a_refusal_a_new_reference(): void {
		$order                  = $this->order();
		$this->sdk()->creates[] = new InvalidOrderException( 'fiat' );
		$this->sdk()->creates[] = static fn ( array $params ): Invoice => Sdk::invoice( $params, time() );
		try {
			$this->invoice_for( $order );
		} catch ( PaymentFailure ) {
			// Refused.
		}

		$attempt = $this->invoice_for( $this->reload( $order ) );

		self::assertNotSame( $this->sdk()->create_calls[0]['ref'], $this->sdk()->create_calls[1]['ref'] );
		self::assertSame( $this->sdk()->create_calls[1]['ref'], $attempt->ref );
		self::assertCount( 2, $this->services()->invoices->for_order( $order->get_id() ) );
	}

	public function test_an_invoice_handed_out_again_is_checked_and_shown_while_open(): void {
		$order                   = $this->order();
		$now                     = $this->clock()->now();
		$this->sdk()->creates[]  = static function ( array $params ) use ( $now ): never {
			throw new InvoicePendingException( Sdk::declared( Sdk::invoice( $params, $now - 60 ), $now - 30 ), null );
		};
		$this->sdk()->statuses[] = Sdk::open( $now + 840 );

		$attempt = $this->invoice_for( $order );

		self::assertTrue( $attempt->is_declared() );
		self::assertSame( 'open', $attempt->status_state() );
		self::assertCount( 1, $this->sdk()->status_calls, 'checked at once' );
		self::assertSame( $attempt->invoice, $this->sdk()->status_calls[0] );
		self::assertSame( 'open', \Sattleme\WooCommerce\Page\Phase::of( $attempt, 'pending', false, $now ) );
	}

	public function test_an_invoice_handed_out_again_being_confirmed_is_not_shown_to_pay(): void {
		$order                   = $this->order();
		$now                     = $this->clock()->now();
		$this->sdk()->creates[]  = static function ( array $params ) use ( $now ): never {
			throw new InvoicePendingException( Sdk::declared( Sdk::invoice( $params, $now - 600 ), $now - 300 ), null );
		};
		$this->sdk()->statuses[] = Sdk::unconfirmed( 1 );

		$attempt = $this->invoice_for( $order );

		self::assertSame( 'unconfirmed', \Sattleme\WooCommerce\Page\Phase::of( $attempt, 'pending', false, $now ) );
		$this->assertNoteContains( 'its on-chain payment is being confirmed (1 of 3 confirmations', $this->notes( $this->reload( $order ) ) );
	}

	public function test_an_invoice_handed_out_again_whose_check_failed_waits(): void {
		$order                   = $this->order();
		$now                     = $this->clock()->now();
		$this->sdk()->creates[]  = static function ( array $params ) use ( $now ): never {
			throw new InvoicePendingException( Sdk::declared( Sdk::invoice( $params, $now - 60 ), $now - 30 ), null );
		};
		$this->sdk()->statuses[] = new NetworkException();

		$attempt = $this->invoice_for( $order );

		self::assertNull( $attempt->status_state() );
		self::assertSame( 'checking', \Sattleme\WooCommerce\Page\Phase::of( $attempt, 'pending', false, $now ) );
	}

	public function test_an_invoice_handed_out_again_for_another_price_is_never_shown(): void {
		$order                  = $this->order();
		$now                    = $this->clock()->now();
		$earlier                = null;
		$this->sdk()->creates[] = static function ( array $params ) use ( &$earlier, $now ): never {
			// The SDK made the invoice, then the call failed: the customer never saw it.
			$earlier = Sdk::declared( Sdk::invoice( $params, $now ), $now );
			throw new NetworkException();
		};
		$this->sdk()->creates[] = static function () use ( &$earlier ): never {
			if ( null === $earlier ) {
				throw new \LogicException( 'No invoice declared yet' );
			}
			throw new InvoicePendingException( $earlier, null );
		};
		$this->sdk()->creates[] = static fn ( array $params ): Invoice => Sdk::invoice( $params, $now );
		try {
			$this->invoice_for( $order );
			self::fail( 'An invoice was made' );
		} catch ( PaymentFailure ) {
			// Told to try again.
		}
		// Meanwhile the merchant added an item to the order: 49.00 CHF now, not 24.50.
		$order = $this->reload( $order );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals( false );
		$order->save();

		try {
			$this->invoice_for( $this->reload( $order ) );
			self::fail( 'The invoice of the earlier price was shown' );
		} catch ( PaymentFailure $e ) {
			self::assertSame( 'We could not create your Bitcoin invoice right now. Please try again in a moment.', $e->getMessage() );
		}

		$failed = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $failed );
		self::assertSame( $this->sdk()->create_calls[0]['ref'], $failed->ref );
		self::assertSame( InvoiceRecord::STATE_FAILED, $failed->state );
		self::assertSame( Errors::MISMATCH, $failed->error );
		self::assertFalse( $failed->has_invoice() );
		self::assertTrue( $failed->is_final );
		self::assertStringContainsString( 'warning Invoice ' . $failed->ref . ' (order ' . $order->get_id() . ') is not of the order\'s price, 49 CHF: never shown', $this->log()->text() );

		$attempt = $this->invoice_for( $this->reload( $order ) );

		self::assertNotSame( $failed->ref, $attempt->ref, 'a new reference, a new invoice' );
		self::assertSame(
			array(
				'amount'   => '49',
				'currency' => 'CHF',
			),
			$this->sdk()->create_calls[2]['fiat']
		);
		self::assertSame( '49.00', Arrays::at( $attempt->invoice, 'fiat', 'amount' ) );
	}

	public function test_an_order_paid_already_at_an_earlier_price_is_put_on_hold(): void {
		$order                  = $this->order();
		$now                    = $this->clock()->now();
		$this->sdk()->creates[] = static function ( array $params ) use ( $now ): never {
			$params['fiat'] = array(
				'amount'   => '20',
				'currency' => 'CHF',
			);
			throw new OrderPaidException( Sdk::declared( Sdk::invoice( $params, $now - 600 ), $now - 300 ), Sdk::paid( 'lightning' ) );
		};
		$completed              = did_action( 'woocommerce_payment_complete' );

		$result = $this->gateway()->process_payment( $order->get_id() );

		$order   = $this->reload( $order );
		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertSame( 'on-hold', $order->get_status() );
		self::assertFalse( $order->is_paid() );
		self::assertSame( $completed, did_action( 'woocommerce_payment_complete' ) );
		self::assertSame( $attempt->ref, $order->get_transaction_id() );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $attempt->outcome );
		$this->assertNoteContains( 'for a price of 20.00 CHF. The order now owes 24.50 CHF', $this->notes( $order ) );
		self::assertSame( $this->gateway()->get_return_url( $order ), $result['redirect'] );
	}

	public function test_an_order_paid_already_is_completed(): void {
		$order                  = $this->order();
		$now                    = $this->clock()->now();
		$this->sdk()->creates[] = static function ( array $params ) use ( $now ): never {
			throw new OrderPaidException( Sdk::declared( Sdk::invoice( $params, $now - 600 ), $now - 300 ), Sdk::paid( 'lightning' ) );
		};

		$result = $this->gateway()->process_payment( $order->get_id() );

		$order   = $this->reload( $order );
		$attempt = $this->services()->invoices->latest_for_order( $order->get_id() );
		self::assertNotNull( $attempt );
		self::assertTrue( $order->is_paid() );
		self::assertSame( 'processing', $order->get_status() );
		self::assertSame( $attempt->ref, $order->get_transaction_id() );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $attempt->outcome );
		self::assertSame( $this->gateway()->get_return_url( $order ), $result['redirect'] );
		self::assertSame( array(), $this->sdk()->status_calls, 'its status came with it' );
	}
}
