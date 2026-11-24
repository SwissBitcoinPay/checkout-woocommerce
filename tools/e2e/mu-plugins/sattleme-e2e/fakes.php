<?php
/**
 * The end-to-end tests' fake SDK: invoices made of the order's parameters, statuses as the
 * option sattleme_e2e_status says (open, underpaid, unconfirmed, paid, expired), a merchant.
 * Each call is recorded in the option sattleme_e2e_calls.
 *
 * @package Sattleme\WooCommerce
 */

use Sattleme\WooCommerce\Sdk\CheckoutClient;
use Sattleme\WooCommerce\Sdk\ClientFactory;
use Sattleme\WooCommerce\Services;
use Sattleme\WooCommerce\Support\SystemClock;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Internal\Pricing;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\RateInfo;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Rest;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The tests' fake, in one file.

/**
 * The fakes.
 */
final class Sattleme_E2e_Fakes implements ClientFactory, CheckoutClient {

	/** A valid taproot address (bech32m, witness version 1), made for these tests. */
	public const ADDRESS = 'bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297';

	/** The price of a bitcoin, in any currency. */
	public const PRICE = '50000';

	/**
	 * The plugin's parts, with this fake.
	 */
	public static function services(): Services {
		return new Services( new SystemClock(), new self() );
	}

	/**
	 * A client of $key.
	 *
	 * @param string $key The integration key.
	 */
	public function for_key( #[\SensitiveParameter] string $key ): CheckoutClient {
		return $this;
	}

	/**
	 * An invoice for an order: its price at PRICE (the price as the SDK's own Pricing writes it),
	 * payable on-chain and in Lightning.
	 *
	 * @param array<string, mixed> $order        The order.
	 * @param Cancellation         $cancellation When to give up.
	 * @throws \InvalidArgumentException When the order has no reference or no price.
	 */
	public function create_invoice( array $order, Cancellation $cancellation ): Invoice {
		self::record( 'createInvoice ' . wp_json_encode( $order ) );
		$ref      = $order['ref'] ?? null;
		$fiat     = is_array( $order['fiat'] ?? null ) ? $order['fiat'] : array();
		$amount   = $fiat['amount'] ?? null;
		$currency = is_string( $fiat['currency'] ?? null ) ? $fiat['currency'] : 'CHF';
		if ( ! is_string( $ref ) || ! is_string( $amount ) || ! is_numeric( $amount ) ) {
			throw new \InvalidArgumentException( 'An order has a reference and a price' );
		}
		$sats   = bcdiv( bcmul( $amount, '100000000', 8 ), self::PRICE, 0 );
		$bolt11 = 'lnbc' . $sats . 'n1e2e' . substr( md5( $ref ), 0, 20 );
		$now    = time();

		return new Invoice(
			$ref,
			$bolt11,
			hash( 'sha256', $ref ),
			null,
			self::ADDRESS,
			'bitcoin:' . self::ADDRESS . '?amount=' . rtrim( rtrim( bcdiv( $sats, '100000000', 8 ), '0' ), '.' ) . '&lightning=' . $bolt11,
			$sats,
			Pricing::fiat(
				array(
					'amount'   => $amount,
					'currency' => $currency,
				)
			),
			new RateInfo( 'coingecko', $currency, self::PRICE, $now, $now ),
			is_string( $order['note'] ?? null ) ? $order['note'] : '',
			$now,
			$now + ( is_int( $order['durationSeconds'] ?? null ) ? $order['durationSeconds'] : 900 ),
			null,
			null
		);
	}

	/**
	 * The status the option sattleme_e2e_status says.
	 *
	 * @param array<string, mixed> $invoice      The invoice's JSON.
	 * @param Cancellation         $cancellation When to give up.
	 */
	public function get_status( array $invoice, Cancellation $cancellation ): PaymentStatus {
		$state = get_option( 'sattleme_e2e_status', 'open' );
		$state = is_string( $state ) ? $state : 'open';
		self::record( 'getStatus ' . ( is_string( $invoice['ref'] ?? null ) ? $invoice['ref'] : '' ) . ' ' . $state );
		$sats = $invoice['sats'] ?? null;
		if ( ! is_string( $sats ) || ! is_numeric( $sats ) ) {
			$sats = '0';
		}
		$expires = is_int( $invoice['expiresAt'] ?? null ) ? $invoice['expiresAt'] : time();
		switch ( $state ) {
			case 'underpaid':
				$rest = bcsub( $sats, '20000' );

				return PaymentStatus::open( $expires, '20000', new Rest( $rest, 'lnbc' . $rest . 'n1rest', 'lightning:lnbc' . $rest . 'n1rest' ) );
			case 'unconfirmed':
				return PaymentStatus::unconfirmed( $sats, 1, array( str_repeat( 'ab', 32 ) ) );
			case 'paid':
				return PaymentStatus::paidByLightning( str_repeat( 'cd', 32 ) );
			case 'expired':
				return PaymentStatus::expired( '0' );
			default:
				return PaymentStatus::open( $expires, '0', null );
		}
	}

	/**
	 * The merchant.
	 *
	 * @param Cancellation $cancellation When to give up.
	 */
	public function merchant( Cancellation $cancellation ): Merchant {
		self::record( 'merchant' );

		return new Merchant( 'E2E Shop', '1', '10000000', 25 );
	}

	/**
	 * Records a call.
	 *
	 * @param string $call The call.
	 */
	private static function record( string $call ): void {
		$calls   = get_option( 'sattleme_e2e_calls', array() );
		$calls   = is_array( $calls ) ? $calls : array();
		$calls[] = $call;
		update_option( 'sattleme_e2e_calls', $calls, false );
	}
}
