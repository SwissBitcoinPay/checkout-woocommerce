<?php
/**
 * The plugin's log.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce's log (WooCommerce → Status → Logs), source "sattleme-for-woocommerce".
 *
 * Never given a secret: the integration key never reaches this class, and the SDK's messages
 * hold none. Nor what leads to a customer's coins (a deposit address, a transaction): the log
 * names orders and references only.
 */
class Logger {

	public const SOURCE = 'sattleme-for-woocommerce';

	/**
	 * Something the merchant should look at, which may fix itself (a service that did not answer).
	 *
	 * @param string $message The message.
	 */
	public function warning( string $message ): void {
		$this->log( 'warning', $message );
	}

	/**
	 * Something the merchant must act on (the key, the configuration) or a fault of the plugin.
	 *
	 * @param string $message The message.
	 */
	public function error( string $message ): void {
		$this->log( 'error', $message );
	}

	/**
	 * What happened, for the merchant's records (an invoice made, a payment confirmed).
	 *
	 * @param string $message The message.
	 */
	public function info( string $message ): void {
		$this->log( 'info', $message );
	}

	/**
	 * Writes one line at $level.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 */
	protected function log( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
