<?php
/**
 * Makes the invoice of an order.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Orders\LockUnavailable;
use Sattleme\WooCommerce\Orders\OrderLock;
use Sattleme\WooCommerce\Orders\Owed;
use Sattleme\WooCommerce\Orders\StockHold;
use Sattleme\WooCommerce\Orders\StockUnavailable;
use Sattleme\WooCommerce\Page\Amounts;
use Sattleme\WooCommerce\Sdk\ClientFactory;
use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Sdk\Prices;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Settings\KeyStore;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Dates;
use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\TimeoutCancellation;

defined( 'ABSPATH' ) || exit;

/**
 * The invoice of an order, as its customer checks out or pays it again: the one it has that may
 * still be paid, else a new one.
 *
 * The order is locked in the database while its invoice is made (OrderLock), so that two
 * requests for it (a double click, two PHP workers) never make two invoices: the second waits,
 * then gets the first one's. Each attempt has a random reference, stored before the SDK is
 * called; an attempt that failed when an invoice may exist for its reference (the network, the
 * time) asks again with that reference, and the SDK hands that invoice out (InvoicePending), or
 * reports it paid (OrderPaid). One payable invoice per order: another one only once the last one
 * ended unpaid, which only a check made after its end tells (it checks one whose end passed).
 */
class InvoiceCreator {

	/** Seconds a request waits for another one making the invoice of the same order. */
	public const LOCK_WAIT = 25;

	/**
	 * A request that waited longer than this (seconds) for another one, which made no invoice,
	 * makes none either: two calls to the SDK in a row could outlast the request.
	 */
	public const RETRY_AFTER_WAIT = 5;

	/** The check of an earlier invoice the SDK hands out again, in seconds. */
	public const PENDING_CHECK_TIMEOUT = 10;

	/**
	 * A creator.
	 *
	 * @param InvoiceRepository $invoices  The invoices.
	 * @param ClientFactory     $clients   The SDK's clients.
	 * @param KeyStore          $keys      The integration key.
	 * @param GatewaySettings   $settings  The payment method's settings.
	 * @param OrderLock         $lock      The order's lock.
	 * @param StockHold         $stock     The order's stock hold.
	 * @param Checker           $checker   The checks.
	 * @param Scheduler         $scheduler The background checks.
	 * @param Clock             $clock     The clock.
	 * @param Logger            $logger    The log.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly ClientFactory $clients,
		private readonly KeyStore $keys,
		private readonly GatewaySettings $settings,
		private readonly OrderLock $lock,
		private readonly StockHold $stock,
		private readonly Checker $checker,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {}

	/**
	 * The invoice to show for $order: the one it has that may still be paid or confirmed, else a
	 * new one (its row holds the SDK's invoice).
	 *
	 * @param \WC_Order $order The order.
	 * @throws PaymentFailure With what to tell the customer.
	 */
	public function invoice_for( \WC_Order $order ): InvoiceRecord {
		$order_id = $order->get_id();
		try {
			return $this->lock->run(
				$order_id,
				self::LOCK_WAIT,
				fn ( float $waited ): InvoiceRecord => $this->locked( $order_id, $waited )
			);
		} catch ( LockUnavailable $e ) {
			$this->logger->warning( sprintf( 'No invoice for order %d: %s', $order_id, $e->getMessage() ) );

			throw PaymentFailure::transient();
		}
	}

	/**
	 * The invoice of order $order_id, its lock held.
	 *
	 * @param int   $order_id The order.
	 * @param float $waited   Seconds this request waited for the lock.
	 * @throws PaymentFailure With what to tell the customer.
	 */
	private function locked( int $order_id, float $waited ): InvoiceRecord {
		// Read again under the lock: another request may have changed it meanwhile.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw PaymentFailure::not_payable();
		}
		$now  = $this->clock->now();
		$live = $this->invoices->live_for_order( $order_id, $now );
		if ( null !== $live && $live->has_invoice() && ! $live->is_final && $now >= $live->end() ) {
			// Its end passed and no check found its final status yet: only a check tells whether
			// it ended unpaid (a payment of its last seconds, an on-chain one dated in time).
			$this->checker->check( $live->id, 0, self::PENDING_CHECK_TIMEOUT );
			$live = $this->invoices->live_for_order( $order_id, $now );
		}
		if ( null !== $live && $live->has_invoice() && ( ! $live->is_final || $order->needs_payment() ) ) {
			// One payable invoice per order: this one, until it ended unpaid. A final one (paid at
			// another price, underpaid, given up on) is shown as it ended, for the merchant to settle.
			return $live;
		}
		if ( ! $order->needs_payment() ) {
			throw PaymentFailure::not_payable();
		}
		if ( $waited > self::RETRY_AFTER_WAIT ) {
			throw PaymentFailure::transient();
		}
		$key = $this->keys->key();
		if ( '' === $key ) {
			$this->logger->error( sprintf( 'No invoice for order %d: no integration key is set', $order_id ) );

			throw PaymentFailure::unavailable();
		}
		$currency = $order->get_currency();
		// What the order owes: its total, less what was refunded of it meanwhile.
		$amount = Prices::amount( Owed::amount( $order ), $currency );
		if ( null === $amount ) {
			$this->logger->warning( sprintf( 'No invoice for order %d: what it owes in %s is not a price the SDK takes', $order_id, $currency ) );

			throw PaymentFailure::order();
		}
		$latest = $this->invoices->latest_for_order( $order_id );
		if ( null !== $latest && in_array( $latest->state, array( InvoiceRecord::STATE_RETRY, InvoiceRecord::STATE_CREATING ), true ) ) {
			// An invoice may exist for its reference: asked again with it.
			$attempt_id = $latest->id;
			$ref        = $latest->ref;
		} else {
			$ref        = Refs::new_ref( $order_id );
			$attempt_id = $this->invoices->insert_attempt( $order_id, $ref, $now );
		}
		$this->prepare( $order, $ref, $now );

		$minutes = $this->settings->minutes();
		try {
			$hold_left = $this->stock->seconds_left( $order, Durations::wanted( $minutes ) );
		} catch ( StockUnavailable $e ) {
			$this->invoices->mark_failed( $attempt_id, 'stock' );
			$this->logger->info( sprintf( 'No invoice for order %d: its products are no longer in stock', $order_id ) );

			$failure = PaymentFailure::stock( $e->getMessage() );

			throw $failure;
		}
		$left     = self::earliest( $hold_left, $this->stock->cancellation_left() );
		$deadline = null === $left ? null : $now + $left;
		$seconds  = Durations::seconds( $minutes, $left );
		if ( null === $seconds ) {
			$this->invoices->mark_failed( $attempt_id, Errors::DEADLINE );
			$this->logger->info( sprintf( 'No invoice for order %d: %d s are left before its stock hold ends', $order_id, (int) $left ) );

			throw PaymentFailure::too_late();
		}
		$params  = array(
			'ref'             => $ref,
			'fiat'            => array(
				'amount'   => $amount,
				'currency' => $currency,
			),
			'durationSeconds' => $seconds,
			'note'            => Refs::note( get_bloginfo( 'name' ), $order->get_order_number() ),
		);
		$pending = false;
		try {
			$made    = $this->clients->for_key( $key )->create_invoice( $params, new TimeoutCancellation( Durations::CREATE_TIMEOUT ) );
			$data    = $made->toArray();
			$expires = $made->expiresAt; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The SDK's Invoice.
		} catch ( InvoicePendingException $e ) {
			// An invoice of this reference that may still be paid (an earlier try failed after
			// the SDK made it): this attempt's, checked below and shown only while it is open.
			$declared = $e->getInvoice();
			$data     = $declared->toArray();
			$expires  = $declared->expiresAt; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The SDK's DeclaredInvoice.
			$pending  = true;
		} catch ( OrderPaidException $e ) {
			return $this->already_paid( $attempt_id, $order, $e, $now );
		} catch ( \Throwable $e ) {
			$failure = $this->failure( $attempt_id, $order_id, $ref, $e );

			throw $failure;
		}
		if ( ! Prices::is_price_of( $data['fiat'] ?? null, $amount, $currency ) ) {
			// An earlier try of this attempt made it for another price (the order changed since):
			// never shown, it would pay the order's old price. It was never shown either (its try
			// stored nothing): the next attempt has a reference of its own.
			$this->invoices->mark_failed( $attempt_id, Errors::MISMATCH );
			$this->logger->warning( sprintf( 'Invoice %s (order %d) is not of the order\'s price, %s %s: never shown', $ref, $order_id, $amount, $currency ) );

			throw PaymentFailure::transient();
		}
		if ( ! Durations::ends_in_time( $expires, $deadline ) ) {
			// The clock of the merchant's Lightning address is ahead (or an earlier try's invoice
			// ends later): never shown, it would be payable past the order's deadline. The next
			// attempt has a reference of its own.
			$this->invoices->mark_failed( $attempt_id, Errors::DEADLINE );
			$this->logger->warning( sprintf( 'Invoice %s (order %d) ends at %d, after the order\'s deadline (%d): never shown', $ref, $order_id, $expires, (int) $deadline ) );

			throw PaymentFailure::too_late();
		}
		$this->invoices->store_invoice( $attempt_id, $data, $expires, $now );
		$record = $this->invoices->find( $attempt_id );
		if ( null === $record ) {
			throw PaymentFailure::transient();
		}
		$order->add_order_note( $this->created_note( $record ), 0, false, array( 'note_group' => 'payment' ) );
		$this->logger->info( sprintf( 'Invoice %s made for order %d: %s sats, until %d', $ref, $order_id, $record->sats(), $expires ) );
		$this->scheduler->start_chain( $record );
		if ( $pending ) {
			$this->checker->check( $record->id, 0, self::PENDING_CHECK_TIMEOUT );
		}

		// As stored now: its checks scheduled, a pending one's status found.
		return $this->invoices->find( $attempt_id ) ?? $record;
	}

	/**
	 * The order is about to be paid with this method: the method set, a failed order pending
	 * again, the attempt's reference noted, and the order saved now, so that WooCommerce's
	 * cancellation of unpaid orders counts from now.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $ref   The attempt's reference.
	 * @param int       $now   The time, in Unix seconds.
	 */
	private function prepare( \WC_Order $order, string $ref, int $now ): void {
		if ( GatewaySettings::GATEWAY_ID !== $order->get_payment_method() ) {
			$order->set_payment_method( GatewaySettings::GATEWAY_ID );
			$order->set_payment_method_title( $this->settings->title() );
		}
		$order->update_meta_data( '_sattleme_ref', $ref );
		$order->set_date_modified( $now );
		if ( $order->has_status( 'failed' ) ) {
			$order->set_status( 'pending', __( 'The customer asked for a new Bitcoin invoice.', 'sattleme-for-woocommerce' ) );
		}
		$order->save();
	}

	/**
	 * An invoice of this reference was paid already (the SDK's OrderPaid): stored, and its
	 * payment applied to the order as a check would.
	 *
	 * @param int                $attempt_id The attempt's id.
	 * @param \WC_Order          $order      The order.
	 * @param OrderPaidException $paid       The SDK's OrderPaid.
	 * @param int                $now        The time, in Unix seconds.
	 * @throws PaymentFailure When the attempt cannot be read again.
	 */
	private function already_paid( int $attempt_id, \WC_Order $order, OrderPaidException $paid, int $now ): InvoiceRecord {
		$declared = $paid->getInvoice();
		$this->invoices->store_invoice( $attempt_id, $declared->toArray(), $declared->expiresAt, $now ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The SDK's DeclaredInvoice.
		$this->checker->apply_known( $attempt_id, $paid->getStatus() );
		$record = $this->invoices->find( $attempt_id );
		if ( null === $record ) {
			throw PaymentFailure::transient();
		}
		$this->logger->info( sprintf( 'Invoice %s of order %d was paid already', $record->ref, $order->get_id() ) );

		return $record;
	}

	/**
	 * Records why no invoice was made, logs it, and gives what to tell the customer.
	 *
	 * @param int        $attempt_id The attempt's id.
	 * @param int        $order_id   The order.
	 * @param string     $ref        The attempt's reference.
	 * @param \Throwable $error      The error.
	 */
	private function failure( int $attempt_id, int $order_id, string $ref, \Throwable $error ): PaymentFailure {
		$code = Errors::code( $error );
		if ( $error instanceof SattlemeException && ! $error->isRetryable() ) {
			$this->invoices->mark_failed( $attempt_id, $code );
		} else {
			// The network, the time, or a fault after the SDK answered: an invoice may exist.
			$this->invoices->mark_retry( $attempt_id, $code );
		}
		$message = sprintf( 'No invoice for order %d (%s): %s', $order_id, $ref, Errors::describe( $error ) );
		if ( Errors::is_transient( $error ) ) {
			$this->logger->warning( $message );

			return PaymentFailure::transient();
		}
		if ( Errors::is_order_error( $error ) ) {
			$this->logger->warning( $message );

			return PaymentFailure::order();
		}
		// A key that is invalid or was revoked, the merchant's Lightning address answering wrongly,
		// the server's PHP, or a fault: the merchant has to act.
		$this->logger->error( $message );

		return PaymentFailure::unavailable();
	}

	/**
	 * The order's note of a new invoice: its reference, amount, methods and end.
	 *
	 * @param InvoiceRecord $record The attempt.
	 */
	private function created_note( InvoiceRecord $record ): string {
		$lightning = null !== $record->bolt11();
		$onchain   = null !== $record->onchain_address();
		if ( $lightning && $onchain ) {
			$methods = __( 'in Lightning or on-chain', 'sattleme-for-woocommerce' );
		} elseif ( $onchain ) {
			$methods = __( 'on-chain', 'sattleme-for-woocommerce' );
		} else {
			$methods = __( 'in Lightning', 'sattleme-for-woocommerce' );
		}

		return sprintf(
			/* translators: 1: the invoice's reference, 2: its amount in sats, 3: "in Lightning", "on-chain" or both, 4: the date and time it ends. */
			__( 'Bitcoin invoice %1$s created: %2$s sats, payable %3$s until %4$s.', 'sattleme-for-woocommerce' ),
			$record->ref,
			Amounts::sats( $record->sats() ),
			$methods,
			Dates::date_time( $record->expires_at ?? 0 )
		);
	}

	/**
	 * The earliest of two seconds left, null when neither bounds.
	 *
	 * @param int|null $a One.
	 * @param int|null $b The other.
	 */
	private static function earliest( ?int $a, ?int $b ): ?int {
		if ( null === $a ) {
			return $b;
		}

		return null === $b ? $a : min( $a, $b );
	}
}
