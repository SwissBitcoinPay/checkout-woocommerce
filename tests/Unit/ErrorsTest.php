<?php
/**
 * The SDK's errors, as the plugin records and logs them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\CheckoutUnavailableException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidOptionException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RateException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every error of the SDK: its code, whether it may pass, whether the order is at fault.
 */
final class ErrorsTest extends TestCase {

	/**
	 * @dataProvider errors
	 *
	 * @param \Throwable $error     The error.
	 * @param string     $code      Its code.
	 * @param bool       $transient Whether the same call may succeed later.
	 * @param bool       $order     Whether the order cannot be paid in bitcoin.
	 */
	public function test_each_error( \Throwable $error, string $code, bool $transient, bool $order ): void {
		self::assertSame( $code, Errors::code( $error ) );
		self::assertSame( $transient, Errors::is_transient( $error ) );
		self::assertSame( $order, Errors::is_order_error( $error ) );
		self::assertStringStartsWith( $code . ': ', Errors::describe( $error ) );
	}

	/**
	 * The SDK's errors (its README's table), and the plugin's own codes.
	 *
	 * @return array<string, array{\Throwable, string, bool, bool}>
	 */
	public static function errors(): array {
		return array(
			'invalid_key'                    => array( new InvalidKeyException(), 'invalid_key', false, false ),
			'invalid_option'                 => array( new InvalidOptionException( 'durationSeconds' ), 'invalid_option', false, false ),
			'invalid_order'                  => array( new InvalidOrderException( 'fiat' ), 'invalid_order', false, true ),
			'revoked'                        => array( new RevokedException(), 'revoked', false, false ),
			'channel_not_found'              => array( new ChannelNotFoundException(), 'channel_not_found', true, false ),
			'channel_not_found, newer'       => array( new ChannelNotFoundException( true ), 'channel_not_found', false, false ),
			'network'                        => array( new NetworkException(), 'network', true, false ),
			'invalid_invoice'                => array( new InvalidInvoiceException(), 'invalid_invoice', false, false ),
			'unsupported_currency'           => array( new UnsupportedCurrencyException( 'XAU' ), 'unsupported_currency', false, true ),
			'rate'                           => array( new RateException(), 'rate', true, false ),
			'checkout_unavailable'           => array( new CheckoutUnavailableException(), 'checkout_unavailable', false, false ),
			'onchain_unavailable'            => array( new OnchainUnavailableException(), 'onchain_unavailable', true, false ),
			'a call out of time'             => array( new CancelledException(), Errors::TIMEOUT, true, false ),
			'PHP without what the SDK needs' => array( new UnsupportedEnvironmentException( 'ext-bcmath is missing' ), Errors::ENVIRONMENT, false, false ),
			'anything else'                  => array( new \RuntimeException( 'boom' ), Errors::UNEXPECTED, false, false ),
		);
	}

	public function test_an_unexpected_error_s_message_is_never_described(): void {
		$described = Errors::describe( new \RuntimeException( 'sattleme_ck_secret' ) );

		self::assertStringNotContainsString( 'sattleme_ck_secret', $described );
		self::assertMatchesRegularExpression( '/^error: RuntimeException at ErrorsTest\.php:\d+$/D', $described );
	}

	public function test_the_sdk_s_message_is_described(): void {
		self::assertSame( 'network: No usable answer', Errors::describe( new NetworkException() ) );
	}
}
