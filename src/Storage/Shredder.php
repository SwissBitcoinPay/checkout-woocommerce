<?php
/**
 * Removes what leads to a customer's coins.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * An on-chain payment is public: the deposit address its customer paid, and the transactions that
 * paid it, lead anyone to the customer's coins and wallet on the blockchain. When the customer's
 * personal data is removed, those go; the invoice's reference, amounts, rate, dates, state, its
 * Lightning invoice, payment hash and proofs of payment stay, for the merchant's accounting (a
 * Lightning invoice names the merchant's node, never the customer's).
 */
final class Shredder {

	/** What replaces a value removed, as WooCommerce's own erasers write it. */
	public const SHREDDED = '█';

	/**
	 * An invoice without its deposit address, which its payment URI (BIP-21) holds too.
	 *
	 * @param array<string, mixed> $invoice The invoice's JSON.
	 * @return array<string, mixed>
	 */
	public static function invoice( array $invoice ): array {
		if ( is_string( $invoice['onchainAddress'] ?? null ) && '' !== $invoice['onchainAddress'] && self::SHREDDED !== $invoice['onchainAddress'] ) {
			$invoice['onchainAddress'] = self::SHREDDED;
			$invoice['paymentUri']     = self::SHREDDED;
		}

		return $invoice;
	}

	/**
	 * A status without the transactions that paid the invoice, nor the rest to pay (whose payment
	 * URI holds the address).
	 *
	 * @param array<string, mixed> $status The status's JSON.
	 * @return array<string, mixed>
	 */
	public static function status( array $status ): array {
		if ( is_array( $status['txids'] ?? null ) && array() !== $status['txids'] ) {
			$status['txids'] = array();
		}
		if ( is_array( $status['rest'] ?? null ) ) {
			$status['rest'] = null;
		}

		return $status;
	}
}
