<?php
/**
 * Prices as the SDK takes them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Sdk\Prices;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The SDK's table of currencies, and an order's total as an exact price.
 */
final class PricesTest extends TestCase {

	public function test_the_currencies_are_the_sdk_s(): void {
		self::assertTrue( Prices::is_supported_currency( 'CHF' ) );
		self::assertTrue( Prices::is_supported_currency( 'EUR' ) );
		self::assertTrue( Prices::is_supported_currency( 'JPY' ) );
		self::assertFalse( Prices::is_supported_currency( 'BTC' ) );
		self::assertFalse( Prices::is_supported_currency( 'XXX' ) );
		self::assertFalse( Prices::is_supported_currency( 'chf' ) );
		self::assertFalse( Prices::is_supported_currency( '' ) );
	}

	public function test_the_decimals_are_the_sdk_s(): void {
		self::assertSame( 2, Prices::digits( 'CHF' ) );
		self::assertSame( 0, Prices::digits( 'JPY' ) );
		self::assertSame( 3, Prices::digits( 'BHD' ) );
		self::assertNull( Prices::digits( 'XYZ' ) );
	}

	/**
	 * @dataProvider prices
	 *
	 * @param string      $total    The order's total.
	 * @param string      $currency Its currency.
	 * @param string|null $expected The SDK's price, or null.
	 */
	public function test_a_total_is_the_exact_price( string $total, string $currency, ?string $expected ): void {
		self::assertSame( $expected, Prices::amount( $total, $currency ) );
	}

	/**
	 * Totals as WooCommerce stores them.
	 *
	 * @return array<string, array{string, string, string|null}>
	 */
	public static function prices(): array {
		return array(
			'two decimals'                => array( '24.50', 'CHF', '24.5' ),
			'a whole amount'              => array( '24.00', 'CHF', '24' ),
			'no decimals'                 => array( '24', 'CHF', '24' ),
			'leading zeros'               => array( '007.10', 'CHF', '7.1' ),
			'a cent'                      => array( '0.01', 'CHF', '0.01' ),
			'yen, stored with decimals'   => array( '1500.00', 'JPY', '1500' ),
			'three decimals'              => array( '1.234', 'BHD', '1.234' ),
			'the largest price'           => array( '90071992547409.91', 'CHF', '90071992547409.91' ),
			'15 digits, in yen'           => array( '999999999999999', 'JPY', '999999999999999' ),
			'a cent above the largest'    => array( '90071992547409.92', 'CHF', null ),
			'above it, in its whole'      => array( '900719925474100', 'CHF', null ),
			'above it, in dinars'         => array( '9007199254740.992', 'BHD', null ),
			'the largest, in dinars'      => array( '9007199254740.991', 'BHD', '9007199254740.991' ),
			'more decimals than the yen'  => array( '1500.50', 'JPY', null ),
			'more decimals than the cent' => array( '24.505', 'CHF', null ),
			'zero'                        => array( '0.00', 'CHF', null ),
			'negative'                    => array( '-5.00', 'CHF', null ),
			'exponent'                    => array( '1e3', 'CHF', null ),
			'empty'                       => array( '', 'CHF', null ),
			'a separator'                 => array( '1,500.00', 'CHF', null ),
			'unknown currency'            => array( '24.50', 'XYZ', null ),
		);
	}

	/**
	 * @dataProvider invoice_prices
	 *
	 * @param mixed  $fiat     The invoice's price (its JSON's fiat).
	 * @param string $total    The order's total.
	 * @param string $currency Its currency.
	 * @param bool   $expected Whether the invoice is of the order's price.
	 */
	public function test_an_invoice_pays_an_order_at_its_price_only( mixed $fiat, string $total, string $currency, bool $expected ): void {
		self::assertSame( $expected, Prices::is_price_of( $fiat, $total, $currency ) );
	}

	/**
	 * Invoices' prices, as the SDK writes them, and orders' totals.
	 *
	 * @return array<string, array{mixed, string, string, bool}>
	 */
	public static function invoice_prices(): array {
		$chf = static fn ( string $amount, string $currency = 'CHF' ): array => array(
			'amount'   => $amount,
			'currency' => $currency,
			'minor'    => '2450',
			'digits'   => 2,
		);

		return array(
			'the same price'                   => array( $chf( '24.50' ), '24.50', 'CHF', true ),
			'written without its zero'         => array( $chf( '24.50' ), '24.5', 'CHF', true ),
			'a whole price'                    => array( $chf( '24.00' ), '24', 'CHF', true ),
			'another price'                    => array( $chf( '24.50' ), '34.50', 'CHF', false ),
			'a cent less'                      => array( $chf( '24.50' ), '24.49', 'CHF', false ),
			'another currency'                 => array( $chf( '24.50', 'EUR' ), '24.50', 'CHF', false ),
			'a total the currency can\'t hold' => array( $chf( '24.50' ), '24.505', 'CHF', false ),
			'no price'                         => array( null, '24.50', 'CHF', false ),
			'a price in sats'                  => array( array( 'amount' => '49000' ), '24.50', 'CHF', false ),
			'an amount that is a number'       => array(
				array(
					'amount'   => 24.5,
					'currency' => 'CHF',
				),
				'24.50',
				'CHF',
				false,
			),
		);
	}
}
