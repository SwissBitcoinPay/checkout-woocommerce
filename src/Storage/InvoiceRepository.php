<?php
/**
 * Reads and writes the invoices' table.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Storage;

use Sattleme\WooCommerce\Support\Db;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table: never cached, every read must see the latest write (two requests, a check and a page).

/**
 * The invoices' table (Schema). Every write that a concurrent request could also make is a
 * single conditional UPDATE, atomic in the database: claiming a check (a lease), storing what it
 * found while the lease is still its own, marking an invoice final once.
 */
class InvoiceRepository {

	/**
	 * Stores a new attempt to pay $order_id, before the SDK is asked for its invoice: its id.
	 *
	 * @param int    $order_id The order.
	 * @param string $ref      The attempt's reference.
	 * @param int    $now      The time, in Unix seconds.
	 * @throws \RuntimeException When the database refuses it.
	 */
	public function insert_attempt( int $order_id, string $ref, int $now ): int {
		$wpdb = Db::wpdb();

		$inserted = $wpdb->insert(
			Schema::table(),
			array(
				'order_id'   => $order_id,
				'ref'        => $ref,
				'state'      => InvoiceRecord::STATE_CREATING,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%d' )
		);
		if ( 1 !== $inserted ) {
			throw new \RuntimeException( 'The attempt could not be stored' );
		}

		return $wpdb->insert_id;
	}

	/**
	 * The attempt $id, or null.
	 *
	 * @param int $id The invoice's id.
	 */
	public function find( int $id ): ?InvoiceRecord {
		$wpdb = Db::wpdb();

		$table = Schema::table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );

		return is_array( $row ) ? InvoiceRecord::from_row( $row ) : null;
	}

	/**
	 * The attempt of reference $ref, or null.
	 *
	 * @param string $ref The attempt's reference.
	 */
	public function find_by_ref( string $ref ): ?InvoiceRecord {
		$wpdb = Db::wpdb();

		$table = Schema::table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s', $table, $ref ), ARRAY_A );

		return is_array( $row ) ? InvoiceRecord::from_row( $row ) : null;
	}

	/**
	 * The latest attempt to pay $order_id, or null.
	 *
	 * @param int $order_id The order.
	 */
	public function latest_for_order( int $order_id ): ?InvoiceRecord {
		$attempts = $this->for_order( $order_id, 1 );

		return $attempts[0] ?? null;
	}

	/**
	 * The latest attempt of $order_id that has an invoice, or null.
	 *
	 * @param int $order_id The order.
	 */
	public function latest_invoice_for_order( int $order_id ): ?InvoiceRecord {
		$wpdb = Db::wpdb();

		$table = Schema::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d AND state = %s ORDER BY id DESC LIMIT 1', $table, $order_id, InvoiceRecord::STATE_INVOICE ),
			ARRAY_A
		);

		return is_array( $row ) ? InvoiceRecord::from_row( $row ) : null;
	}

	/**
	 * The attempts to pay $order_id, newest first.
	 *
	 * @param int $order_id The order.
	 * @param int $limit    How many at most.
	 * @return list<InvoiceRecord>
	 */
	public function for_order( int $order_id, int $limit = 100 ): array {
		$wpdb = Db::wpdb();

		$table = Schema::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT %d', $table, $order_id, $limit ),
			ARRAY_A
		);

		return self::records( $rows );
	}

	/**
	 * Whether $order_id has an attempt whose invoice may still be paid or confirmed (see
	 * InvoiceRecord::is_live), its latest one.
	 *
	 * @param int $order_id The order.
	 * @param int $now      The time, in Unix seconds.
	 */
	public function live_for_order( int $order_id, int $now ): ?InvoiceRecord {
		foreach ( $this->for_order( $order_id, 5 ) as $attempt ) {
			if ( $attempt->is_live( $now ) ) {
				return $attempt;
			}
		}

		return null;
	}

	/**
	 * The attempt failed with $code, and an invoice may exist for its reference (a network
	 * failure, a call that ran out of time): the next attempt asks the SDK again with it.
	 *
	 * @param int    $id   The invoice's id.
	 * @param string $code The error's code (Errors::code()).
	 */
	public function mark_retry( int $id, string $code ): void {
		$this->update_attempt( $id, InvoiceRecord::STATE_RETRY, $code );
	}

	/**
	 * The attempt failed with $code, for good: it never gave an invoice that was shown.
	 *
	 * @param int    $id   The invoice's id.
	 * @param string $code The error's code (Errors::code()).
	 */
	public function mark_failed( int $id, string $code ): void {
		$wpdb = Db::wpdb();

		$wpdb->update(
			Schema::table(),
			array(
				'state' => InvoiceRecord::STATE_FAILED,
				'error' => substr( $code, 0, 64 ),
				'final' => 1,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Stores the invoice the SDK made for attempt $id (its JSON), and when it is checked first.
	 *
	 * @param int                  $id            The invoice's id.
	 * @param array<string, mixed> $invoice       The invoice's JSON (Invoice::toArray(), or DeclaredInvoice's).
	 * @param int                  $expires_at    When the invoice ends (Unix seconds).
	 * @param int                  $next_check_at When it is checked next (Unix seconds).
	 * @throws \RuntimeException When the database refuses it.
	 */
	public function store_invoice( int $id, array $invoice, int $expires_at, int $next_check_at ): void {
		$wpdb = Db::wpdb();

		$updated = $wpdb->update(
			Schema::table(),
			array(
				'state'         => InvoiceRecord::STATE_INVOICE,
				'invoice'       => self::json( $invoice ),
				'expires_at'    => $expires_at,
				'error'         => '',
				'next_check_at' => $next_check_at,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			throw new \RuntimeException( 'The invoice could not be stored' );
		}
	}

	/**
	 * Claims the check of invoice $id until $lease_until: false when it is final, when another
	 * check holds it, or when one ended less than $min_interval seconds ago. Atomic: of two
	 * requests that claim it at once, one gets it.
	 *
	 * @param int $id           The invoice's id.
	 * @param int $now          The time, in Unix seconds.
	 * @param int $lease_until  The check's lease, until when it holds the invoice (Unix seconds).
	 * @param int $min_interval Seconds since the last check, at least.
	 */
	public function claim( int $id, int $now, int $lease_until, int $min_interval ): bool {
		$wpdb = Db::wpdb();

		return 1 === $wpdb->query(
			(string) $wpdb->prepare(
				'UPDATE %i SET lease_until = %d WHERE id = %d AND state = %s AND final = 0 AND ( lease_until IS NULL OR lease_until < %d ) AND ( checked_at IS NULL OR checked_at <= %d )',
				Schema::table(),
				$lease_until,
				$id,
				InvoiceRecord::STATE_INVOICE,
				$now,
				$now - $min_interval
			)
		);
	}

	/**
	 * Stores the status a check found, while that check still holds the invoice ($lease_until):
	 * false when it no longer does (it outlived its lease, or the invoice is final).
	 *
	 * @param int                  $id          The invoice's id.
	 * @param int                  $lease_until The check's lease, until when it holds the invoice (Unix seconds).
	 * @param array<string, mixed> $status      The status's JSON (PaymentStatus::toArray()).
	 * @param int                  $now         The time, in Unix seconds.
	 */
	public function store_status( int $id, int $lease_until, array $status, int $now ): bool {
		$wpdb = Db::wpdb();

		return 1 === $wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i SET status = %s, checked_at = %d, checks = checks + 1, error = '' WHERE id = %d AND lease_until = %d AND final = 0",
				Schema::table(),
				self::json( $status ),
				$now,
				$id,
				$lease_until
			)
		);
	}

	/**
	 * A check holding the invoice failed with $code: one more failure in a row, the lease freed.
	 *
	 * @param int    $id          The invoice's id.
	 * @param int    $lease_until The check's lease, until when it holds the invoice (Unix seconds).
	 * @param string $code        The error's code (Errors::code()).
	 * @param int    $now         The time, in Unix seconds.
	 */
	public function record_failure( int $id, int $lease_until, string $code, int $now ): void {
		$wpdb = Db::wpdb();

		$wpdb->query(
			(string) $wpdb->prepare(
				'UPDATE %i SET error = %s, errors = errors + 1, checked_at = %d, lease_until = NULL WHERE id = %d AND lease_until = %d',
				Schema::table(),
				substr( $code, 0, 64 ),
				$now,
				$id,
				$lease_until
			)
		);
	}

	/**
	 * Frees the lease of a check that applied its status: its failures in a row are over, and
	 * the invoice is final when $outcome is given (paid, expired). Never makes it non-final.
	 *
	 * @param int         $id          The invoice's id.
	 * @param int         $lease_until The check's lease, until when it holds the invoice (Unix seconds).
	 * @param string|null $outcome     How it ended (paid, expired, abandoned).
	 */
	public function release( int $id, int $lease_until, ?string $outcome ): void {
		$wpdb = Db::wpdb();

		if ( null === $outcome ) {
			$wpdb->query(
				(string) $wpdb->prepare(
					'UPDATE %i SET lease_until = NULL, errors = 0 WHERE id = %d AND lease_until = %d',
					Schema::table(),
					$id,
					$lease_until
				)
			);

			return;
		}
		$wpdb->query(
			(string) $wpdb->prepare(
				'UPDATE %i SET lease_until = NULL, errors = 0, final = 1, outcome = %s, next_check_at = NULL WHERE id = %d AND lease_until = %d',
				Schema::table(),
				$outcome,
				$id,
				$lease_until
			)
		);
	}

	/**
	 * Gives up on invoice $id (final, abandoned), unless it is final already or a check holds it
	 * now ($now within its lease: that check stores what it finds): whether this call did.
	 *
	 * @param int $id  The invoice's id.
	 * @param int $now The time, in Unix seconds.
	 */
	public function give_up( int $id, int $now ): bool {
		$wpdb = Db::wpdb();

		return 1 === $wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i SET final = 1, outcome = %s, error = 'abandoned', next_check_at = NULL WHERE id = %d AND final = 0 AND ( lease_until IS NULL OR lease_until < %d )",
				Schema::table(),
				InvoiceRecord::OUTCOME_ABANDONED,
				$id,
				$now
			)
		);
	}

	/**
	 * Starts a new chain of background checks of invoice $id: the chains it had stop.
	 *
	 * @param int    $id            The invoice's id.
	 * @param string $chain         The chain of background checks (its random id).
	 * @param int    $next_check_at When it is checked next (Unix seconds).
	 */
	public function set_chain( int $id, string $chain, int $next_check_at ): void {
		$wpdb = Db::wpdb();

		$wpdb->update(
			Schema::table(),
			array(
				'chain'         => $chain,
				'next_check_at' => $next_check_at,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * When chain $chain checks invoice $id next: false when that chain no longer checks it (the
	 * invoice is final, or a newer chain does).
	 *
	 * @param int    $id            The invoice's id.
	 * @param string $chain         The chain of background checks (its random id).
	 * @param int    $next_check_at When it is checked next (Unix seconds).
	 */
	public function schedule_next( int $id, string $chain, int $next_check_at ): bool {
		$wpdb = Db::wpdb();

		$updated = $wpdb->query(
			(string) $wpdb->prepare(
				'UPDATE %i SET next_check_at = %d WHERE id = %d AND chain = %s AND final = 0',
				Schema::table(),
				$next_check_at,
				$id,
				$chain
			)
		);
		if ( 1 === $updated ) {
			return true;
		}
		// MySQL counts a row whose values did not change as not affected: read it again.
		$attempt = $this->find( $id );

		return null !== $attempt && $attempt->chain === $chain && ! $attempt->is_final && $attempt->next_check_at === $next_check_at;
	}

	/**
	 * Invoices not final whose next check is due since before $before: their chain stopped (a
	 * lost action). The longest due first, $limit at most.
	 *
	 * @param int $before The moment, in Unix seconds.
	 * @param int $limit  How many at most.
	 * @return list<InvoiceRecord>
	 */
	public function stale( int $before, int $limit ): array {
		$wpdb = Db::wpdb();

		$table = Schema::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE final = 0 AND state = %s AND next_check_at IS NOT NULL AND next_check_at < %d ORDER BY next_check_at ASC LIMIT %d',
				$table,
				InvoiceRecord::STATE_INVOICE,
				$before,
				$limit
			),
			ARRAY_A
		);

		return self::records( $rows );
	}

	/**
	 * Invoices not final made before $before: given up on. $limit at most.
	 *
	 * @param int $before The moment, in Unix seconds.
	 * @param int $limit  How many at most.
	 * @return list<InvoiceRecord>
	 */
	public function older_than( int $before, int $limit ): array {
		$wpdb = Db::wpdb();

		$table = Schema::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE final = 0 AND state = %s AND created_at < %d ORDER BY created_at ASC LIMIT %d',
				$table,
				InvoiceRecord::STATE_INVOICE,
				$before,
				$limit
			),
			ARRAY_A
		);

		return self::records( $rows );
	}

	/**
	 * Deletes the invoices of $order_id (the order is deleted for good): the invoices it had.
	 *
	 * @param int $order_id The order.
	 * @return list<InvoiceRecord>
	 */
	public function delete_for_order( int $order_id ): array {
		$wpdb    = Db::wpdb();
		$deleted = $this->for_order( $order_id, 1000 );

		$wpdb->query( (string) $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::table(), $order_id ) );

		return $deleted;
	}

	/**
	 * Marks the invoices of $order_id as shredded (the customer's personal data is removed):
	 * those final lose what leads to the customer's coins now, the others once final.
	 *
	 * @param int $order_id The order.
	 */
	public function shred_order( int $order_id ): int {
		$wpdb = Db::wpdb();

		$wpdb->query( (string) $wpdb->prepare( 'UPDATE %i SET shredded = 1 WHERE order_id = %d', Schema::table(), $order_id ) );
		$count = 0;
		foreach ( $this->for_order( $order_id ) as $attempt ) {
			if ( $this->shred_if_final( $attempt->id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * When invoice $id is final and shredded: removes its deposit address and transactions (and
	 * the payment URIs that hold the address). Whether it changed anything.
	 *
	 * @param int $id The invoice's id.
	 */
	public function shred_if_final( int $id ): bool {
		$wpdb = Db::wpdb();

		$attempt = $this->find( $id );
		if ( null === $attempt || ! $attempt->shredded || ! $attempt->is_final ) {
			return false;
		}
		$invoice = null === $attempt->invoice ? null : Shredder::invoice( $attempt->invoice );
		$status  = null === $attempt->status ? null : Shredder::status( $attempt->status );
		if ( $invoice === $attempt->invoice && $status === $attempt->status ) {
			return false;
		}
		$wpdb->update(
			Schema::table(),
			array(
				'invoice' => null === $invoice ? null : self::json( $invoice ),
				'status'  => null === $status ? null : self::json( $status ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Updates a failed attempt.
	 *
	 * @param int    $id    The invoice's id.
	 * @param string $state Where the attempt is (InvoiceRecord::STATE_*).
	 * @param string $code  The error's code (Errors::code()).
	 */
	private function update_attempt( int $id, string $state, string $code ): void {
		$wpdb = Db::wpdb();

		$wpdb->update(
			Schema::table(),
			array(
				'state' => $state,
				'error' => substr( $code, 0, 64 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rows as records.
	 *
	 * @param mixed $rows What $wpdb->get_results() returned.
	 * @return list<InvoiceRecord>
	 */
	private static function records( mixed $rows ): array {
		$records = array();
		if ( ! is_array( $rows ) ) {
			return $records;
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$records[] = InvoiceRecord::from_row( $row );
			}
		}

		return $records;
	}

	/**
	 * JSON text of the SDK's data: exactly its members, slashes and Unicode as they are.
	 *
	 * @param array<string, mixed> $data The data.
	 * @throws \RuntimeException When it cannot be encoded.
	 */
	private static function json( array $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'The data could not be encoded as JSON' );
		}

		return $json;
	}
}
