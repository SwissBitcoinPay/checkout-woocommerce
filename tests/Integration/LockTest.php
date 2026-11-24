<?php
/**
 * The order's lock around createInvoice.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Orders\LockUnavailable;
use Sattleme\WooCommerce\Orders\OrderLock;
use Sattleme\WooCommerce\Storage\Schema;
use Sattleme\WooCommerce\Support\Db;

/**
 * Two requests paying one order at once (a double click, two PHP workers) make one invoice: the
 * second waits for the order's lock in the database (GET_LOCK, the same whatever stores the
 * orders), then gets the first one's invoice.
 */
final class LockTest extends TestCase {

	public function test_two_requests_at_once_make_one_invoice(): void {
		$product = $this->product();
		$order   = $this->order( $product );
		// The two processes have connections of their own: they see committed data only.
		self::commit_transaction();
		$calls = (string) tempnam( sys_get_temp_dir(), 'sattleme-calls-' );
		try {
			$first  = $this->start( $order->get_id(), $calls );
			$second = $this->start( $order->get_id(), $calls );
			$one    = $this->finish( $first );
			$other  = $this->finish( $second );

			self::assertSame( $one['id'], $other['id'], 'the same invoice' );
			self::assertSame( $one['ref'], $other['ref'] );
			$lines = file( $calls, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			self::assertIsArray( $lines );
			self::assertCount( 1, $lines, 'createInvoice was called once' );
			[ $ref, $waiting ] = explode( ' ', $lines[0] );
			self::assertSame( $one['ref'], $ref );
			self::assertGreaterThanOrEqual( 1, (int) $waiting, 'the other request waited for the lock meanwhile' );
			self::assertCount( 1, $this->services()->invoices->for_order( $order->get_id() ) );
		} finally {
			unlink( $calls );
			$this->forget( $order, $product );
		}
	}

	public function test_a_held_lock_is_waited_for_then_refused(): void {
		$other = $this->connection();
		$name  = OrderLock::name( 42 );
		self::assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		$ran     = false;
		$started = microtime( true );

		try {
			$this->services()->lock->run(
				42,
				1,
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
			self::fail( 'The lock was taken' );
		} catch ( LockUnavailable $e ) {
			self::assertSame( 'Another request held the lock', $e->getMessage() );
		} finally {
			$other->query( (string) $other->prepare( 'DO RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}

		self::assertFalse( $ran );
		self::assertGreaterThanOrEqual( 0.9, microtime( true ) - $started, 'it waited' );
	}

	public function test_the_lock_is_let_go_whatever_happens(): void {
		try {
			$this->services()->lock->run(
				43,
				1,
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
		} catch ( \RuntimeException ) {
			// The body failed.
		}

		$other = $this->connection();
		self::assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', OrderLock::name( 43 ) ) ) );
		$other->query( (string) $other->prepare( 'DO RELEASE_LOCK(%s)', OrderLock::name( 43 ) ) );
		$other->close();
	}

	public function test_the_lock_waited_says_how_long(): void {
		$waited = $this->services()->lock->run( 44, 1, static fn ( float $waited ): float => $waited );

		self::assertLessThan( 0.5, $waited );
	}

	public function test_a_lock_is_this_site_s_and_this_order_s(): void {
		$wpdb = Db::wpdb();
		$name = OrderLock::name( 1042 );

		self::assertMatchesRegularExpression( '/^sattleme-wc:[0-9a-f]{24}:1042$/D', $name );
		self::assertLessThanOrEqual( 64, strlen( OrderLock::name( PHP_INT_MAX ) ) );
		self::assertNotSame( $name, OrderLock::name( 1043 ) );
		$prefix         = $wpdb->prefix;
		$wpdb->prefix   = 'other_';
		$on_other_sites = OrderLock::name( 1042 );
		$wpdb->prefix   = $prefix;
		self::assertNotSame( $name, $on_other_sites );
	}

	/**
	 * Starts a process paying $order_id.
	 *
	 * @param int    $order_id The order.
	 * @param string $calls    The file of createInvoice's calls.
	 * @return array{0: resource, 1: array<int, resource>}
	 */
	private function start( int $order_id, string $calls ): array {
		$process = proc_open(
			array( PHP_BINARY, __DIR__ . '/Concurrent/pay.php', (string) $order_id, $calls ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );

		return array( $process, $pipes );
	}

	/**
	 * Waits for a process: the invoice it printed.
	 *
	 * @param array{0: resource, 1: array<int, resource>} $started The process.
	 * @return array{id: int, ref: string}
	 */
	private function finish( array $started ): array {
		[ $process, $pipes ] = $started;
		$output              = (string) stream_get_contents( $pipes[1] );
		$errors              = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		self::assertSame( 0, $status, $output . $errors );
		$result = json_decode( trim( $output ), true );
		self::assertIsArray( $result, $output . $errors );
		self::assertIsInt( $result['id'] );
		self::assertIsString( $result['ref'] );

		return array(
			'id'  => $result['id'],
			'ref' => $result['ref'],
		);
	}

	/**
	 * Another connection to the database.
	 */
	private function connection(): \wpdb {
		self::assertTrue( is_string( DB_USER ) && is_string( DB_PASSWORD ) && is_string( DB_NAME ) && is_string( DB_HOST ), 'wp-tests-config.php names the database' );

		return new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	}

	/**
	 * Removes what the test committed: the order, its product, its invoices.
	 *
	 * @param \WC_Order   $order   The order.
	 * @param \WC_Product $product The product.
	 */
	private function forget( \WC_Order $order, \WC_Product $product ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( (string) $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::table(), $order->get_id() ) );
		$wpdb->query( (string) $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', $wpdb->prefix . 'wc_reserved_stock', $order->get_id() ) );
		as_unschedule_all_actions( \Sattleme\WooCommerce\Background\Scheduler::CHECK_HOOK );
		$this->reload( $order )->delete( true );
		$product->delete( true );
		self::commit_transaction();
	}
}
