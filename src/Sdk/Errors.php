<?php
/**
 * The SDK's errors, as the plugin tells them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Sdk;

use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

defined( 'ABSPATH' ) || exit;

/**
 * A short stable code of each failure (stored, logged), a description for the log (the SDK's
 * messages never hold a secret; nothing else of an unexpected exception is written), and the
 * merchant's words for the codes.
 */
final class Errors {

	/** A call that ran out of time (its Cancellation). */
	public const TIMEOUT = 'timeout';

	/** PHP lacks what the SDK needs. */
	public const ENVIRONMENT = 'environment';

	/** WooCommerce could not take a status a check found (a database error, another plugin). */
	public const APPLY = 'apply';

	/** The invoice made would end after the order's deadline: never shown. */
	public const DEADLINE = 'deadline';

	/** The invoice the SDK gave is not of the order's price: never shown. */
	public const MISMATCH = 'mismatch';

	/** Not an error of the SDK: a fault of the plugin, or of WordPress. */
	public const UNEXPECTED = 'error';

	/**
	 * The code of $error: the SDK's (invalid_key, network…), or one of this class's.
	 */
	public static function code( \Throwable $error ): string {
		if ( $error instanceof SattlemeException ) {
			return $error->getErrorCode();
		}
		if ( $error instanceof CancelledException ) {
			return self::TIMEOUT;
		}
		if ( $error instanceof UnsupportedEnvironmentException ) {
			return self::ENVIRONMENT;
		}

		return self::UNEXPECTED;
	}

	/**
	 * $error for the log: the SDK's code and message (which never hold a secret), else only what
	 * kind of error it was: an unexpected exception's message or trace could hold anything.
	 */
	public static function describe( \Throwable $error ): string {
		if ( $error instanceof SattlemeException ) {
			return $error->getErrorCode() . ': ' . $error->getMessage();
		}
		if ( $error instanceof CancelledException ) {
			return self::TIMEOUT . ': the call took too long';
		}
		if ( $error instanceof UnsupportedEnvironmentException ) {
			// Lists what PHP lacks (extensions, the curve), its version and OpenSSL's: no secret.
			return self::ENVIRONMENT . ': ' . $error->getMessage();
		}

		return self::UNEXPECTED . ': ' . get_class( $error ) . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine();
	}

	/**
	 * Whether the same request may succeed a moment later: a relay, a rate source, the merchant's
	 * Bitcoin node or an explorer that did not answer, a lot of addresses that is empty for now, a
	 * call out of time.
	 */
	public static function is_transient( \Throwable $error ): bool {
		return ( $error instanceof SattlemeException && $error->isRetryable() ) || $error instanceof CancelledException;
	}

	/**
	 * Whether the order itself cannot be paid with bitcoin: its amount or its currency.
	 */
	public static function is_order_error( \Throwable $error ): bool {
		return $error instanceof InvalidOrderException || $error instanceof UnsupportedCurrencyException;
	}

	/**
	 * The merchant's words for a stored code (the order's admin view).
	 */
	public static function explain( string $code ): string {
		switch ( $code ) {
			case '':
				return '';
			case 'network':
				return __( 'Your relays, your Lightning address, a rate source, your Bitcoin node or an explorer did not answer. Checks go on.', 'sattleme-for-woocommerce' );
			case self::TIMEOUT:
				return __( 'The check took too long. Checks go on.', 'sattleme-for-woocommerce' );
			case 'rate':
				return __( 'No usable exchange rate. Try again later.', 'sattleme-for-woocommerce' );
			case 'onchain_unavailable':
				return __( 'No deposit address was free: open the sattle.me app to fill the integration’s addresses again.', 'sattleme-for-woocommerce' );
			case 'invalid_key':
				return __( 'The integration key is not valid: enter the key of the integration again.', 'sattleme-for-woocommerce' );
			case 'revoked':
				return __( 'The integration was removed in the sattle.me app: create a new one and enter its key.', 'sattleme-for-woocommerce' );
			case 'channel_not_found':
				return __( 'The integration was not found on its relays: check the key, or update the plugin.', 'sattleme-for-woocommerce' );
			case 'invalid_invoice':
				return __( 'Your Lightning address answered with an invoice or a payment that failed the checks.', 'sattleme-for-woocommerce' );
			case 'invalid_order':
				return __( 'The SDK refused the order or the stored invoice (amount, currency or reference).', 'sattleme-for-woocommerce' );
			case 'unsupported_currency':
				return __( 'No rate source has a price in the store’s currency.', 'sattleme-for-woocommerce' );
			case self::ENVIRONMENT:
				return __( 'The server’s PHP lacks what the plugin needs (see WooCommerce → Status → Logs).', 'sattleme-for-woocommerce' );
			case self::APPLY:
				return __( 'WooCommerce could not update the order. Checks go on.', 'sattleme-for-woocommerce' );
			case self::DEADLINE:
				return __( 'The invoice would have ended after the order’s stock hold: it was never shown.', 'sattleme-for-woocommerce' );
			case self::MISMATCH:
				return __( 'The invoice made earlier for this attempt was not of the order’s price (the order changed since): it was never shown.', 'sattleme-for-woocommerce' );
			case 'stock':
				return __( 'Some products of the order were no longer in stock.', 'sattleme-for-woocommerce' );
			case 'lock':
				return __( 'Another request was making this order’s invoice.', 'sattleme-for-woocommerce' );
			case 'abandoned':
				return __( 'No final status after 90 days: the checks stopped. Check this payment in the sattle.me app.', 'sattleme-for-woocommerce' );
			default:
				/* translators: %s: an error code of the plugin or of the sattle.me SDK, such as "network". */
				return sprintf( __( 'Error: %s.', 'sattleme-for-woocommerce' ), $code );
		}
	}
}
