<?php
/**
 * Makes the SDK's clients.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Sdk;

use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Client;

defined( 'ABSPATH' ) || exit;

/**
 * The SDK's clients, with the plugin's timeouts and its log.
 */
final class SdkClientFactory implements ClientFactory {

	/** Per relay request, connection included, in milliseconds. */
	public const RELAY_TIMEOUT_MS = 5000;

	/** Per HTTP request, body included, in milliseconds. */
	public const HTTP_TIMEOUT_MS = 8000;

	/**
	 * A factory.
	 *
	 * @param Logger $logger Where the SDK's warnings go (they never hold a secret).
	 */
	public function __construct( private readonly Logger $logger ) {}

	/**
	 * A client of $key.
	 *
	 * @param string $key The integration key.
	 */
	public function for_key( #[\SensitiveParameter] string $key ): CheckoutClient {
		return new SdkCheckoutClient( new Client( self::options( $key, $this->logger ) ) );
	}

	/**
	 * The client's options: the key, the plugin's timeouts, its log.
	 *
	 * @param string $key    The integration key.
	 * @param Logger $logger The log.
	 * @return array<string, mixed>
	 */
	public static function options( #[\SensitiveParameter] string $key, Logger $logger ): array {
		return array(
			'key'            => $key,
			'relayTimeoutMs' => self::RELAY_TIMEOUT_MS,
			'httpTimeoutMs'  => self::HTTP_TIMEOUT_MS,
			'logger'         => static function ( string $message ) use ( $logger ): void {
				$logger->warning( 'SDK: ' . $message );
			},
		);
	}
}
