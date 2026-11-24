<?php
/**
 * Where an invoice's payment is, for the customer.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Page;

use Sattleme\WooCommerce\Storage\InvoiceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * What the customer is told of an invoice and its payment:
 *
 * - paid: the payment is confirmed (or the order is paid);
 * - received: the payment is confirmed, the order on hold: the merchant decides (it was
 *   cancelled, or changed, before the invoice was found paid) and contacts the customer;
 * - expired: never paid, for good (with something received: underpaid);
 * - unconfirmed: enough came in time, being confirmed;
 * - canceled: the order no longer takes this payment (cancelled, refunded, paid otherwise);
 * - ended: its time is over, its final status not known yet;
 * - checking: an invoice the SDK handed out again, not checked yet (shown once found open);
 * - underpaid: less than the amount came on-chain, the rest may still be paid;
 * - open: waiting for the payment;
 * - none: no invoice could be made.
 */
final class Phase {

	public const PAID        = 'paid';
	public const RECEIVED    = 'received';
	public const EXPIRED     = 'expired';
	public const UNCONFIRMED = 'unconfirmed';
	public const CANCELED    = 'canceled';
	public const ENDED       = 'ended';
	public const CHECKING    = 'checking';
	public const UNDERPAID   = 'underpaid';
	public const OPEN        = 'open';
	public const NONE        = 'none';

	/** Phases whose page waits for news, and how often it asks (seconds). */
	private const POLL_INTERVALS = array(
		self::OPEN        => 3,
		self::UNDERPAID   => 3,
		self::ENDED       => 5,
		self::CHECKING    => 3,
		self::UNCONFIRMED => 30,
	);

	/**
	 * Whether the invoice of $phase was paid: the order's page then follows, not the payment page.
	 *
	 * @param string $phase The phase.
	 */
	public static function is_paid( string $phase ): bool {
		return self::PAID === $phase || self::RECEIVED === $phase;
	}

	/**
	 * Whether the page of $phase for $invoice's order, which takes no payment now, sends the
	 * customer to WooCommerce's "order received" page: once paid (or received, the order on
	 * hold), and once it ended with something received (the order on hold, for the merchant to
	 * settle), which that page says.
	 *
	 * @param string             $phase   The phase.
	 * @param InvoiceRecord|null $invoice The invoice of the phase.
	 */
	public static function goes_to_order( string $phase, ?InvoiceRecord $invoice ): bool {
		return self::is_paid( $phase ) || ( self::EXPIRED === $phase && null !== $invoice && $invoice->has_received() );
	}

	/**
	 * How often the page of $phase asks for news, in seconds (null: it waits for none).
	 *
	 * @param string $phase The phase.
	 */
	public static function poll_interval( string $phase ): ?int {
		return self::POLL_INTERVALS[ $phase ] ?? null;
	}

	/**
	 * The phase of $invoice (null: none) for an order whose status is $order_status, at $now.
	 *
	 * @param InvoiceRecord|null $invoice      The order's latest attempt.
	 * @param string             $order_status The order's status, without "wc-".
	 * @param bool               $order_paid   Whether the order is paid (processing, completed).
	 * @param int                $now          Now.
	 */
	public static function of( ?InvoiceRecord $invoice, string $order_status, bool $order_paid, int $now ): string {
		if ( null === $invoice || ! $invoice->has_invoice() ) {
			return $order_paid ? self::PAID : self::NONE;
		}
		$state = $invoice->status_state();
		if ( 'paid' === $state || ( $invoice->is_final && InvoiceRecord::OUTCOME_PAID === $invoice->outcome ) ) {
			// On hold, the order waits for the merchant (Settlement): never "paid" to the customer.
			return 'on-hold' === $order_status ? self::RECEIVED : self::PAID;
		}
		if ( $order_paid || in_array( $order_status, array( 'cancelled', 'refunded' ), true ) ) {
			return self::CANCELED;
		}
		if ( 'expired' === $state || ( $invoice->is_final && InvoiceRecord::OUTCOME_EXPIRED === $invoice->outcome ) ) {
			return self::EXPIRED;
		}
		if ( 'unconfirmed' === $state ) {
			return self::UNCONFIRMED;
		}
		if ( $invoice->is_final || null === $invoice->expires_at || $now >= $invoice->expires_at ) {
			return self::ENDED;
		}
		if ( null === $state && $invoice->is_declared() ) {
			return self::CHECKING;
		}
		if ( $invoice->has_received() ) {
			return self::UNDERPAID;
		}

		return self::OPEN;
	}

	/**
	 * What the payment page shows, in a few characters: the page reloads when it changes.
	 *
	 * @param InvoiceRecord|null $invoice The invoice.
	 * @param string             $phase   What the page shows (Phase).
	 */
	public static function version( ?InvoiceRecord $invoice, string $phase ): string {
		$rest  = null === $invoice ? null : $invoice->rest();
		$shown = array(
			$phase,
			null === $invoice ? 0 : $invoice->id,
			null === $invoice ? '0' : $invoice->received(),
			$rest['bolt11'] ?? null,
			$rest['paymentUri'] ?? null,
			null === $invoice ? null : $invoice->confirmations(),
		);

		return substr( hash( 'sha256', (string) wp_json_encode( $shown ) ), 0, 16 );
	}
}
