<?php
/**
 * When an invoice is checked next.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

use Sattleme\WooCommerce\Storage\InvoiceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * The rhythm of an invoice's background checks (the SDK has no webhooks):
 *
 * - every minute while it can be paid, once just before the end of its grace period (an on-chain
 *   payment a check lists by then came in time, SPEC.md §31) and once just after (its final
 *   status), whatever failed;
 * - then every five minutes until its final status is known (an on-chain payment's
 *   confirmations); after failed checks, twice as long each time, up to every 30 minutes;
 * - given up 90 days after it was made (Scheduler).
 *
 * The waits of a background check count from the start of its action, less RUN_SLACK: Action
 * Scheduler runs the actions due when its queue runner starts, once a minute (WP-Cron's, or the
 * server's cron), and a check ends some seconds after it started: a wait counted from its end
 * would miss the run it is meant for (every minute would be every two, every five minutes every
 * six). The checks just before and just after the end of the grace period are at their moments.
 */
final class Schedule {

	/** Seconds between two checks of an invoice that can still be paid. */
	public const OPEN_INTERVAL = 60;

	/** Seconds between two checks of one past its end whose final status is unknown. */
	public const SETTLING_INTERVAL = 300;

	/**
	 * The last check of the grace period runs this long before its end, the first one after it
	 * this long after. More than WEB_INTERVAL, the shortest delay: else that last check could
	 * skip itself and come after the end.
	 */
	public const END_MARGIN = 10;

	/** A check asked by the customer's page runs at most this often per invoice (seconds). */
	public const WEB_INTERVAL = 6;

	/** After failed checks, the next waits twice as long each time, up to this (seconds). */
	public const MAX_BACKOFF = 1800;

	/**
	 * A background check's next one is due this long (seconds) before the start of its action
	 * plus the wait: the queue runner's next start may come a little early.
	 */
	public const RUN_SLACK = 5;

	/** An invoice whose final status is unknown this long after it was made is given up on. */
	public const GIVE_UP_AFTER = 90 * DAY_IN_SECONDS;

	/**
	 * Seconds from $now until the next background check of $invoice: its waits counted from $now,
	 * or, for a check of an action that started at $run, from $run less RUN_SLACK; never sooner
	 * than WEB_INTERVAL after $now.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 * @param int           $now     The time, in Unix seconds.
	 * @param int|null      $run     When the action of the check started, if one did.
	 */
	public static function next_delay( InvoiceRecord $invoice, int $now, ?int $run = null ): int {
		$from = null !== $run && $run - self::RUN_SLACK < $now ? $run - self::RUN_SLACK : $now;
		$end  = $invoice->end();
		if ( $now < $end + self::END_MARGIN ) {
			// A check just before the end, and one just after: the next of them that is to come.
			$mark = $end - self::END_MARGIN > $now ? $end - self::END_MARGIN : $end + self::END_MARGIN;
			$next = min( $from + self::OPEN_INTERVAL, $mark );
		} elseif ( $invoice->errors > 0 ) {
			$next = $from + min( self::MAX_BACKOFF, self::OPEN_INTERVAL * ( 2 ** min( $invoice->errors - 1, 10 ) ) );
		} else {
			$next = $from + self::SETTLING_INTERVAL;
		}

		return max( self::WEB_INTERVAL, $next - $now );
	}
}
