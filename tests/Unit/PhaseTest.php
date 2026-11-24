<?php
/**
 * What the customer is told of a payment.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Page\Phase;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Tests\Support\Records;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Each state of an invoice and of its order, as the payment page shows it.
 */
final class PhaseTest extends TestCase {

	private const EXPIRES = 1_800_000_000;

	private const BEFORE = self::EXPIRES - 300;

	public function test_no_invoice(): void {
		self::assertSame( Phase::NONE, Phase::of( null, 'pending', false, self::BEFORE ) );
		self::assertSame(
			Phase::NONE,
			Phase::of(
				Records::row(
					array(
						'state'   => InvoiceRecord::STATE_FAILED,
						'invoice' => null,
					)
				),
				'pending',
				false,
				self::BEFORE
			)
		);
		self::assertSame( Phase::PAID, Phase::of( null, 'processing', true, self::BEFORE ), 'paid otherwise' );
	}

	public function test_open_before_its_end(): void {
		self::assertSame( Phase::OPEN, Phase::of( self::with_status( null ), 'pending', false, self::BEFORE ) );
		self::assertSame(
			Phase::OPEN,
			Phase::of(
				self::with_status(
					array(
						'state'     => 'open',
						'expiresAt' => self::EXPIRES,
						'received'  => '0',
						'rest'      => null,
					)
				),
				'pending',
				false,
				self::BEFORE
			)
		);
	}

	public function test_open_with_a_rest(): void {
		$status = array(
			'state'     => 'open',
			'expiresAt' => self::EXPIRES,
			'received'  => '20000',
			'rest'      => array(
				'sats'       => '29000',
				'bolt11'     => Records::BOLT11,
				'paymentUri' => 'lightning:' . Records::BOLT11,
			),
		);

		self::assertSame( Phase::UNDERPAID, Phase::of( self::with_status( $status ), 'pending', false, self::BEFORE ) );
	}

	public function test_ended_while_its_final_status_is_unknown(): void {
		self::assertSame( Phase::ENDED, Phase::of( self::with_status( null ), 'pending', false, self::EXPIRES ) );
		self::assertSame(
			Phase::ENDED,
			Phase::of(
				self::with_status(
					array(
						'state'     => 'open',
						'expiresAt' => self::EXPIRES,
						'received'  => '0',
						'rest'      => null,
					)
				),
				'pending',
				false,
				self::EXPIRES + 60
			)
		);
	}

	public function test_unconfirmed(): void {
		$status = array(
			'state'         => 'unconfirmed',
			'received'      => '49000',
			'confirmations' => 1,
			'txids'         => array( Records::TXID ),
		);

		self::assertSame( Phase::UNCONFIRMED, Phase::of( self::with_status( $status ), 'pending', false, self::EXPIRES + 600 ) );
	}

	public function test_paid(): void {
		$status = array(
			'state'    => 'paid',
			'method'   => 'lightning',
			'preimage' => Records::PREIMAGE,
		);

		self::assertSame( Phase::PAID, Phase::of( self::with_status( $status ), 'processing', true, self::BEFORE ) );
		self::assertSame( Phase::PAID, Phase::of( self::with_status( $status ), 'pending', false, self::BEFORE ), 'paid before the order knows' );
	}

	public function test_paid_for_an_order_on_hold_is_received(): void {
		$status = array(
			'state'    => 'paid',
			'method'   => 'lightning',
			'preimage' => Records::PREIMAGE,
		);
		$final  = Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'final'      => '1',
				'outcome'    => InvoiceRecord::OUTCOME_PAID,
			)
		);

		// Cancelled, or changed, before its invoice was found paid: the merchant decides.
		self::assertSame( Phase::RECEIVED, Phase::of( self::with_status( $status ), 'on-hold', false, self::BEFORE ) );
		self::assertSame( Phase::RECEIVED, Phase::of( $final, 'on-hold', false, self::EXPIRES + 600 ) );
		self::assertSame( Phase::PAID, Phase::of( self::with_status( $status ), 'processing', true, self::BEFORE ), 'reinstated' );
		self::assertNull( Phase::poll_interval( Phase::RECEIVED ), 'nothing to wait for' );
	}

	public function test_expired_and_expired_underpaid(): void {
		self::assertSame(
			Phase::EXPIRED,
			Phase::of(
				self::with_status(
					array(
						'state'    => 'expired',
						'received' => '0',
					)
				),
				'failed',
				false,
				self::EXPIRES + 600
			)
		);
		self::assertSame(
			Phase::EXPIRED,
			Phase::of(
				self::with_status(
					array(
						'state'    => 'expired',
						'received' => '20000',
					)
				),
				'failed',
				false,
				self::EXPIRES + 600
			)
		);
	}

	public function test_an_order_that_no_longer_takes_the_payment(): void {
		self::assertSame( Phase::CANCELED, Phase::of( self::with_status( null ), 'cancelled', false, self::BEFORE ) );
		self::assertSame( Phase::CANCELED, Phase::of( self::with_status( null ), 'refunded', false, self::BEFORE ) );
		self::assertSame( Phase::CANCELED, Phase::of( self::with_status( null ), 'processing', true, self::BEFORE ), 'paid with another method' );
	}

	public function test_an_invoice_handed_out_again_is_shown_once_found_open(): void {
		$declared = Records::invoice( '49000', self::EXPIRES );
		unset( $declared['rate'], $declared['checkoutUrl'], $declared['redirectUrl'] );
		$declared['declaredAt'] = self::BEFORE;

		self::assertSame( Phase::CHECKING, Phase::of( Records::row( array( 'invoice' => $declared ) ), 'pending', false, self::BEFORE ) );
		self::assertSame(
			Phase::OPEN,
			Phase::of(
				Records::row(
					array(
						'invoice' => $declared,
						'status'  => array(
							'state'     => 'open',
							'expiresAt' => self::EXPIRES,
							'received'  => '0',
							'rest'      => null,
						),
					)
				),
				'pending',
				false,
				self::BEFORE
			)
		);
		self::assertSame(
			Phase::UNCONFIRMED,
			Phase::of(
				Records::row(
					array(
						'invoice' => $declared,
						'status'  => array(
							'state'         => 'unconfirmed',
							'received'      => '49000',
							'confirmations' => 0,
							'txids'         => array( Records::TXID ),
						),
					)
				),
				'pending',
				false,
				self::BEFORE
			)
		);
	}

	public function test_a_final_outcome_holds_without_a_status(): void {
		self::assertSame(
			Phase::PAID,
			Phase::of(
				Records::row(
					array(
						'final'   => '1',
						'outcome' => InvoiceRecord::OUTCOME_PAID,
					)
				),
				'processing',
				true,
				self::BEFORE
			)
		);
		self::assertSame(
			Phase::EXPIRED,
			Phase::of(
				Records::row(
					array(
						'final'   => '1',
						'outcome' => InvoiceRecord::OUTCOME_EXPIRED,
					)
				),
				'failed',
				false,
				self::BEFORE
			)
		);
		self::assertSame(
			Phase::ENDED,
			Phase::of(
				Records::row(
					array(
						'final'   => '1',
						'outcome' => InvoiceRecord::OUTCOME_ABANDONED,
					)
				),
				'pending',
				false,
				self::BEFORE
			)
		);
	}

	public function test_the_pages_that_wait_for_news_poll(): void {
		self::assertSame( 3, Phase::poll_interval( Phase::OPEN ) );
		self::assertSame( 3, Phase::poll_interval( Phase::UNDERPAID ) );
		self::assertSame( 3, Phase::poll_interval( Phase::CHECKING ) );
		self::assertSame( 5, Phase::poll_interval( Phase::ENDED ) );
		self::assertSame( 30, Phase::poll_interval( Phase::UNCONFIRMED ) );
		foreach ( array( Phase::PAID, Phase::EXPIRED, Phase::CANCELED, Phase::NONE ) as $phase ) {
			self::assertNull( Phase::poll_interval( $phase ), $phase . ': nothing to wait for' );
		}
	}

	/**
	 * An open invoice ending at EXPIRES, with $status.
	 *
	 * @param array<string, mixed>|null $status The status.
	 */
	private static function with_status( ?array $status ): InvoiceRecord {
		return Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'status'     => $status,
			)
		);
	}
}
