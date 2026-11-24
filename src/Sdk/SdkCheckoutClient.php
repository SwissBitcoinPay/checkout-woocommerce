<?php
/**
 * The SDK's client.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Sdk;

use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Client;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The SDK's Client, as the plugin calls it.
 */
final class SdkCheckoutClient implements CheckoutClient {

	/**
	 * A client.
	 *
	 * @param Client $client The SDK's client (it holds the key outside any property).
	 */
	public function __construct( private readonly Client $client ) {}

	/**
	 * An invoice for an order.
	 *
	 * @param array<string, mixed> $order        The order.
	 * @param Cancellation         $cancellation When to give up.
	 */
	public function create_invoice( array $order, Cancellation $cancellation ): Invoice {
		return $this->client->createInvoice( $order, $cancellation );
	}

	/**
	 * The payment status of an invoice.
	 *
	 * @param array<string, mixed> $invoice      The invoice's JSON.
	 * @param Cancellation         $cancellation When to give up.
	 */
	public function get_status( array $invoice, Cancellation $cancellation ): PaymentStatus {
		return $this->client->getStatus( $invoice, $cancellation );
	}

	/**
	 * The merchant.
	 *
	 * @param Cancellation $cancellation When to give up.
	 */
	public function merchant( Cancellation $cancellation ): Merchant {
		return $this->client->merchant( $cancellation );
	}
}
