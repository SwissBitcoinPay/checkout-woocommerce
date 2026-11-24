<?php
/**
 * One attempt, in the order's box.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What the order's box shows of one attempt (templates/admin-order-box.php, which escapes every
 * value).
 */
final class OrderBoxRow {

	/**
	 * A row.
	 *
	 * @param string                            $ref           The attempt's reference.
	 * @param bool                              $has_invoice   Whether it has an invoice.
	 * @param string                            $state         Where it is, in words.
	 * @param string                            $sats          Its amount, in grouped sats.
	 * @param string                            $btc           The same, in bitcoins.
	 * @param string|null                       $fiat          Its price in the store's currency.
	 * @param string|null                       $rate          The exchange rate it was converted at.
	 * @param string|null                       $rate_source   Where the rate came from.
	 * @param string|null                       $rate_time     When it was read.
	 * @param bool                              $lightning     Whether it is payable in Lightning.
	 * @param bool                              $onchain       Whether it is payable on-chain.
	 * @param string|null                       $payment_hash  Its Lightning invoice's payment hash.
	 * @param string|null                       $address       Its deposit address (null once removed).
	 * @param string|null                       $address_link  The address on mempool.space.
	 * @param string                            $created       When it was made.
	 * @param string|null                       $expires       When it ends.
	 * @param string|null                       $received      What came in time, in grouped sats.
	 * @param int|null                          $confirmations The confirmations of an on-chain payment.
	 * @param list<array{0: string, 1: string}> $txids         Its transactions and their links.
	 * @param string|null                       $txids_link    Where the transactions of what came are, when the status lists none (the address's page).
	 * @param list<array{0: string, 1: string}> $preimages     Its proofs of payment, each with the payment hash it proves.
	 * @param string|null                       $checked       When it was last checked.
	 * @param int                               $checks        How many checks found a status.
	 * @param string                            $error         Its last error, in words ("" for none).
	 * @param string|null                       $next_check    When it is checked next.
	 * @param bool                              $shredded      Whether its customer's personal data was removed.
	 */
	public function __construct(
		public readonly string $ref,
		public readonly bool $has_invoice,
		public readonly string $state,
		public readonly string $sats,
		public readonly string $btc,
		public readonly ?string $fiat,
		public readonly ?string $rate,
		public readonly ?string $rate_source,
		public readonly ?string $rate_time,
		public readonly bool $lightning,
		public readonly bool $onchain,
		public readonly ?string $payment_hash,
		public readonly ?string $address,
		public readonly ?string $address_link,
		public readonly string $created,
		public readonly ?string $expires,
		public readonly ?string $received,
		public readonly ?int $confirmations,
		public readonly array $txids,
		public readonly ?string $txids_link,
		public readonly array $preimages,
		public readonly ?string $checked,
		public readonly int $checks,
		public readonly string $error,
		public readonly ?string $next_check,
		public readonly bool $shredded,
	) {}
}
