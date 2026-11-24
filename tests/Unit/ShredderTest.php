<?php
/**
 * What leads to the customer's coins, removed.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Storage\Shredder;
use Sattleme\WooCommerce\Tests\Support\Records;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The address and the URIs that hold it go, the transactions and the rest too; the reference,
 * the amounts and the proofs of payment stay.
 */
final class ShredderTest extends TestCase {

	public function test_the_address_and_its_uri_go(): void {
		$invoice  = Records::invoice( '49000', 1_800_000_000 );
		$shredded = Shredder::invoice( $invoice );

		self::assertSame( Shredder::SHREDDED, $shredded['onchainAddress'] );
		self::assertSame( Shredder::SHREDDED, $shredded['paymentUri'] );
		self::assertSame( $invoice['ref'], $shredded['ref'] );
		self::assertSame( $invoice['sats'], $shredded['sats'] );
		self::assertSame( $invoice['bolt11'], $shredded['bolt11'] );
		self::assertSame( $shredded, Shredder::invoice( $shredded ), 'once' );
	}

	public function test_a_lightning_invoice_has_nothing_to_remove(): void {
		$invoice = Records::invoice( '900', 1_800_000_000, false );

		self::assertSame( $invoice, Shredder::invoice( $invoice ) );
	}

	public function test_the_transactions_and_the_rest_go(): void {
		$status = Shredder::status(
			array(
				'state'     => 'paid',
				'method'    => 'mixed',
				'received'  => '49000',
				'txids'     => array( Records::TXID ),
				'preimages' => array( Records::PREIMAGE ),
				'rest'      => array( 'sats' => '1' ),
			)
		);

		self::assertSame( array(), $status['txids'] );
		self::assertNull( $status['rest'] );
		self::assertSame( array( Records::PREIMAGE ), $status['preimages'] );
		self::assertSame( '49000', $status['received'] );
	}
}
