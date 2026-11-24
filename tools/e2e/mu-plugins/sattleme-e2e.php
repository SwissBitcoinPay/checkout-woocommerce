<?php
/**
 * Plugin Name: sattle.me E2E fake
 * Description: The end-to-end tests' fake of the sattle.me SDK, through the plugin's testing filter (which only the testing constant opens). Never on a shop.
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/*
 * The site's emails, recorded (to and subject) in the option sattleme_e2e_mails rather than
 * sent; while the option sattleme_e2e_fail_mails is above 0, an email fails instead, as a fatal
 * error would (an Error, which WooCommerce does not catch), and the option counts it down.
 */
add_filter(
	'pre_wp_mail',
	static function ( $sent, $mail ) {
		$failures = get_option( 'sattleme_e2e_fail_mails', '0' );
		$failures = is_numeric( $failures ) ? (int) $failures : 0;
		if ( $failures > 0 ) {
			update_option( 'sattleme_e2e_fail_mails', $failures - 1, false );
			throw new \Error( 'E2E: an email failed' );
		}
		$mails   = get_option( 'sattleme_e2e_mails', array() );
		$mails   = is_array( $mails ) ? $mails : array();
		$to      = is_array( $mail ) ? ( $mail['to'] ?? '' ) : '';
		$to      = is_array( $to ) ? array_filter( $to, 'is_string' ) : array( $to );
		$subject = is_array( $mail ) ? ( $mail['subject'] ?? '' ) : '';
		$mails[] = implode( ',', array_filter( $to, 'is_string' ) ) . ': ' . ( is_string( $subject ) ? $subject : '' );
		update_option( 'sattleme_e2e_mails', $mails, false );

		return true;
	},
	10,
	2
);

add_filter(
	'sattleme_for_woocommerce_testing_services',
	static function () {
		// The plugin's classes and its prefixed SDK are loaded by now (plugins_loaded).
		require_once __DIR__ . '/sattleme-e2e/fakes.php';

		return Sattleme_E2e_Fakes::services();
	}
);
