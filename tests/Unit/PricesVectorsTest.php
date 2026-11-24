<?php
/**
 * The SDK's vectors of prices, through the plugin's Prices.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Sdk\Prices;
use Sattleme\WooCommerce\Tests\Support\Arrays;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The plugin offers the method for, and asks invoices at, exactly the prices the SDK takes: the
 * specification's vectors of SPEC.md §25 and of §4's currencyDigits (fiat.json, copied with the
 * pinned SDK by tools/sdk.php import), its `cases` and its `digits`, run through Prices.
 */
final class PricesVectorsTest extends TestCase {

	/**
	 * The vectors' file, in the pinned copy of the SDK.
	 */
	private const FILE = 'sdk/spec/vectors/fiat.json';

	public function test_the_vectors_are_the_specification_s(): void {
		$pin = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/sdk/spec/PIN.json' ), true );

		self::assertSame( 'https://github.com/sattleme/checkout-spec', Arrays::at( $pin, 'repository' ) );
		self::assertSame( hash_file( 'sha256', dirname( __DIR__, 2 ) . '/' . self::FILE ), Arrays::at( $pin, 'files', 'vectors/fiat.json' ) );
		// Every case is run: none of them is left out for its form.
		self::assertCount( count( self::vectors( 'cases' ) ), self::cases() );
		self::assertCount( count( self::vectors( 'digits' ) ), self::digits() );
		self::assertGreaterThanOrEqual( 30, count( self::cases() ) );
		self::assertGreaterThanOrEqual( 20, count( self::digits() ) );
	}

	/**
	 * @dataProvider cases
	 *
	 * @param string $amount   The order's price.
	 * @param string $currency Its currency.
	 * @param mixed  $expected What the SDK makes of it: a Fiat's JSON, or an error.
	 */
	public function test_a_total_is_a_price_exactly_when_the_sdk_takes_it( string $amount, string $currency, mixed $expected ): void {
		$price = Prices::amount( $amount, $currency );

		if ( null !== Arrays::at( $expected, 'error' ) ) {
			self::assertSame(
				array(
					'error' => 'invalid_order',
					'param' => 'fiat',
				),
				$expected
			);
			self::assertNull( $price, 'refused, as the SDK refuses it' );
			$fiat = array(
				'amount'   => $amount,
				'currency' => $currency,
			);
			self::assertFalse( Prices::is_price_of( $fiat, $amount, $currency ) );

			return;
		}
		$digits = Arrays::at( $expected, 'digits' );
		self::assertIsInt( $digits );
		self::assertNotNull( $price, 'taken, as the SDK takes it' );
		self::assertSame( $digits, Prices::digits( $currency ) );
		// The SDK reads the plugin's price as the vector's minor units.
		[ $whole, $fraction ] = array_pad( explode( '.', $price ), 2, '' );
		self::assertSame( Arrays::at( $expected, 'minor' ), ltrim( $whole . str_pad( $fraction, $digits, '0' ), '0' ) );
		// An invoice the SDK made at that price is one of an order of that total.
		self::assertTrue( Prices::is_price_of( $expected, $amount, $currency ) );
	}

	/**
	 * The `cases` of fiat.json: an order's price, and the SDK's Fiat or error.
	 *
	 * @return array<string, array{string, string, mixed}>
	 */
	public static function cases(): array {
		$cases = array();
		foreach ( self::vectors( 'cases' ) as $index => $case ) {
			$amount   = Arrays::at( $case, 'input', 'amount' );
			$currency = Arrays::at( $case, 'input', 'currency' );
			if ( is_string( $amount ) && is_string( $currency ) ) {
				$cases[ "#{$index} \"{$amount}\" {$currency}" ] = array( $amount, $currency, Arrays::at( $case, 'expected' ) );
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider digits
	 *
	 * @param string   $code     A text.
	 * @param int|null $expected The decimals of the currency whose code it is exactly, or null.
	 */
	public function test_a_currency_and_its_decimals_are_the_sdk_s( string $code, ?int $expected ): void {
		self::assertSame( $expected, Prices::digits( $code ) );
		self::assertSame( null !== $expected, Prices::is_supported_currency( $code ) );
	}

	/**
	 * The `digits` of fiat.json: a text, and the decimals currencyDigits gives for it.
	 *
	 * @return array<string, array{string, int|null}>
	 */
	public static function digits(): array {
		$digits = array();
		foreach ( self::vectors( 'digits' ) as $index => $case ) {
			$code     = Arrays::at( $case, 'code' );
			$expected = Arrays::at( $case, 'expected' );
			if ( is_string( $code ) && ( null === $expected || is_int( $expected ) ) ) {
				$digits[ "#{$index} \"{$code}\"" ] = array( $code, $expected );
			}
		}

		return $digits;
	}

	/**
	 * The list $name of fiat.json.
	 *
	 * @param string $name "cases" or "digits".
	 * @return array<int|string, mixed>
	 */
	private static function vectors( string $name ): array {
		$list = Arrays::at( json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . self::FILE ), true ), $name );

		return is_array( $list ) ? $list : array();
	}
}
