<?php
/**
 * What the payment page shows.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Page;

defined( 'ABSPATH' ) || exit;

/**
 * What the payment page shows of an order's invoice and its payment (templates/payment-page.php,
 * which escapes every value).
 */
final class PaymentView {

	/**
	 * A view.
	 *
	 * @param string      $phase         Phase::*.
	 * @param string      $version       Phase::version(): the page reloads when it changes.
	 * @param string      $sats          The invoice's amount, in grouped sats.
	 * @param string      $received_sats What came in time, in grouped sats.
	 * @param string      $to_pay_sats   What is to pay now (the amount, or the rest), in grouped sats.
	 * @param string      $to_pay_btc    The same, in bitcoins.
	 * @param string|null $uri           The payment URI to open and to scan, while payable.
	 * @param string|null $qr            Its QR code, an SVG image.
	 * @param string|null $lightning     The Lightning invoice to copy, while payable.
	 * @param string|null $address       The deposit address to copy, while payable on-chain.
	 * @param bool        $has_onchain   Whether the invoice has a deposit address.
	 * @param int         $seconds_left  Seconds left to pay.
	 * @param string      $time_left     The same, as minutes:seconds.
	 * @param string      $until         When the invoice ends, a time of day ("" without one).
	 * @param int         $confirmations The confirmations of an on-chain payment being confirmed.
	 * @param bool        $has_received  Whether something came.
	 * @param int|null    $poll_interval Seconds between two asks for news, null when nothing may change.
	 * @param string      $status_url    StatusRoute's URL for the order.
	 * @param string      $check_url     The page's URL with a check asked (without JavaScript).
	 * @param string|null $pay_again_url WooCommerce's "pay for order" page, when the order needs payment and its invoice ended unpaid.
	 * @param string      $price         The price the invoice was made for, as WooCommerce formats prices (HTML).
	 */
	public function __construct(
		public readonly string $phase,
		public readonly string $version,
		public readonly string $sats,
		public readonly string $received_sats,
		public readonly string $to_pay_sats,
		public readonly string $to_pay_btc,
		public readonly ?string $uri,
		public readonly ?string $qr,
		public readonly ?string $lightning,
		public readonly ?string $address,
		public readonly bool $has_onchain,
		public readonly int $seconds_left,
		public readonly string $time_left,
		public readonly string $until,
		public readonly int $confirmations,
		public readonly bool $has_received,
		public readonly ?int $poll_interval,
		public readonly string $status_url,
		public readonly string $check_url,
		public readonly ?string $pay_again_url,
		public readonly string $price,
	) {}
}
