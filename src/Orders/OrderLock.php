<?php
/**
 * The order's lock.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

use Sattleme\WooCommerce\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * A lock of one order in the database, around the SDK's createInvoice and around applying a
 * payment's status, so that two requests (a double click, two PHP workers, the page and the
 * background) never make two invoices nor complete a payment twice.
 *
 * It is MySQL's and MariaDB's named lock (GET_LOCK), held by the database connection: the same
 * whatever stores the orders (HPOS tables or posts), no transaction held open while the SDK
 * talks to the network, and released by the server should PHP die (its connection closes). With
 * read replicas (HyperDB, LudicrousDB), the lock is taken on the primary. It serializes requests
 * of one database server: a cluster whose nodes all take writes (Galera, Group Replication in
 * multi-primary mode) does not share named locks between them (the README's known limits).
 */
class OrderLock {

	/**
	 * Runs $body holding the lock of $order_id, waiting for it $wait seconds at most.
	 *
	 * @template T
	 * @param int                $order_id The order.
	 * @param int                $wait     Seconds to wait for another request holding it.
	 * @param callable(float): T $body     Given how long it waited, in seconds.
	 * @return T
	 * @throws LockUnavailable When the lock could not be taken (another request held it all along, or the database refused).
	 */
	public function run( int $order_id, int $wait, callable $body ): mixed {
		$wpdb = Db::wpdb();

		// Read replicas (HyperDB, LudicrousDB): a SELECT may go to one of them, whose locks are
		// its own. The lock and every read made while it is held go to the primary.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}
		$name    = self::name( $order_id );
		$started = microtime( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, never cached.
		$taken  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) );
		$waited = microtime( true ) - $started;
		if ( '1' !== $taken ) {
			throw new LockUnavailable( null === $taken ? 'The database refused the lock' : 'Another request held the lock' );
		}
		try {
			return $body( $waited );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, never cached.
			$wpdb->query( (string) $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * The lock's name: of this order, of this site's tables (a database server's named locks are
	 * shared by all its databases), 64 characters at most as MySQL requires.
	 *
	 * @param int $order_id The order.
	 */
	public static function name( int $order_id ): string {
		$wpdb = Db::wpdb();

		$database = defined( 'DB_NAME' ) && is_string( DB_NAME ) ? DB_NAME : '';

		return 'sattleme-wc:' . substr( hash( 'sha256', $database . "\0" . $wpdb->prefix ), 0, 24 ) . ':' . $order_id;
	}
}
