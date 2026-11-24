<?php
/**
 * The SDK's client, faked.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use Sattleme\WooCommerce\Sdk\CheckoutClient;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * The plugin's seam to the SDK (CheckoutClient), answering what a test says, recording each call.
 *
 * Each queue answers its calls in turn, its last answer repeated: an SDK object, a Throwable
 * (thrown), or a Closure given the call's argument (its result answers).
 */
final class FakeCheckout implements CheckoutClient {

	/**
	 * What createInvoice answers. Empty: an invoice made of the order (Sdk::invoice()).
	 *
	 * @var list<Invoice|\Throwable|\Closure>
	 */
	public array $creates = array();

	/**
	 * What getStatus answers. Empty: open, nothing received.
	 *
	 * @var list<PaymentStatus|\Throwable|\Closure>
	 */
	public array $statuses = array();

	/**
	 * What merchant() answers. Empty: Sdk::merchant().
	 *
	 * @var list<Merchant|\Throwable|\Closure>
	 */
	public array $merchants = array();

	/**
	 * The orders createInvoice was called with.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $create_calls = array();

	/**
	 * The invoices getStatus was called with.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $status_calls = array();

	/**
	 * How many times merchant() was called.
	 *
	 * @var int
	 */
	public int $merchant_calls = 0;

	/**
	 * A fake.
	 *
	 * @param FakeClock $clock The clock invoices are made at.
	 */
	public function __construct( private readonly FakeClock $clock ) {}

	/**
	 * An invoice for an order.
	 *
	 * @param array<string, mixed> $order        The order.
	 * @param Cancellation         $cancellation When to give up.
	 * @throws \LogicException When the test's answer is not an invoice.
	 */
	public function create_invoice( array $order, Cancellation $cancellation ): Invoice {
		$this->create_calls[] = $order;
		$answer               = self::next( $this->creates, $order ) ?? Sdk::invoice( $order, $this->clock->now() );
		if ( ! $answer instanceof Invoice ) {
			throw new \LogicException( 'Not an invoice' );
		}

		return $answer;
	}

	/**
	 * The status of an invoice.
	 *
	 * @param array<string, mixed> $invoice      The invoice's JSON.
	 * @param Cancellation         $cancellation When to give up.
	 * @throws \LogicException When the test's answer is not a status.
	 */
	public function get_status( array $invoice, Cancellation $cancellation ): PaymentStatus {
		$this->status_calls[] = $invoice;
		$answer               = self::next( $this->statuses, $invoice );
		if ( null === $answer ) {
			$expires = is_int( $invoice['expiresAt'] ?? null ) ? $invoice['expiresAt'] : $this->clock->now() + 900;

			return Sdk::open( $expires );
		}
		if ( ! $answer instanceof PaymentStatus ) {
			throw new \LogicException( 'Not a status' );
		}

		return $answer;
	}

	/**
	 * The merchant.
	 *
	 * @param Cancellation $cancellation When to give up.
	 * @throws \LogicException When the test's answer is not a merchant.
	 */
	public function merchant( Cancellation $cancellation ): Merchant {
		++$this->merchant_calls;
		$answer = self::next( $this->merchants, null ) ?? Sdk::merchant();
		if ( ! $answer instanceof Merchant ) {
			throw new \LogicException( 'Not a merchant' );
		}

		return $answer;
	}

	/**
	 * The next answer of $queue (its last one stays), thrown when it is a Throwable.
	 *
	 * @template T of object
	 * @param array<int, T> $queue    The answers, in turn.
	 * @param mixed         $argument The call's argument, for a Closure.
	 * @param-out list<T> $queue
	 * @throws \Throwable The answer, when it is one.
	 */
	private static function next( array &$queue, mixed $argument ): ?object {
		$queue = array_values( $queue );
		if ( array() === $queue ) {
			return null;
		}
		$answer = count( $queue ) > 1 ? array_shift( $queue ) : $queue[0];
		if ( $answer instanceof \Closure ) {
			$answer = $answer( $argument );
		}
		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}

		return is_object( $answer ) ? $answer : null;
	}
}
