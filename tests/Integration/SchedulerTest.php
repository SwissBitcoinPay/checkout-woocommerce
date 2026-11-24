<?php
/**
 * The background checks.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Plugin;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * Action Scheduler checks each invoice in a chain of single actions, one check per action, until
 * its status is final; a recurring sweep restarts chains that stopped and gives up after 90 days.
 */
final class SchedulerTest extends TestCase {

	public function test_the_first_check_is_a_minute_after_the_invoice(): void {
		$invoice = $this->invoice_for( $this->order() );

		$action = $this->next_action( $invoice );

		self::assertSame( $this->clock()->now() + 60, self::due( $action ) );
		self::assertSame( Scheduler::GROUP, $action->get_group() );
		self::assertSame( array( $invoice->id, $invoice->chain ), $action->get_args() );
	}

	public function test_an_action_checks_once_then_schedules_the_next(): void {
		$invoice = $this->invoice_for( $this->order() );
		$this->clock()->advance( 60 );

		$this->run_action( $this->action_id( $invoice ) );

		self::assertCount( 1, $this->sdk()->status_calls );
		$current = $this->attempt( $invoice->id );
		self::assertSame( 1, $current->checks );
		// A minute after the action's start, less the slack: the queue runner's next start runs it.
		self::assertSame( $this->clock()->now() + 55, $current->next_check_at );
		self::assertSame( $this->clock()->now() + 55, self::due( $this->next_action( $current ) ) );
	}

	public function test_checks_follow_the_schedule_around_the_end(): void {
		$invoice            = $this->invoice_for( $this->order() );
		$end                = $invoice->end();
		$this->clock()->now = $end - 30;

		$this->services()->scheduler->run_chain( $invoice->id, $invoice->chain );

		self::assertSame( $end - 10, $this->attempt( $invoice->id )->next_check_at );
	}

	public function test_a_final_invoice_is_checked_no_more(): void {
		$invoice               = $this->invoice_for( $this->order() );
		$this->sdk()->statuses = array( Sdk::paid() );

		$this->run_action( $this->action_id( $invoice ) );
		$this->services()->scheduler->run_chain( $invoice->id, $invoice->chain );

		self::assertCount( 1, $this->sdk()->status_calls );
		self::assertTrue( $this->attempt( $invoice->id )->is_final );
		self::assertFalse( as_has_scheduled_action( Scheduler::CHECK_HOOK, array( $invoice->id, $invoice->chain ), Scheduler::GROUP ) );
	}

	public function test_an_older_chain_stops_once_a_new_one_starts(): void {
		$invoice = $this->invoice_for( $this->order() );
		$this->services()->scheduler->start_chain( $invoice, 0 );
		$newer = $this->attempt( $invoice->id );
		self::assertNotSame( $invoice->chain, $newer->chain );

		$this->services()->scheduler->run_chain( $invoice->id, $invoice->chain );
		$this->services()->scheduler->run_chain( $invoice->id, 'not a chain' );
		$this->services()->scheduler->run_chain( 'x', $newer->chain );

		self::assertSame( array(), $this->sdk()->status_calls );
	}

	public function test_failures_back_off_once_the_invoice_ended(): void {
		$invoice               = $this->invoice_for( $this->order() );
		$this->sdk()->statuses = array( new NetworkException() );
		$this->clock()->now    = $invoice->end() + 600;

		$this->services()->scheduler->run_chain( $invoice->id, $invoice->chain );
		$first = $this->attempt( $invoice->id )->next_check_at;
		$this->clock()->advance( 60 );
		$this->services()->scheduler->run_chain( $invoice->id, $invoice->chain );
		$second = $this->attempt( $invoice->id )->next_check_at;

		self::assertSame( $invoice->end() + 600 + 55, $first );
		self::assertSame( $invoice->end() + 660 + 115, $second );
	}

	public function test_after_ninety_days_the_checks_stop_and_the_order_says_so(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::unconfirmed( 0 ) );
		$this->clock()->advance( 90 * DAY_IN_SECONDS + 1 );

		$this->run_action( $this->action_id( $invoice ) );

		$attempt = $this->attempt( $invoice->id );
		self::assertTrue( $attempt->is_final );
		self::assertSame( InvoiceRecord::OUTCOME_ABANDONED, $attempt->outcome );
		$this->assertNoteContains( 'The Bitcoin invoice ' . $invoice->ref . ' had no final status after 90 days: its checks stopped.', $this->notes( $order ) );
		self::assertStringContainsString( 'no final status after 90 days, checks stopped', $this->log()->text() );
		self::assertFalse( as_has_scheduled_action( Scheduler::CHECK_HOOK, array( $invoice->id, $invoice->chain ), Scheduler::GROUP ) );
	}

	public function test_the_sweep_restarts_a_chain_that_stopped(): void {
		$invoice = $this->invoice_for( $this->order() );
		as_unschedule_all_actions( Scheduler::CHECK_HOOK );
		$this->clock()->advance( 60 + Scheduler::STALE_CHAIN + 1 );

		$this->services()->scheduler->sweep();

		$restarted = $this->attempt( $invoice->id );
		self::assertNotSame( $invoice->chain, $restarted->chain );
		self::assertSame( $this->clock()->now(), $restarted->next_check_at );
		self::assertTrue( as_has_scheduled_action( Scheduler::CHECK_HOOK, array( $invoice->id, $restarted->chain ), Scheduler::GROUP ) );
	}

	public function test_the_sweep_leaves_chains_that_run(): void {
		$invoice = $this->invoice_for( $this->order() );
		$this->clock()->advance( 60 + Scheduler::STALE_CHAIN - 1 );

		$this->services()->scheduler->sweep();

		self::assertSame( $invoice->chain, $this->attempt( $invoice->id )->chain );
	}

	public function test_the_sweep_gives_up_on_invoices_too_old(): void {
		$invoice = $this->invoice_for( $this->order() );
		$this->clock()->advance( 90 * DAY_IN_SECONDS + 1 );

		$this->services()->scheduler->sweep();

		self::assertSame( InvoiceRecord::OUTCOME_ABANDONED, $this->attempt( $invoice->id )->outcome );
	}

	public function test_the_give_up_never_drops_the_status_a_check_is_finding(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->clock()->now    = $invoice->created_at + 90 * DAY_IN_SECONDS + 1;
		$scheduler             = $this->services()->scheduler;
		$this->sdk()->statuses = array(
			// The sweep runs (another worker) while this check waits for the SDK's answer.
			static function () use ( $scheduler ): PaymentStatus {
				$scheduler->sweep();

				return Sdk::paid();
			},
		);

		$this->services()->checker->check( $invoice->id, 0, 10 );

		$attempt = $this->attempt( $invoice->id );
		self::assertSame( 'paid', $attempt->status_state() );
		self::assertSame( InvoiceRecord::OUTCOME_PAID, $attempt->outcome );
		self::assertTrue( $this->reload( $order )->is_paid() );
		foreach ( $this->notes( $order ) as $note ) {
			self::assertStringNotContainsString( 'no final status', $note );
		}
	}


	public function test_the_sweep_is_scheduled_once(): void {
		as_unschedule_all_actions( Scheduler::SWEEP_HOOK );

		$this->services()->scheduler->ensure_sweep();
		$this->services()->scheduler->ensure_sweep();

		$sweeps = as_get_scheduled_actions(
			array(
				'hook'   => Scheduler::SWEEP_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		self::assertCount( 1, $sweeps );
		$action = self::store()->fetch_action( (string) self::id_of( $sweeps[0] ) );
		self::assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $action->get_schedule() );
	}

	public function test_deactivating_the_plugin_stops_every_check(): void {
		$this->invoice_for( $this->order() );
		$this->services()->scheduler->ensure_sweep();

		Plugin::deactivate();

		self::assertFalse( as_has_scheduled_action( Scheduler::CHECK_HOOK ) );
		self::assertFalse( as_has_scheduled_action( Scheduler::SWEEP_HOOK ) );
	}

	public function test_the_hooks_run_the_checks(): void {
		self::assertSame( 10, has_action( Scheduler::CHECK_HOOK, array( $this->services()->scheduler, 'run_chain' ) ) );
		self::assertSame( 10, has_action( Scheduler::SWEEP_HOOK, array( $this->services()->scheduler, 'sweep' ) ) );
	}

	/**
	 * The pending action of $invoice's chain.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function next_action( InvoiceRecord $invoice ): \ActionScheduler_Action {
		return self::store()->fetch_action( (string) $this->action_id( $invoice ) );
	}

	/**
	 * The id of the pending action of $invoice's chain.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 */
	private function action_id( InvoiceRecord $invoice ): int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => Scheduler::CHECK_HOOK,
				'args'   => array( $invoice->id, $invoice->chain ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		self::assertNotEmpty( $ids );

		return self::id_of( end( $ids ) );
	}

	/**
	 * Runs action $id as Action Scheduler's queue runner does.
	 *
	 * @param int $id The action.
	 */
	private function run_action( int $id ): void {
		$runner = \ActionScheduler::runner();
		self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
		$runner->process_action( $id, 'PHPUnit' );
	}

	/**
	 * Action Scheduler's store of actions.
	 */
	private static function store(): \ActionScheduler_Store {
		$store = \ActionScheduler::store();
		self::assertInstanceOf( \ActionScheduler_Store::class, $store );

		return $store;
	}

	/**
	 * When $action is due, a Unix time (null: never).
	 *
	 * @param \ActionScheduler_Action $action The action.
	 */
	private static function due( \ActionScheduler_Action $action ): ?int {
		$schedule = $action->get_schedule();
		self::assertInstanceOf( \ActionScheduler_Abstract_Schedule::class, $schedule );

		return $schedule->get_date()?->getTimestamp();
	}

	/**
	 * An action's id, as Action Scheduler's queries give it (an int, or its digits).
	 *
	 * @param mixed $id The id.
	 */
	private static function id_of( mixed $id ): int {
		self::assertIsNumeric( $id );

		return (int) $id;
	}
}
