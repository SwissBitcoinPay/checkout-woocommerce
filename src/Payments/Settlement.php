<?php
/**
 * Applies what a check found to the order.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

use Sattleme\WooCommerce\Orders\Notes;
use Sattleme\WooCommerce\Orders\OrderLock;
use Sattleme\WooCommerce\Orders\Owed;
use Sattleme\WooCommerce\Orders\StockHold;
use Sattleme\WooCommerce\Page\Amounts;
use Sattleme\WooCommerce\Sdk\Prices;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Stores what a check found and updates the order, holding the order's lock, through
 * WooCommerce's own paths:
 *
 * - paid: the order's payment is complete ($order->payment_complete(), the attempt's reference
 *   as the transaction id), exactly once, what came noted when it is not the invoice's amount
 *   (the merchant's tolerance or acceptance, an overpayment); on hold instead, never completed,
 *   when the order was cancelled meanwhile (the bitcoin reached the merchant's wallet: the
 *   merchant reinstates the order or refunds the customer) or when its price is no longer the
 *   invoice's (changed after the invoice was made: what came and what the order owes now, for
 *   the merchant to settle);
 * - expired: nothing received, the order failed (WooCommerce's "failed"): the customer can pay
 *   again, with another method; an underpayment (it reached the merchant's wallet) puts the order
 *   on hold instead, noted, for the merchant to settle by hand: an order on hold takes no payment,
 *   and neither WooCommerce nor the customer cancels it (see Guards);
 * - unconfirmed: noted once, the order kept from being cancelled and its stock held;
 * - open: stored (the page shows the rest of an underpayment).
 *
 * The invoice is final only once its order was updated, so that a check that stops half-way is
 * simply done again; a final invoice is never checked again, nor made non-final. Each note says
 * what came (never the invoice's amount for it) against what the invoice asked, and how (in
 * Lightning, on-chain, or both) only when the status says it (`method`, of a paid status alone); it is written once (Notes), before the order's status changes: a check
 * done again after a failure that followed (a plugin, an email) writes it no second time, and
 * finds the order completed or on hold by its transaction id, never "paid twice".
 */
class Settlement {

	/** Seconds a check waits for the order's lock (another request applying a status). */
	public const LOCK_WAIT = 30;

	/** While a payment is being confirmed, its order's stock is held again when its hold ends within this (seconds). */
	public const HOLD_WITHIN = 1800;

	/**
	 * A settlement.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 * @param OrderLock         $lock     The order's lock.
	 * @param StockHold         $stock    The order's stock hold.
	 * @param GatewaySettings   $settings The payment method's settings.
	 * @param Clock             $clock    The clock.
	 * @param Logger            $logger   The log.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly OrderLock $lock,
		private readonly StockHold $stock,
		private readonly GatewaySettings $settings,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {}

	/**
	 * Applies $status, found by a check of $invoice holding it until $lease.
	 *
	 * @param InvoiceRecord $invoice The invoice, as the check read it.
	 * @param int           $lease   The check's lease.
	 * @param PaymentStatus $status  What it found.
	 * @throws \Sattleme\WooCommerce\Orders\LockUnavailable When another request held the order.
	 * @throws \RuntimeException When WooCommerce could not take the update.
	 */
	public function apply( InvoiceRecord $invoice, int $lease, PaymentStatus $status ): void {
		$this->lock->run(
			$invoice->order_id,
			self::LOCK_WAIT,
			function () use ( $invoice, $lease, $status ): void {
				if ( ! $this->invoices->store_status( $invoice->id, $lease, $status->toArray(), $this->clock->now() ) ) {
					return; // Final meanwhile, or this check outlived its lease: another one decides.
				}
				$stored = $this->invoices->find( $invoice->id );
				if ( null === $stored ) {
					return;
				}
				$outcome = $this->settle( $stored, $status );
				// Released only now: no other check settles the order meanwhile.
				$this->invoices->release( $invoice->id, $lease, $outcome );
				if ( null !== $outcome ) {
					$this->invoices->shred_if_final( $invoice->id );
				}
			}
		);
	}

	/**
	 * Updates the order of $invoice to $status: the invoice's outcome when it is final now.
	 *
	 * @param InvoiceRecord $invoice The invoice, its new status stored.
	 * @param PaymentStatus $status  The status found.
	 * @throws \RuntimeException When WooCommerce could not take the update.
	 */
	public function settle( InvoiceRecord $invoice, PaymentStatus $status ): ?string {
		$order = wc_get_order( $invoice->order_id );
		if ( ! $order instanceof \WC_Order ) {
			// The order was deleted: nothing to update, the invoice's outcome stays recorded.
			$this->logger->warning( sprintf( 'Invoice %s: its order %d no longer exists (%s)', $invoice->ref, $invoice->order_id, $status->state ) );

			return $status->isFinal() ? ( $status->isPaid() ? InvoiceRecord::OUTCOME_PAID : InvoiceRecord::OUTCOME_EXPIRED ) : null;
		}
		switch ( $status->state ) {
			case PaymentStatus::PAID:
				$this->paid( $order, $invoice );

				return InvoiceRecord::OUTCOME_PAID;
			case PaymentStatus::EXPIRED:
				$this->expired( $order, $invoice );

				return InvoiceRecord::OUTCOME_EXPIRED;
			case PaymentStatus::UNCONFIRMED:
				$this->unconfirmed( $order, $invoice );

				return null;
			default:
				$this->open( $order, $invoice );

				return null;
		}
	}

	/**
	 * The invoice was paid: the order's payment is complete, once.
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 * @throws \RuntimeException When WooCommerce could not complete it.
	 */
	private function paid( \WC_Order $order, InvoiceRecord $invoice ): void {
		if ( $order->get_transaction_id() === $invoice->ref ) {
			// Completed, or put on hold, by an earlier check (one that failed later), in the save
			// that recorded the transaction: never again, whatever the merchant did since.
			return;
		}
		if ( $order->is_paid() || $order->has_status( 'refunded' ) ) {
			// Paid otherwise meanwhile (another method, another invoice): the bitcoin came all the same.
			Notes::once(
				$order,
				$invoice->ref . ' paid-otherwise',
				self::sentences(
					sprintf(
						/* translators: %s: the invoice's reference. */
						__( 'This order was paid otherwise already, and its Bitcoin invoice %s was paid as well: the bitcoin reached your wallet. Refund the customer one of the payments if needed.', 'sattleme-for-woocommerce' ),
						$invoice->ref
					),
					self::came_note( $invoice ),
					self::difference_note( $invoice )
				)
			);
			$this->logger->warning( sprintf( 'Invoice %s paid, its order %d was paid already', $invoice->ref, $order->get_id() ) );

			return;
		}
		if ( GatewaySettings::GATEWAY_ID !== $order->get_payment_method() ) {
			$order->set_payment_method( GatewaySettings::GATEWAY_ID );
			$order->set_payment_method_title( $this->settings->title() );
		}
		if ( $order->has_status( 'cancelled' ) ) {
			$this->paid_after_cancelled( $order, $invoice );

			return;
		}
		// What the order still owes now, refunds made meanwhile deducted, is the invoice's price.
		if ( ! Prices::is_price_of( $invoice->invoice['fiat'] ?? null, Owed::amount( $order ), $order->get_currency() ) ) {
			$this->paid_another_price( $order, $invoice );

			return;
		}
		// Written first, once: a completion interrupted after it is finished by a later check.
		Notes::once( $order, $invoice->ref . ' paid', self::sentences( self::paid_note( $invoice ), self::difference_note( $invoice ) ) );
		if ( ! $order->payment_complete( $invoice->ref ) ) {
			throw new \RuntimeException( 'WooCommerce could not complete the payment' );
		}
		$this->logger->info( sprintf( 'Invoice %s paid (%s): order %d', $invoice->ref, (string) $invoice->method(), $order->get_id() ) );
	}

	/**
	 * The invoice was paid after its order was cancelled (its Lightning invoice was in the
	 * customer's wallet already, or an on-chain payment of it came in time): the bitcoin reached
	 * the merchant's wallet, but the order is never completed. It is put on hold, WooCommerce's
	 * state for an order the merchant must look at, its transaction the invoice's reference: the
	 * merchant reinstates it, or refunds the customer.
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 * @throws \RuntimeException When WooCommerce could not put it on hold.
	 */
	private function paid_after_cancelled( \WC_Order $order, InvoiceRecord $invoice ): void {
		$price = self::price( $invoice );
		$this->hold(
			$order,
			$invoice,
			sprintf(
				/* translators: 1: the invoice's reference, 2: the price it was made for (an amount and a currency code). */
				__( 'Bitcoin invoice %1$s was paid after the order was cancelled, for a price of %2$s: the bitcoin reached your wallet. The order is on hold: reinstate it, or refund the customer from your wallet.', 'sattleme-for-woocommerce' ),
				$invoice->ref,
				$price
			)
		);
		$this->logger->warning( sprintf( 'Invoice %s paid, but its order %d had been cancelled (%s): on hold', $invoice->ref, $order->get_id(), $price ) );
	}

	/**
	 * The invoice was paid, but what the order owes is no longer the invoice's price: the
	 * merchant changed the order (its items, its total, a refund) after the invoice was made. The payment is not
	 * the order's price, so the order is not complete: it is put on hold for the merchant, its
	 * transaction the invoice's reference, as WooCommerce's own gateways do when an amount does
	 * not match.
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 * @throws \RuntimeException When WooCommerce could not put it on hold.
	 */
	private function paid_another_price( \WC_Order $order, InvoiceRecord $invoice ): void {
		$price = self::price( $invoice );
		$this->hold(
			$order,
			$invoice,
			sprintf(
				/* translators: 1: the invoice's reference, 2: the price it was made for (an amount and a currency code), 3: what the order owes now (the same). */
				__( 'Bitcoin invoice %1$s was paid, for a price of %2$s. The order now owes %3$s (its total, less what was refunded): it changed after the invoice was made, so the order is on hold. Settle the difference with the customer, then update the order.', 'sattleme-for-woocommerce' ),
				$invoice->ref,
				$price,
				wc_format_decimal( Owed::amount( $order ), wc_get_price_decimals() ) . ' ' . $order->get_currency()
			)
		);
		$this->logger->warning( sprintf( 'Invoice %s paid, but its order %d is no longer of its price (%s): on hold', $invoice->ref, $order->get_id(), $price ) );
	}

	/**
	 * Puts the order of the paid $invoice on hold, its note $note with what came and how written
	 * first (once), then the invoice's reference as its transaction id and the status in one save:
	 * a later check finds that transaction id, and changes nothing.
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 * @param string        $note    Why the order is on hold.
	 * @throws \RuntimeException When WooCommerce could not put it on hold: a later check does.
	 */
	private function hold( \WC_Order $order, InvoiceRecord $invoice, string $note ): void {
		Notes::once( $order, $invoice->ref . ' on-hold', self::sentences( $note, self::came_note( $invoice ), self::difference_note( $invoice ) ) );
		$order->set_transaction_id( $invoice->ref );
		if ( ! $order->update_status( 'on-hold' ) ) {
			throw new \RuntimeException( 'WooCommerce could not put the order on hold' );
		}
	}

	/**
	 * The price $invoice was made for, an amount and a currency code ("24.50 CHF"), or "–".
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private static function price( InvoiceRecord $invoice ): string {
		$fiat = $invoice->invoice['fiat'] ?? null;

		return is_array( $fiat ) && is_string( $fiat['amount'] ?? null ) && is_string( $fiat['currency'] ?? null ) ? $fiat['amount'] . ' ' . $fiat['currency'] : '–';
	}

	/**
	 * The order's note of a payment: how it was paid, what came and what the invoice asked (a note
	 * for the merchant only: never where the customer reads).
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private static function paid_note( InvoiceRecord $invoice ): string {
		$came = Amounts::sats( self::received_sats( $invoice ) );
		$sats = Amounts::sats( $invoice->sats() );
		switch ( self::how( $invoice ) ) {
			case 'lightning':
				/* translators: 1: the invoice's reference, 2: its amount in sats (what came). */
				return sprintf( __( 'Bitcoin invoice %1$s paid in Lightning: %2$s sats.', 'sattleme-for-woocommerce' ), $invoice->ref, $came );
			case 'onchain':
				/* translators: 1: the invoice's reference, 2: the sats that came, 3: the invoice's amount in sats. */
				return sprintf( __( 'Bitcoin invoice %1$s paid on-chain (3 confirmations): %2$s sats came in time, for its %3$s sats.', 'sattleme-for-woocommerce' ), $invoice->ref, $came, $sats );
			case 'rest':
				/* translators: 1: the invoice's reference, 2: the sats that came, 3: the invoice's amount in sats. */
				return sprintf( __( 'Bitcoin invoice %1$s paid in Lightning, by invoices of the rest of an underpayment: %2$s sats came in time, for its %3$s sats.', 'sattleme-for-woocommerce' ), $invoice->ref, $came, $sats );
			default:
				/* translators: 1: the invoice's reference, 2: the sats that came, 3: the invoice's amount in sats. */
				return sprintf( __( 'Bitcoin invoice %1$s paid on-chain (3 confirmations) and in Lightning (the rest of an underpayment): %2$s sats came in time in all, for its %3$s sats.', 'sattleme-for-woocommerce' ), $invoice->ref, $came, $sats );
		}
	}

	/**
	 * How the paid $invoice was paid, what came and what it asked, for a note that says why else
	 * (on hold, paid otherwise already).
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private static function came_note( InvoiceRecord $invoice ): string {
		$came = Amounts::sats( self::received_sats( $invoice ) );
		$sats = Amounts::sats( $invoice->sats() );
		switch ( self::how( $invoice ) ) {
			case 'lightning':
				/* translators: %s: the invoice's amount in sats (what came). */
				return sprintf( __( 'It was paid in Lightning: %s sats, its amount.', 'sattleme-for-woocommerce' ), $came );
			case 'onchain':
				/* translators: 1: the sats that came, 2: the invoice's amount in sats. */
				return sprintf( __( 'It was paid on-chain (3 confirmations): %1$s sats came in time, for its %2$s sats.', 'sattleme-for-woocommerce' ), $came, $sats );
			case 'rest':
				/* translators: 1: the sats that came, 2: the invoice's amount in sats. */
				return sprintf( __( 'It was paid in Lightning, by invoices of the rest of an underpayment: %1$s sats came in time, for its %2$s sats.', 'sattleme-for-woocommerce' ), $came, $sats );
			default:
				/* translators: 1: the sats that came, 2: the invoice's amount in sats. */
				return sprintf( __( 'It was paid on-chain (3 confirmations) and in Lightning (the rest of an underpayment): %1$s sats came in time in all, for its %2$s sats.', 'sattleme-for-woocommerce' ), $came, $sats );
		}
	}

	/**
	 * How the paid $invoice was paid: lightning (its own invoice), onchain, mixed (on-chain and
	 * invoices of the rest of the underpayment), or rest (those invoices alone: a mixed payment
	 * without transactions, SPEC.md §3).
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private static function how( InvoiceRecord $invoice ): string {
		$method = $invoice->method();
		if ( 'lightning' === $method || 'onchain' === $method ) {
			return $method;
		}

		return array() === $invoice->txids() ? 'rest' : 'mixed';
	}

	/**
	 * Sentences of a note, the empty ones left out.
	 *
	 * @param string ...$sentences The sentences.
	 */
	private static function sentences( string ...$sentences ): string {
		return implode( ' ', array_filter( $sentences, static fn ( string $sentence ): bool => '' !== $sentence ) );
	}

	/**
	 * What came for the paid $invoice, in sats: its amount when paid in Lightning (a Lightning
	 * payment's status has no received), else what the SDK received (on-chain, and by invoices of
	 * the rest), which the merchant's tolerance, or the merchant's acceptance of what came (in the
	 * sattle.me app), may have let be less, and a customer may have made more.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private static function received_sats( InvoiceRecord $invoice ): string {
		$received = $invoice->received();

		return 'lightning' === $invoice->method() || '0' === $received ? $invoice->sats() : $received;
	}

	/**
	 * What a note of the paid $invoice adds when what came differs from its amount (for the
	 * merchant only: never where the customer reads), or "".
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private static function difference_note( InvoiceRecord $invoice ): string {
		$received = self::received_sats( $invoice );
		$sats     = $invoice->sats();
		if ( Amounts::is_less( $received, $sats ) ) {
			return __( 'Less than its amount: accepted in the sattle.me app as paying it in full, by your tolerance or by your acceptance of what came.', 'sattleme-for-woocommerce' );
		}
		if ( Amounts::is_less( $sats, $received ) ) {
			return sprintf(
				/* translators: %s: the sats received above the invoice's amount. */
				__( '%s sats more than its amount reached your wallet: refund them to the customer if needed.', 'sattleme-for-woocommerce' ),
				// Strings of 18 digits at most: exact in a 64-bit integer.
				Amounts::sats( (string) ( (int) $received - (int) $sats ) )
			);
		}

		return '';
	}

	/**
	 * The invoice ended unpaid: the order failed (when it still waits for this invoice), or, when
	 * something came, is on hold for the merchant, its underpayment noted.
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function expired( \WC_Order $order, InvoiceRecord $invoice ): void {
		$received  = $invoice->received();
		$underpaid = '0' !== $received;
		$note      = $underpaid
			? sprintf(
				/* translators: 1: the invoice's reference, 2: sats received, 3: the invoice's amount in sats. */
				__( 'The Bitcoin invoice %1$s ended underpaid: %2$s of its %3$s sats came, and reached your wallet. Please settle the difference with the customer.', 'sattleme-for-woocommerce' ),
				$invoice->ref,
				Amounts::sats( $received ),
				Amounts::sats( $invoice->sats() )
			)
			: sprintf(
				/* translators: %s: the invoice's reference. */
				__( 'The Bitcoin invoice %s expired unpaid.', 'sattleme-for-woocommerce' ),
				$invoice->ref
			);
		Notes::once( $order, $invoice->ref . ' expired', $note );
		$latest = $this->invoices->latest_for_order( $order->get_id() );
		if ( null !== $latest && $latest->id === $invoice->id && $order->has_status( 'pending' ) && GatewaySettings::GATEWAY_ID === $order->get_payment_method() ) {
			// No other payment is under way. Nothing came: the customer can pay again, or with
			// another method. Something came: the merchant settles it (WooCommerce's state for an
			// order the merchant looks at, which no "Pay" nor "Cancel" button offers to change).
			$order->update_status( $underpaid ? 'on-hold' : 'failed' );
		}
		if ( $underpaid ) {
			$this->logger->warning( sprintf( 'Invoice %s (order %d) ended underpaid: %s of %s sats', $invoice->ref, $order->get_id(), $received, $invoice->sats() ) );
		}
	}

	/**
	 * Enough came in time, being confirmed: noted once (what came, and that its on-chain payment,
	 * which every unconfirmed status has, is being confirmed: it may still fail), the order's
	 * stock held.
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function unconfirmed( \WC_Order $order, InvoiceRecord $invoice ): void {
		Notes::once(
			$order,
			$invoice->ref . ' unconfirmed',
			sprintf(
				/* translators: 1: the invoice's reference, 2: sats received, 3: the invoice's amount in sats, 4: the confirmations so far. */
				__( 'Bitcoin invoice %1$s: %2$s sats came in time for its %3$s sats; its on-chain payment is being confirmed (%4$d of 3 confirmations, about 30 minutes).', 'sattleme-for-woocommerce' ),
				$invoice->ref,
				Amounts::sats( $invoice->received() ),
				Amounts::sats( $invoice->sats() ),
				(int) $invoice->confirmations()
			)
		);
		if ( ! $this->stock->extend( $order, self::HOLD_WITHIN ) ) {
			$this->logger->warning( sprintf( 'Invoice %s (order %d) is being confirmed: its stock could not be held again', $invoice->ref, $order->get_id() ) );
		}
	}

	/**
	 * Not paid yet: an underpayment is noted once, as it first comes (what came, not how: the
	 * status does not say it).
	 *
	 * @param \WC_Order     $order   The order.
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function open( \WC_Order $order, InvoiceRecord $invoice ): void {
		if ( ! $invoice->has_received() ) {
			return;
		}
		Notes::once(
			$order,
			$invoice->ref . ' underpaid',
			sprintf(
				/* translators: 1: sats received, 2: the invoice's amount in sats, 3: the invoice's reference. */
				__( '%1$s of %2$s sats came for invoice %3$s: the customer is asked for the rest until the invoice ends.', 'sattleme-for-woocommerce' ),
				Amounts::sats( $invoice->received() ),
				Amounts::sats( $invoice->sats() ),
				$invoice->ref
			)
		);
	}
}
