<?php
/**
 * Rows of the invoices' table, for the tests.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Support;

use Sattleme\WooCommerce\Storage\InvoiceRecord;

/**
 * Builds InvoiceRecord rows as the repository reads them.
 */
final class Records {

	/** A valid taproot address (bech32m, witness version 1), made for these tests. */
	public const ADDRESS = 'bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297';

	/** A transaction id. */
	public const TXID = '6f7cf9580f1c2dfb3c4d5d043cdbb128c640e3f20161245aa7372e9666168516';

	/** A Lightning payment's preimage. */
	public const PREIMAGE = '0b7ee2c3f2e1d9b0a4c3d2e1f0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1';

	/** A BOLT11 invoice (its text only matters to the tests). */
	public const BOLT11 = 'lnbc490u1pjtestinvoicexyz';

	/**
	 * An invoice of $sats sats ending at $expires_at, with an address unless $onchain is false.
	 *
	 * @param string $sats       Its amount.
	 * @param int    $expires_at Its end.
	 * @param bool   $onchain    Whether it has an address.
	 * @return array<string, mixed>
	 */
	public static function invoice( string $sats, int $expires_at, bool $onchain = true ): array {
		return array(
			'ref'            => 'wc-1-' . str_repeat( 'a', 32 ),
			'bolt11'         => self::BOLT11,
			'paymentHash'    => str_repeat( 'b', 64 ),
			'verifyUrl'      => null,
			'onchainAddress' => $onchain ? self::ADDRESS : null,
			'paymentUri'     => $onchain ? 'bitcoin:' . self::ADDRESS . '?amount=0.00049&lightning=' . self::BOLT11 : 'lightning:' . self::BOLT11,
			'sats'           => $sats,
			'fiat'           => array(
				'amount'   => '24.50',
				'currency' => 'CHF',
				'minor'    => '2450',
				'digits'   => 2,
			),
			'rate'           => array(
				'source'    => 'coingecko',
				'currency'  => 'CHF',
				'price'     => '50000',
				'fetchedAt' => $expires_at - 900,
				'time'      => $expires_at - 900,
			),
			'note'           => 'Test Shop · Order 1',
			'createdAt'      => $expires_at - 900,
			'expiresAt'      => $expires_at,
			'checkoutUrl'    => null,
			'redirectUrl'    => null,
		);
	}

	/**
	 * A row of an attempt.
	 *
	 * @param array<string, mixed> $columns The columns that differ from an open invoice's.
	 */
	public static function row( array $columns = array() ): InvoiceRecord {
		$expires = isset( $columns['expires_at'] ) && is_int( $columns['expires_at'] ) ? $columns['expires_at'] : 2_000_000_000;
		$row     = array_merge(
			array(
				'id'            => '7',
				'order_id'      => '1',
				'ref'           => 'wc-1-' . str_repeat( 'a', 32 ),
				'state'         => InvoiceRecord::STATE_INVOICE,
				'invoice'       => json_encode( self::invoice( '49000', $expires ) ),
				'status'        => null,
				'expires_at'    => (string) $expires,
				'final'         => '0',
				'outcome'       => '',
				'error'         => '',
				'errors'        => '0',
				'created_at'    => (string) ( $expires - 900 ),
				'checked_at'    => null,
				'checks'        => '0',
				'next_check_at' => null,
				'chain'         => '',
				'lease_until'   => null,
				'shredded'      => '0',
			),
			$columns
		);
		foreach ( array( 'invoice', 'status' ) as $json ) {
			if ( is_array( $row[ $json ] ) ) {
				$row[ $json ] = json_encode( $row[ $json ] );
			}
		}

		return InvoiceRecord::from_row( $row );
	}
}
