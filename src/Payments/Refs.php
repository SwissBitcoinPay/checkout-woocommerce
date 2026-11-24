<?php
/**
 * An attempt's reference and note.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * The SDK's order reference of each attempt to pay, and the note its merchant's app shows.
 */
final class Refs {

	/** The SDK's longest note, in code points. */
	public const NOTE_MAX = 100;

	/**
	 * A new reference for an attempt to pay order $order_id: "wc-<order id>-<32 hex digits>",
	 * 128 bits from the CSPRNG, made on the server.
	 *
	 * Random, so that no two attempts ever share one, whatever holds the key: a deleted test
	 * order whose number comes back, a restored backup, a copy of the shop. The SDK would
	 * otherwise hand out an earlier invoice of the reference, or report it paid. The order's id
	 * only helps the merchant find the order.
	 *
	 * @param int $order_id The order.
	 */
	public static function new_ref( int $order_id ): string {
		return 'wc-' . $order_id . '-' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * What the merchant's app shows of the sale (and, when the Lightning address takes
	 * comments, the customer's wallet): the shop's name and the order's number, the SDK's rules
	 * met (no control character, 100 code points at most).
	 *
	 * @param string $shop         The shop's name.
	 * @param string $order_number The order's number.
	 */
	public static function note( string $shop, string $order_number ): string {
		$order_number = self::clean( $order_number );
		/* translators: %s: the order's number. */
		$order = sprintf( __( 'Order %s', 'sattleme-for-woocommerce' ), $order_number );
		$order = self::clean( $order );
		$shop  = self::clean( wp_specialchars_decode( $shop, ENT_QUOTES ) );
		if ( '' === $shop ) {
			return self::cut( $order, self::NOTE_MAX );
		}
		$suffix = ' · ' . $order;
		$room   = self::NOTE_MAX - mb_strlen( $suffix, 'UTF-8' );
		if ( $room < 8 ) {
			return self::cut( $order, self::NOTE_MAX );
		}
		if ( mb_strlen( $shop, 'UTF-8' ) > $room ) {
			$shop = rtrim( self::cut( $shop, $room - 1 ) ) . '…';
		}

		return $shop . $suffix;
	}

	/**
	 * $text as valid UTF-8 without control characters, its whitespace runs as one space.
	 *
	 * @param string $text The text.
	 */
	private static function clean( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The first $length code points of $text.
	 *
	 * @param string $text   The text.
	 * @param int    $length The length.
	 */
	private static function cut( string $text, int $length ): string {
		return mb_substr( $text, 0, $length, 'UTF-8' );
	}
}
