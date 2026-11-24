<?php
/**
 * How long an invoice can be paid.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

use Sattleme\WooCommerce\Storage\InvoiceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * An invoice's duration: the merchant's time to pay, but never past the order's deadline (the
 * moment WooCommerce would free its stock or cancel it), its grace period included.
 *
 * The SDK dates an invoice when the merchant's Lightning address makes it, up to CREATE_TIMEOUT
 * after the duration is computed: it counts from then, and must still end, with the SDK's grace
 * period (120 s: a payment of the invoice's last seconds is reported up to then), by the
 * deadline. Once made, the invoice is checked against the deadline again (the clock of the
 * merchant's Lightning address may be up to 300 s off): one that ends later is never shown.
 */
final class Durations {

	/** The SDK's createInvoice, as a whole, in seconds: it runs while the customer's checkout waits. */
	public const CREATE_TIMEOUT = 20;

	/** The SDK's bounds of an invoice's duration, in seconds. */
	public const MIN_SECONDS = 60;

	public const MAX_SECONDS = 3600;

	/**
	 * The seconds an invoice is asked for: $minutes, bounded by $deadline_left (seconds until the
	 * order's deadline, null without one). Null when too little is left to pay.
	 *
	 * @param int      $minutes       The time to pay, in minutes.
	 * @param int|null $deadline_left Seconds left before the order is cancelled or its stock freed, null when nothing bounds it.
	 */
	public static function seconds( int $minutes, ?int $deadline_left ): ?int {
		$seconds = max( self::MIN_SECONDS, min( self::MAX_SECONDS, $minutes * 60 ) );
		if ( null !== $deadline_left ) {
			$left = $deadline_left - InvoiceRecord::GRACE_SECONDS - self::CREATE_TIMEOUT;
			if ( $left < self::MIN_SECONDS ) {
				return null;
			}
			$seconds = min( $seconds, $left );
		}

		return $seconds;
	}

	/**
	 * What must be left of the order's hold for the full $minutes: the stock is held again when
	 * less is.
	 *
	 * @param int $minutes The time to pay, in minutes.
	 */
	public static function wanted( int $minutes ): int {
		return max( self::MIN_SECONDS, min( self::MAX_SECONDS, $minutes * 60 ) ) + InvoiceRecord::GRACE_SECONDS + self::CREATE_TIMEOUT;
	}

	/**
	 * Whether an invoice ending at $expires_at, with its grace period, ends by $deadline (Unix
	 * seconds, null without one).
	 *
	 * @param int      $expires_at When the invoice ends (Unix seconds).
	 * @param int|null $deadline   When the invoice must end by (Unix seconds), null when nothing bounds it.
	 */
	public static function ends_in_time( int $expires_at, ?int $deadline ): bool {
		return null === $deadline || $expires_at + InvoiceRecord::GRACE_SECONDS <= $deadline;
	}
}
