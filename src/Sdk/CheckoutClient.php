<?php
/**
 * The plugin's seam to the SDK.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Sdk;

use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin asks the sattle.me SDK (sattleme/checkout, embedded and prefixed): the three
 * calls it makes, for one integration key. In production, SdkCheckoutClient: the SDK's Client.
 * The tests fake this interface, and only this one: every value and exception they give is the
 * SDK's own.
 */
interface CheckoutClient {

	/**
	 * An invoice for an order (the SDK's createInvoice).
	 *
	 * @param array<string, mixed> $order        Its reference, price, duration and note.
	 * @param Cancellation         $cancellation When to give up.
	 * @throws SattlemeException   The SDK's errors, InvoicePendingException and OrderPaidException included.
	 * @throws CancelledException  Once $cancellation reads cancelled.
	 */
	public function create_invoice( array $order, Cancellation $cancellation ): Invoice;

	/**
	 * The payment status of an invoice, from its stored JSON (the SDK's getStatus).
	 *
	 * @param array<string, mixed> $invoice      The invoice's JSON, decoded.
	 * @param Cancellation         $cancellation When to give up.
	 * @throws SattlemeException   The SDK's errors.
	 * @throws CancelledException  Once $cancellation reads cancelled.
	 */
	public function get_status( array $invoice, Cancellation $cancellation ): PaymentStatus;

	/**
	 * The merchant as the integration sees it (the SDK's merchant()): what checks a key.
	 *
	 * @param Cancellation $cancellation When to give up.
	 * @throws SattlemeException  The SDK's errors (revoked: the merchant removed the integration).
	 * @throws CancelledException Once $cancellation reads cancelled.
	 */
	public function merchant( Cancellation $cancellation ): Merchant;
}
