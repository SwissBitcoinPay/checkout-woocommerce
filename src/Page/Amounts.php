<?php
/**
 * Amounts, as the customer reads them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts in sats (strings of digits, never floats) as bitcoins and as grouped numbers.
 */
final class Amounts {

	/**
	 * $sats as bitcoins, exactly, without trailing zeros: "49000" is "0.00049".
	 *
	 * @param string $sats An amount in sats (a string of digits).
	 */
	public static function btc( string $sats ): string {
		if ( 1 !== preg_match( '/^[0-9]{1,18}$/D', $sats ) ) {
			return '0';
		}
		$padded   = str_pad( ltrim( $sats, '0' ), 9, '0', STR_PAD_LEFT );
		$whole    = ltrim( substr( $padded, 0, -8 ), '0' );
		$fraction = rtrim( substr( $padded, -8 ), '0' );

		return ( '' === $whole ? '0' : $whole ) . ( '' === $fraction ? '' : '.' . $fraction );
	}

	/**
	 * Whether $a is less than $b: two amounts in sats, strings of digits, compared exactly.
	 *
	 * @param string $a One.
	 * @param string $b The other.
	 */
	public static function is_less( string $a, string $b ): bool {
		$a = ltrim( $a, '0' );
		$b = ltrim( $b, '0' );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) < strlen( $b );
		}

		return strcmp( $a, $b ) < 0;
	}

	/**
	 * $sats with the site's digit grouping: "49000" is "49,000" in English.
	 *
	 * @param string $sats An amount in sats (a string of digits).
	 */
	public static function sats( string $sats ): string {
		if ( 1 !== preg_match( '/^[0-9]{1,18}$/D', $sats ) ) {
			return '0';
		}
		$digits = ltrim( $sats, '0' );
		if ( '' === $digits ) {
			return '0';
		}
		global $wp_locale;
		$separator = $wp_locale instanceof \WP_Locale ? ( $wp_locale->number_format['thousands_sep'] ?? null ) : null;
		// WordPress gives a space as "&nbsp;" (an entity, for HTML): the text holds the character.
		$separator = is_string( $separator ) ? html_entity_decode( $separator, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : ',';
		// Grouped from the right, three digits at a time: on the string, never through a float.
		$groups = str_split( strrev( $digits ), 3 );

		return strrev( implode( strrev( $separator ), $groups ) );
	}
}
