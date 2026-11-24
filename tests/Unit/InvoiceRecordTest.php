<?php
/**
 * An attempt's row.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Tests\Support\Records;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What a row says of its invoice and its payment, read defensively from the SDK's JSON.
 */
final class InvoiceRecordTest extends TestCase {

	private const EXPIRES = 1_800_000_000;

	public function test_a_row_as_read(): void {
		$record = Records::row( array( 'expires_at' => self::EXPIRES ) );

		self::assertSame( 7, $record->id );
		self::assertSame( 1, $record->order_id );
		self::assertTrue( $record->has_invoice() );
		self::assertFalse( $record->is_final );
		self::assertFalse( $record->is_declared() );
		self::assertSame( '49000', $record->sats() );
		self::assertSame( self::EXPIRES + 120, $record->end() );
		self::assertSame( Records::BOLT11, $record->bolt11() );
		self::assertSame( str_repeat( 'b', 64 ), $record->payment_hash() );
		self::assertSame( Records::ADDRESS, $record->onchain_address() );
		self::assertStringStartsWith( 'bitcoin:', (string) $record->payment_uri() );
		self::assertNull( $record->status_state() );
		self::assertSame( '0', $record->received() );
		self::assertFalse( $record->has_received() );
	}

	public function test_an_attempt_without_an_invoice(): void {
		$record = Records::row(
			array(
				'state'      => InvoiceRecord::STATE_RETRY,
				'invoice'    => null,
				'expires_at' => null,
			)
		);

		self::assertFalse( $record->has_invoice() );
		self::assertSame( '0', $record->sats() );
		self::assertSame( 0, $record->end() );
		self::assertNull( $record->bolt11() );
	}

	public function test_an_underpayment_and_its_rest(): void {
		$record = Records::row(
			array(
				'status' => array(
					'state'     => 'open',
					'expiresAt' => self::EXPIRES,
					'received'  => '0020000',
					'rest'      => array(
						'sats'       => '29000',
						'bolt11'     => 'lnbc290u1rest',
						'paymentUri' => 'lightning:lnbc290u1rest',
					),
				),
			)
		);

		self::assertSame( '20000', $record->received() );
		self::assertTrue( $record->has_received() );
		self::assertSame(
			array(
				'sats'       => '29000',
				'bolt11'     => 'lnbc290u1rest',
				'paymentUri' => 'lightning:lnbc290u1rest',
			),
			$record->rest()
		);
	}

	public function test_a_paid_status(): void {
		$record = Records::row(
			array(
				'status' => array(
					'state'     => 'paid',
					'method'    => 'mixed',
					'received'  => '49000',
					'txids'     => array( Records::TXID, 'not a txid' ),
					'preimages' => array( Records::PREIMAGE ),
				),
			)
		);

		self::assertSame( 'paid', $record->status_state() );
		self::assertSame( 'mixed', $record->method() );
		self::assertSame( array( Records::TXID ), $record->txids() );
		self::assertSame( array( Records::PREIMAGE ), $record->preimages() );
	}

	public function test_a_lightning_payment_s_proof(): void {
		$record = Records::row(
			array(
				'status' => array(
					'state'    => 'paid',
					'method'   => 'lightning',
					'preimage' => Records::PREIMAGE,
				),
			)
		);

		self::assertSame( array( Records::PREIMAGE ), $record->preimages() );
		self::assertSame( array(), $record->txids() );
	}

	public function test_values_that_are_not_the_sdk_s_are_ignored(): void {
		$record = Records::row(
			array(
				'invoice' => '{"sats": 49000, "bolt11": "", "onchainAddress": 7}',
				'status'  => '{"state": "open", "received": "-5", "rest": {"sats": "x"}, "confirmations": "2"}',
			)
		);

		self::assertSame( '0', $record->sats() );
		self::assertNull( $record->bolt11() );
		self::assertNull( $record->onchain_address() );
		self::assertSame( '0', $record->received() );
		self::assertNull( $record->rest() );
		self::assertNull( $record->confirmations() );
		self::assertNull( Records::row( array( 'invoice' => 'not JSON' ) )->invoice );
	}

	public function test_live_until_it_ended_unpaid_whatever_the_time(): void {
		$record = Records::row( array( 'expires_at' => self::EXPIRES ) );

		foreach ( array( self::EXPIRES - 1, self::EXPIRES + 120, self::EXPIRES + 365 * 86400 ) as $now ) {
			// A payment of its last seconds, or an on-chain one dated in time: a check tells.
			self::assertTrue( $record->may_be_paid( $now ), (string) $now );
			self::assertTrue( $record->is_live( $now ), (string) $now );
		}
		self::assertFalse( $record->ended_unpaid() );
	}

	public function test_live_while_a_payment_came_or_is_confirmed(): void {
		$underpaid   = Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'status'     => array(
					'state'    => 'open',
					'received' => '1000',
				),
			)
		);
		$unconfirmed = Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'status'     => array(
					'state'         => 'unconfirmed',
					'received'      => '49000',
					'confirmations' => 1,
				),
			)
		);

		self::assertTrue( $underpaid->is_live( self::EXPIRES + 3600 ) );
		self::assertTrue( $unconfirmed->is_live( self::EXPIRES + 3600 ) );
	}

	public function test_no_longer_live_once_it_ended_unpaid(): void {
		$record = Records::row(
			array(
				'expires_at' => self::EXPIRES,
				'final'      => '1',
				'outcome'    => InvoiceRecord::OUTCOME_EXPIRED,
				'status'     => array(
					'state'    => 'expired',
					'received' => '0',
				),
			)
		);

		self::assertTrue( $record->ended_unpaid() );
		self::assertFalse( $record->may_be_paid( self::EXPIRES - 600 ) );
		self::assertFalse( $record->is_live( self::EXPIRES - 600 ) );
	}

	public function test_a_final_invoice_that_did_not_end_unpaid_stays_live(): void {
		$cases = array(
			'underpaid' => array(
				'outcome' => InvoiceRecord::OUTCOME_EXPIRED,
				'status'  => array(
					'state'    => 'expired',
					'received' => '20000',
				),
			),
			'paid'      => array(
				'outcome' => InvoiceRecord::OUTCOME_PAID,
				'status'  => array(
					'state'    => 'paid',
					'method'   => 'lightning',
					'preimage' => str_repeat( 'c', 64 ),
				),
			),
			'given up'  => array(
				'outcome' => InvoiceRecord::OUTCOME_ABANDONED,
				'status'  => array(
					'state'         => 'unconfirmed',
					'received'      => '49000',
					'confirmations' => 0,
				),
			),
		);
		foreach ( $cases as $case => $columns ) {
			$record = Records::row( array_merge( $columns, array( 'final' => '1' ) ) );

			self::assertFalse( $record->ended_unpaid(), $case );
			self::assertFalse( $record->may_be_paid( self::EXPIRES ), $case );
			self::assertTrue( $record->is_live( self::EXPIRES ), $case . ': settled by the merchant, never paid otherwise' );
		}
	}

	public function test_an_attempt_being_made_is_live_for_a_minute(): void {
		$record = Records::row(
			array(
				'state'      => InvoiceRecord::STATE_CREATING,
				'invoice'    => null,
				'expires_at' => null,
				'created_at' => '1000',
			)
		);

		self::assertTrue( $record->is_live( 1059 ) );
		self::assertFalse( $record->is_live( 1060 ) );
	}
}
