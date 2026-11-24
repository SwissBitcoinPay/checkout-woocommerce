<?php
/**
 * One check of an invoice's payment.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

use Sattleme\WooCommerce\Orders\LockUnavailable;
use Sattleme\WooCommerce\Sdk\ClientFactory;
use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Settings\KeyStore;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\TimeoutCancellation;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the payment of an invoice now (the SDK's getStatus, the server's own check), and has
 * what it found applied to its order (Settlement).
 *
 * Two checks of one invoice never run at once: a check claims it first (a lease in its row,
 * which only the check holding it writes through), and checks in a row are harmless: the SDK's
 * checks are made to be repeated, and applying a status is idempotent. Only a "paid" status of
 * the SDK, for the invoice the attempt holds, completes an order.
 */
class Checker {

	/** A check holds its invoice this much longer than its call to the SDK may take (seconds). */
	public const LEASE_MARGIN = 30;

	/**
	 * A checker.
	 *
	 * @param InvoiceRepository $invoices   The invoices.
	 * @param ClientFactory     $clients    The SDK's clients.
	 * @param KeyStore          $keys       The integration key.
	 * @param Settlement        $settlement What applies a status.
	 * @param Clock             $clock      The clock.
	 * @param Logger            $logger     The log.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly ClientFactory $clients,
		private readonly KeyStore $keys,
		private readonly Settlement $settlement,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {}

	/**
	 * Checks invoice $id, then applies what was found. Skipped (false) when it is final, when
	 * another check of it is under way, or when one ended less than $min_interval seconds ago.
	 *
	 * @param int $id           The invoice.
	 * @param int $min_interval Seconds since the last check, at least.
	 * @param int $timeout      Of the SDK's call, in seconds.
	 */
	public function check( int $id, int $min_interval, int $timeout ): bool {
		$now   = $this->clock->now();
		$lease = $now + $timeout + self::LEASE_MARGIN;
		if ( ! $this->invoices->claim( $id, $now, $lease, $min_interval ) ) {
			return false;
		}
		$invoice = $this->invoices->find( $id );
		if ( null === $invoice || null === $invoice->invoice ) {
			return false;
		}
		try {
			$status = $this->clients->for_key( $this->keys->key() )->get_status( $invoice->invoice, new TimeoutCancellation( $timeout ) );
		} catch ( \Throwable $e ) {
			$this->failed( $invoice->id, $invoice->ref, $invoice->order_id, $invoice->error, $lease, $e, Errors::code( $e ) );

			return true;
		}
		try {
			$this->settlement->apply( $invoice, $lease, $status );
		} catch ( LockUnavailable $e ) {
			$this->failed( $invoice->id, $invoice->ref, $invoice->order_id, $invoice->error, $lease, $e, 'lock' );
		} catch ( \Throwable $e ) {
			// WooCommerce could not take the update (a database error, a plugin reacting to the
			// payment): the invoice is not final, a later check applies its status again.
			$this->failed( $invoice->id, $invoice->ref, $invoice->order_id, $invoice->error, $lease, $e, Errors::APPLY );
		}

		return true;
	}

	/**
	 * Applies $status, known without a check (the SDK's OrderPaid carries the paid status of an
	 * invoice), to invoice $id as a check would: claimed first, so that no check runs meanwhile.
	 * Whether it was applied.
	 *
	 * @param int           $id     The invoice's id.
	 * @param PaymentStatus $status The status.
	 */
	public function apply_known( int $id, PaymentStatus $status ): bool {
		$now   = $this->clock->now();
		$lease = $now + self::LEASE_MARGIN;
		if ( ! $this->invoices->claim( $id, $now, $lease, 0 ) ) {
			return false;
		}
		$invoice = $this->invoices->find( $id );
		if ( null === $invoice ) {
			return false;
		}
		try {
			$this->settlement->apply( $invoice, $lease, $status );
		} catch ( \Throwable $e ) {
			$this->failed( $invoice->id, $invoice->ref, $invoice->order_id, $invoice->error, $lease, $e, $e instanceof LockUnavailable ? 'lock' : Errors::APPLY );

			return false;
		}

		return true;
	}

	/**
	 * Records a failed check: logged when it starts failing, or fails otherwise, not at each of
	 * its retries.
	 *
	 * @param int        $id       The invoice.
	 * @param string     $ref      Its reference.
	 * @param int        $order_id Its order.
	 * @param string     $previous The code of its previous failure, "" after a success.
	 * @param int        $lease    The check's lease.
	 * @param \Throwable $error    What failed.
	 * @param string     $code     Its code.
	 */
	private function failed( int $id, string $ref, int $order_id, string $previous, int $lease, \Throwable $error, string $code ): void {
		$this->invoices->record_failure( $id, $lease, $code, $this->clock->now() );
		if ( $code === $previous ) {
			return;
		}
		$message = sprintf( 'The check of invoice %s (order %d) failed: %s', $ref, $order_id, Errors::describe( $error ) );
		if ( Errors::APPLY === $code || Errors::UNEXPECTED === $code ) {
			$this->logger->error( $message );
		} else {
			$this->logger->warning( $message );
		}
	}
}
