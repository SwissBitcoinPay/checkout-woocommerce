<?php
/**
 * The SDK's real client, as the plugin makes it.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Sdk\SdkCheckoutClient;
use Sattleme\WooCommerce\Sdk\SdkClientFactory;
use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * No network: a client is made from a key without a request, and a key that is not one is
 * refused at once, its message never holding it.
 */
final class SdkClientTest extends TestCase {

	/** A fake integration key for these tests: well-formed, its relay relay.example (a reserved name: no integration answers there). */
	public const KEY = 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg.cmVsYXkuZXhhbXBsZQ';

	public function test_a_client_is_made_from_a_key(): void {
		$client = ( new SdkClientFactory( new Logger() ) )->for_key( self::KEY );

		self::assertInstanceOf( SdkCheckoutClient::class, $client );
	}

	/**
	 * @dataProvider not_keys
	 *
	 * @param string $text What was entered.
	 */
	public function test_a_key_that_is_not_one_is_refused( string $text ): void {
		try {
			( new SdkClientFactory( new Logger() ) )->for_key( $text );
			self::fail( 'The key was taken' );
		} catch ( InvalidKeyException $e ) {
			self::assertSame( 'invalid_key', $e->getErrorCode() );
			self::assertFalse( $e->isRetryable() );
			if ( '' !== $text ) {
				self::assertStringNotContainsString( $text, $e->getMessage() );
			}
		}
	}

	/**
	 * Texts that are not integration keys.
	 *
	 * @return array<string, array{string}>
	 */
	public static function not_keys(): array {
		return array(
			'empty'            => array( '' ),
			'another prefix'   => array( 'sk_live_' . substr( self::KEY, 12 ) ),
			'two parts'        => array( 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg' ),
			'a short key'      => array( 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW.cmVsYXkuZXhhbXBsZQ' ),
			'no relay'         => array( 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg.' ),
			'spaces around it' => array( ' ' . self::KEY . ' ' ),
		);
	}

	public function test_the_client_s_options_are_the_plugin_s(): void {
		$options = SdkClientFactory::options( self::KEY, new Logger() );

		self::assertSame( array( 'key', 'relayTimeoutMs', 'httpTimeoutMs', 'logger' ), array_keys( $options ) );
		self::assertSame( 5000, $options['relayTimeoutMs'] );
		self::assertSame( 8000, $options['httpTimeoutMs'] );
		self::assertInstanceOf( \Closure::class, $options['logger'] );
	}

	public function test_the_client_never_shows_its_key(): void {
		$client = ( new SdkClientFactory( new Logger() ) )->for_key( self::KEY );

		self::assertStringNotContainsString( '17wserpSaW', print_r( $client, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- What a dump of it shows.
		self::assertStringNotContainsString( '17wserpSaW', var_export( $client, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- What a dump of it shows.
	}
}
