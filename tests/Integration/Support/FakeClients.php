<?php
/**
 * The SDK's clients, faked.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use Sattleme\WooCommerce\Sdk\CheckoutClient;
use Sattleme\WooCommerce\Sdk\ClientFactory;

/**
 * Hands out one FakeCheckout whatever the key, or refuses keys as a test says (as the SDK's
 * Client refuses a key that is not one).
 */
final class FakeClients implements ClientFactory {

	/**
	 * Thrown when a client is asked for, if set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $refusal = null;

	/**
	 * How many clients were asked for.
	 *
	 * @var int
	 */
	public int $made = 0;

	/**
	 * The fake.
	 *
	 * @param FakeCheckout $client The client handed out.
	 */
	public function __construct( public FakeCheckout $client ) {}

	/**
	 * A client of $key.
	 *
	 * @param string $key The integration key.
	 * @throws \Throwable The refusal, when set.
	 */
	public function for_key( #[\SensitiveParameter] string $key ): CheckoutClient {
		++$this->made;
		if ( null !== $this->refusal ) {
			throw $this->refusal;
		}

		return $this->client;
	}
}
