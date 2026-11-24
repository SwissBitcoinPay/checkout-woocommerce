<?php
/**
 * Checks an integration key.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Settings;

use Sattleme\WooCommerce\Sdk\ClientFactory;
use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\TimeoutCancellation;

defined( 'ABSPATH' ) || exit;

/**
 * The check of a key a merchant enters: an integration key (the SDK parses it), of an
 * integration the merchant still has (the SDK reads its channel: a removed one is refused).
 */
class KeyVerifier {

	/** The whole check, in seconds: it runs while the settings page saves. */
	public const TIMEOUT = 15;

	/**
	 * A verifier.
	 *
	 * @param ClientFactory $clients The SDK's clients.
	 * @param Logger        $logger  The log.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly Logger $logger,
	) {}

	/**
	 * The merchant of $key, or KeyRejected with what to tell the merchant.
	 *
	 * @param string $key The key entered.
	 * @throws KeyRejected When the key is refused, or could not be checked.
	 */
	public function verify( #[\SensitiveParameter] string $key ): Merchant {
		try {
			$client = $this->clients->for_key( $key );
		} catch ( InvalidKeyException ) {
			throw new KeyRejected( esc_html__( 'This is not the secret key of a sattle.me integration (sattleme_ck_…).', 'sattleme-for-woocommerce' ) );
		} catch ( UnsupportedEnvironmentException $e ) {
			$this->logger->error( 'The key could not be checked: ' . Errors::describe( $e ) );
			/* translators: %s: what the server's PHP lacks, as the SDK lists it (in English). */
			throw new KeyRejected( sprintf( esc_html__( 'The server’s PHP lacks what sattle.me needs: %s', 'sattleme-for-woocommerce' ), esc_html( $e->getMessage() ) ) );
		} catch ( \Throwable $e ) {
			// Never expected. Neither raised as it is nor described: its frames could hold the key.
			$this->logger->error( 'The key could not be read: ' . get_class( $e ) );
			throw new KeyRejected( esc_html__( 'This is not the secret key of a sattle.me integration (sattleme_ck_…).', 'sattleme-for-woocommerce' ) );
		}
		try {
			return $client->merchant( new TimeoutCancellation( self::TIMEOUT ) );
		} catch ( RevokedException ) {
			throw new KeyRejected( esc_html__( 'This integration was removed in the sattle.me app. Create a new one and enter its key.', 'sattleme-for-woocommerce' ) );
		} catch ( ChannelNotFoundException $e ) {
			if ( $e->isNewerFormat() ) {
				throw new KeyRejected( esc_html__( 'This integration needs a newer version of sattle.me for WooCommerce.', 'sattleme-for-woocommerce' ) );
			}
			throw new KeyRejected( esc_html__( 'This integration could not be found. Please check the key, or try again in a moment.', 'sattleme-for-woocommerce' ) );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'The key could not be checked: ' . Errors::describe( $e ) );
			throw new KeyRejected( esc_html__( 'sattle.me could not be reached to check this key. Please try again in a moment.', 'sattleme-for-woocommerce' ) );
		}
	}
}
