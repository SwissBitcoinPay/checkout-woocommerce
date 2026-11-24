<?php
/**
 * The background checks of the invoices.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Background;

use Sattleme\WooCommerce\Orders\LockUnavailable;
use Sattleme\WooCommerce\Orders\OrderLock;
use Sattleme\WooCommerce\Payments\Checker;
use Sattleme\WooCommerce\Payments\Schedule;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Input;
use Sattleme\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The SDK has no webhooks: payments are checked by the plugin, on the shop's server, with Action
 * Scheduler (it ships with WooCommerce, and runs from WP-Cron or WP-CLI):
 *
 * - each invoice by a chain of single actions of its own, each one checking it and scheduling
 *   the next (Schedule: every minute while it can be paid, around the end of its grace period,
 *   then every five minutes until its final status is known, less often after failures);
 * - a recurring sweep every five minutes, which restarts a chain that stopped (a lost action) and
 *   gives up on an invoice still not final 90 days after it was made (noted on its order).
 *
 * Each run is bounded: one check of one invoice per action, its SDK call within CHECK_TIMEOUT;
 * the sweep handles SWEEP_BATCH invoices at most; Action Scheduler runs its batches within its
 * own time limit.
 */
class Scheduler {

	public const CHECK_HOOK = 'sattleme_for_woocommerce_check';

	public const SWEEP_HOOK = 'sattleme_for_woocommerce_sweep';

	public const GROUP = 'sattleme-for-woocommerce';

	/** One background check's call to the SDK, in seconds (WP-Cron runs in a web request). */
	public const CHECK_TIMEOUT = 25;

	/** Seconds between two sweeps. */
	public const SWEEP_INTERVAL = 300;

	/** A chain whose next check is this late (seconds) has stopped: the sweep starts a new one. */
	public const STALE_CHAIN = 600;

	/** Invoices handled per sweep: chains restarted, invoices given up on. */
	public const SWEEP_BATCH = 100;

	/** Seconds the give-up waits for the order's lock (held while a check applies a status). */
	public const GIVE_UP_LOCK_WAIT = 5;

	/**
	 * A scheduler.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 * @param Checker           $checker  The checks.
	 * @param OrderLock         $lock     The order's lock.
	 * @param Clock             $clock    The clock.
	 * @param Logger            $logger   The log.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly Checker $checker,
		private readonly OrderLock $lock,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {}

	/**
	 * Starts the background checks of $invoice, replacing those it had: its first in $delay
	 * seconds, else when the schedule says.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 * @param int|null      $delay   Seconds until the first check.
	 */
	public function start_chain( InvoiceRecord $invoice, ?int $delay = null ): void {
		$now   = $this->clock->now();
		$chain = bin2hex( random_bytes( 16 ) );
		$at    = $now + ( $delay ?? Schedule::next_delay( $invoice, $now ) );
		$this->invoices->set_chain( $invoice->id, $chain, $at );
		as_schedule_single_action( $at, self::CHECK_HOOK, array( $invoice->id, $chain ), self::GROUP );
	}

	/**
	 * One step of the background checks of invoice $id (the action CHECK_HOOK): check it, then
	 * schedule the next, unless it is final or another chain checks it now.
	 *
	 * @param mixed $id    The invoice (an action's argument, as stored).
	 * @param mixed $chain The chain this step is of.
	 */
	public function run_chain( $id, $chain ): void {
		$run   = $this->clock->now();
		$id    = Input::id( $id );
		$chain = is_string( $chain ) ? $chain : '';
		$found = $this->invoices->find( $id );
		if ( null === $found || $found->is_final || '' === $chain || ! hash_equals( $found->chain, $chain ) ) {
			return; // Final, or a newer chain checks it.
		}
		$this->checker->check( $id, Schedule::WEB_INTERVAL, self::CHECK_TIMEOUT );
		$current = $this->invoices->find( $id );
		if ( null === $current || $current->is_final ) {
			return;
		}
		$now = $this->clock->now();
		if ( $current->created_at < $now - Schedule::GIVE_UP_AFTER ) {
			$this->give_up( $current );

			return;
		}
		// Counted from the action's start: the queue runner's next start runs it (Schedule).
		$at = $now + Schedule::next_delay( $current, $now, $run );
		if ( $this->invoices->schedule_next( $id, $chain, $at ) ) {
			as_schedule_single_action( $at, self::CHECK_HOOK, array( $id, $chain ), self::GROUP );
		}
	}

	/**
	 * The recurring sweep (the action SWEEP_HOOK): gives up on invoices too old, restarts the
	 * chains that stopped.
	 */
	public function sweep(): void {
		$now = $this->clock->now();
		foreach ( $this->invoices->older_than( $now - Schedule::GIVE_UP_AFTER, self::SWEEP_BATCH ) as $old ) {
			$this->give_up( $old );
		}
		foreach ( $this->invoices->stale( $now - self::STALE_CHAIN, self::SWEEP_BATCH ) as $stopped ) {
			$this->start_chain( $stopped, 0 );
		}
	}

	/**
	 * Schedules the recurring sweep, unless it is (action_scheduler_init).
	 */
	public function ensure_sweep(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::SWEEP_HOOK, null, self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( $this->clock->now() + self::SWEEP_INTERVAL, self::SWEEP_INTERVAL, self::SWEEP_HOOK, array(), self::GROUP );
	}

	/**
	 * Stops every check (the plugin is deactivated): the sweep restarts them once it is active
	 * again.
	 */
	public static function unschedule_all(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::CHECK_HOOK );
		as_unschedule_all_actions( self::SWEEP_HOOK );
	}

	/**
	 * Stops checking $invoice, whose final status is still unknown after 90 days (an on-chain
	 * payment made in time, still unconfirmed on a congested network): noted on its order, whose
	 * payment the merchant settles by hand. Holding the order's lock, and never while a check
	 * holds the invoice: a status a check finds (paid, at last) is always stored and applied.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function give_up( InvoiceRecord $invoice ): void {
		try {
			$this->lock->run(
				$invoice->order_id,
				self::GIVE_UP_LOCK_WAIT,
				function () use ( $invoice ): void {
					$this->give_up_locked( $invoice );
				}
			);
		} catch ( LockUnavailable ) {
			// A check applies a status to its order now: the next sweep decides.
			return;
		}
	}

	/**
	 * Gives up on $invoice, its order's lock held.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function give_up_locked( InvoiceRecord $invoice ): void {
		if ( ! $this->invoices->give_up( $invoice->id, $this->clock->now() ) ) {
			return; // Final meanwhile, or a check holds it: that check decides.
		}
		$this->invoices->shred_if_final( $invoice->id );
		$order = wc_get_order( $invoice->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: the invoice's reference, 2: a number of days. */
					__( 'The Bitcoin invoice %1$s had no final status after %2$d days: its checks stopped. Please check this payment in the sattle.me app, and settle the order by hand.', 'sattleme-for-woocommerce' ),
					$invoice->ref,
					intdiv( Schedule::GIVE_UP_AFTER, DAY_IN_SECONDS )
				),
				0,
				false,
				array( 'note_group' => 'payment' )
			);
		}
		$this->logger->warning( sprintf( 'Invoice %s (order %d): no final status after %d days, checks stopped', $invoice->ref, $invoice->order_id, intdiv( Schedule::GIVE_UP_AFTER, DAY_IN_SECONDS ) ) );
	}
}
