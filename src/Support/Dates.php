<?php
/**
 * Dates, as the site shows them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A moment (Unix seconds) in the site's formats (Settings > General), timezone and language.
 */
final class Dates {

	/**
	 * Its date and time of day.
	 *
	 * @param int $timestamp The moment, in Unix seconds.
	 */
	public static function date_time( int $timestamp ): string {
		return self::format( self::option( 'date_format' ) . ' ' . self::option( 'time_format' ), $timestamp );
	}

	/**
	 * Its time of day.
	 *
	 * @param int $timestamp The moment, in Unix seconds.
	 */
	public static function time( int $timestamp ): string {
		return self::format( self::option( 'time_format' ), $timestamp );
	}

	/**
	 * $timestamp in $format, by wp_date().
	 *
	 * @param string $format    The format.
	 * @param int    $timestamp The moment, in Unix seconds.
	 */
	private static function format( string $format, int $timestamp ): string {
		$date = wp_date( $format, $timestamp );

		return is_string( $date ) ? $date : '';
	}

	/**
	 * A format the site set.
	 *
	 * @param string $name The name.
	 */
	private static function option( string $name ): string {
		$value = get_option( $name, '' );

		return is_string( $value ) ? $value : '';
	}
}
