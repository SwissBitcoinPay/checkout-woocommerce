<?php
/**
 * Where the integration key is kept.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The integration key, in an option of its own: never in the gateway's settings array (which
 * WooCommerce's REST API returns to administrators, and its settings page writes into a form
 * field), never autoloaded, never shown again once saved. The option holds an array, which
 * WordPress's "All options" page (wp-admin/options.php) shows as "SERIALIZED DATA": a string
 * would be written there, into an input's value. WordPress keeps options in its database as they
 * are, unencrypted: the README says so.
 */
class KeyStore {

	public const OPTION = 'sattleme_for_woocommerce_integration_key';

	/**
	 * Whether a key is saved.
	 */
	public function has_key(): bool {
		return '' !== $this->key();
	}

	/**
	 * The key saved, or "" (a secret: hand it to the SDK, nowhere else).
	 */
	public function key(): string {
		$stored = get_option( self::OPTION, array() );
		$key    = is_array( $stored ) ? ( $stored['key'] ?? '' ) : '';

		return is_string( $key ) ? $key : '';
	}

	/**
	 * Saves $key, checked by KeyVerifier.
	 *
	 * @param string $key The integration key.
	 */
	public function save( #[\SensitiveParameter] string $key ): void {
		update_option( self::OPTION, array( 'key' => $key ), false );
	}

	/**
	 * Forgets the key.
	 */
	public function delete(): void {
		delete_option( self::OPTION );
	}
}
