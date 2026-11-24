<?php
/**
 * A payment that cannot be made now.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * No invoice could be shown: its message is what to tell the customer (translated, escaped for
 * HTML, never the SDK's internals). WooCommerce's checkouts (classic, Store API) and its order-pay
 * page show an exception's message thrown from process_payment().
 */
final class PaymentFailure extends \Exception {

	/**
	 * A service did not answer, or another request was making the invoice: try again soon.
	 */
	public static function transient(): self {
		return new self( esc_html__( 'We could not create your Bitcoin invoice right now. Please try again in a moment.', 'sattleme-for-woocommerce' ) );
	}

	/**
	 * The order's amount or currency cannot be paid in bitcoin.
	 */
	public static function order(): self {
		return new self( esc_html__( 'This order cannot be paid with Bitcoin. Please choose another payment method.', 'sattleme-for-woocommerce' ) );
	}

	/**
	 * Too little time is left before the order's deadline.
	 */
	public static function too_late(): self {
		return new self( esc_html__( 'The time to pay this order is almost over: it cannot be paid with Bitcoin.', 'sattleme-for-woocommerce' ) );
	}

	/**
	 * The merchant has to act (the key, the server): never the customer's fault.
	 */
	public static function unavailable(): self {
		return new self( esc_html__( 'Bitcoin payments are not available right now. Please choose another payment method or contact us.', 'sattleme-for-woocommerce' ) );
	}

	/**
	 * The order no longer needs a payment.
	 */
	public static function not_payable(): self {
		return new self( esc_html__( 'This order can no longer be paid.', 'sattleme-for-woocommerce' ) );
	}

	/**
	 * WooCommerce's own words for products no longer in stock.
	 *
	 * @param string $message The message.
	 */
	public static function stock( string $message ): self {
		return new self( '' === $message ? esc_html__( 'Some products of this order are no longer in stock.', 'sattleme-for-woocommerce' ) : $message );
	}
}
