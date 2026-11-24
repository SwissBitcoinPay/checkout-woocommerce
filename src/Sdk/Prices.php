<?php
/**
 * Prices as the SDK takes them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Sdk;

use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Client;

defined( 'ABSPATH' ) || exit;

/**
 * The currencies the SDK converts, and an order's total as its price.
 *
 * The table is the SDK's own (checkout-spec's currencies.json, with each currency's decimals),
 * never the platform's locale data, read through the SDK's public Client::currencyDigits()
 * (SPEC.md §4, Functions): the decimals of the currency whose code a text is exactly, null for
 * any other text. No internal class of the SDK is read.
 */
final class Prices {

	/** The largest price the SDK takes, in minor units: 2^53 - 1 (SPEC.md §25). */
	public const MAX_MINOR = '9007199254740991';

	/**
	 * Whether the SDK converts prices in $currency (ISO 4217, upper case).
	 */
	public static function is_supported_currency( string $currency ): bool {
		return null !== self::digits( $currency );
	}

	/**
	 * The decimals of $currency in the SDK's table, or null when it is not in the table.
	 */
	public static function digits( string $currency ): ?int {
		return Client::currencyDigits( $currency );
	}

	/**
	 * An order's total ($total, WooCommerce's decimal string) as the SDK's price in $currency:
	 * exactly it, without trailing zeros. Null when it is not a positive decimal with at most the
	 * currency's decimals, or is more than MAX_MINOR minor units, as the SDK refuses it
	 * (SPEC.md §25): a total that cannot be paid exactly is never rounded.
	 */
	public static function amount( string $total, string $currency ): ?string {
		$digits = self::digits( $currency );
		if ( null === $digits || 1 !== preg_match( '/^([0-9]{1,15})(?:\.([0-9]{1,15}))?$/D', $total, $match ) ) {
			return null;
		}
		$whole    = ltrim( $match[1], '0' );
		$fraction = rtrim( $match[2] ?? '', '0' );
		if ( strlen( $fraction ) > $digits || ( '' === $whole && '' === $fraction ) ) {
			return null;
		}
		// Compared as digits: 15 of the whole and the decimals may be more than an int holds.
		$minor = ltrim( $whole . str_pad( $fraction, $digits, '0' ), '0' );
		if ( strlen( $minor ) > strlen( self::MAX_MINOR ) || ( strlen( $minor ) === strlen( self::MAX_MINOR ) && strcmp( $minor, self::MAX_MINOR ) > 0 ) ) {
			return null;
		}
		$whole = '' === $whole ? '0' : $whole;

		return '' === $fraction ? $whole : $whole . '.' . $fraction;
	}

	/**
	 * Whether $fiat, the price of an invoice (the "fiat" of its JSON: amount and currency), is
	 * exactly $total (WooCommerce's decimal string) in $currency. An invoice pays an order only
	 * at the order's price: one made before the order changed, or handed out again for an
	 * earlier price, does not.
	 *
	 * @param mixed  $fiat     The invoice's price.
	 * @param string $total    The order's total.
	 * @param string $currency The order's currency.
	 */
	public static function is_price_of( mixed $fiat, string $total, string $currency ): bool {
		if ( ! is_array( $fiat ) || ! is_string( $fiat['amount'] ?? null ) || ( $fiat['currency'] ?? null ) !== $currency ) {
			return false;
		}
		$price = self::amount( $total, $currency );

		return null !== $price && self::amount( $fiat['amount'], $currency ) === $price;
	}
}
