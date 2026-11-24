<?php
/**
 * The SDK's objects, as its client returns them.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use Sattleme\WooCommerce\Tests\Support\Records;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\DeclaredInvoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Internal\Pricing;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\RateInfo;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Rest;

/**
 * Invoices, statuses and merchants of the SDK (its own classes, prefixed as the plugin embeds
 * them), as a client would return them.
 */
final class Sdk {

	/** The test's price of a bitcoin, in any currency. */
	public const PRICE = '50000';

	/**
	 * The invoice createInvoice would make of $order (its parameters), at $now: its price as the
	 * SDK writes it (its own Pricing, the amount with its currency's decimals).
	 *
	 * @param array<mixed> $order   The order's parameters, as the plugin passed them.
	 * @param int          $now     When it is made.
	 * @param bool         $onchain Whether it has a deposit address.
	 * @param string|null  $bolt11  Its Lightning invoice (null: on-chain only).
	 */
	public static function invoice( array $order, int $now, bool $onchain = true, ?string $bolt11 = Records::BOLT11 ): Invoice {
		$fiat     = is_array( $order['fiat'] ?? null ) ? $order['fiat'] : array();
		$amount   = is_string( $fiat['amount'] ?? null ) ? $fiat['amount'] : '24.5';
		$currency = is_string( $fiat['currency'] ?? null ) ? $fiat['currency'] : 'CHF';
		$duration = is_int( $order['durationSeconds'] ?? null ) ? $order['durationSeconds'] : 900;
		$sats     = self::sats( $amount );
		$address  = $onchain ? Records::ADDRESS : null;
		$uri      = null !== $address
			? 'bitcoin:' . $address . '?amount=' . self::btc( $sats ) . ( null !== $bolt11 ? '&lightning=' . $bolt11 : '' )
			: 'lightning:' . (string) $bolt11;

		return new Invoice(
			is_string( $order['ref'] ?? null ) ? $order['ref'] : 'wc-1-' . str_repeat( 'a', 32 ),
			$bolt11,
			null !== $bolt11 ? str_repeat( 'b', 64 ) : null,
			null,
			$address,
			$uri,
			$sats,
			Pricing::fiat(
				array(
					'amount'   => $amount,
					'currency' => $currency,
				)
			),
			new RateInfo( 'coingecko', $currency, self::PRICE, $now, $now ),
			is_string( $order['note'] ?? null ) ? $order['note'] : 'Order',
			$now,
			$now + $duration,
			null,
			null
		);
	}

	/**
	 * $invoice as the SDK hands it out again (InvoicePending, OrderPaid), declared at $at.
	 *
	 * @param Invoice $invoice The invoice.
	 * @param int     $at      When it was declared.
	 */
	public static function declared( Invoice $invoice, int $at ): DeclaredInvoice {
		return new DeclaredInvoice(
			$invoice->ref,
			$invoice->bolt11,
			$invoice->paymentHash,
			$invoice->verifyUrl,
			$invoice->onchainAddress,
			$invoice->paymentUri,
			$invoice->sats,
			$invoice->fiat,
			$invoice->note,
			$invoice->createdAt,
			$invoice->expiresAt,
			$at
		);
	}

	/**
	 * Not paid yet; with $received sats, the rest asked.
	 *
	 * @param int            $expires_at The invoice's end.
	 * @param numeric-string $received   Sats that came.
	 * @param numeric-string $sats       The invoice's amount.
	 */
	public static function open( int $expires_at, string $received = '0', string $sats = '49000' ): PaymentStatus {
		$rest = null;
		if ( '0' !== $received ) {
			$left = (string) ( (int) $sats - (int) $received );
			$rest = new Rest( $left, 'lnbc' . $left . 'n1rest', 'bitcoin:' . Records::ADDRESS . '?amount=' . self::btc( $left ) . '&lightning=lnbc' . $left . 'n1rest' );
		}

		return PaymentStatus::open( $expires_at, $received, $rest );
	}

	/**
	 * Enough came on-chain, $confirmations deep.
	 *
	 * @param int            $confirmations Its confirmations.
	 * @param numeric-string $received      Sats that came.
	 */
	public static function unconfirmed( int $confirmations = 1, string $received = '49000' ): PaymentStatus {
		return PaymentStatus::unconfirmed( $received, $confirmations, array( Records::TXID ) );
	}

	/**
	 * Paid by $method: lightning, onchain or mixed.
	 *
	 * @param string         $method   How.
	 * @param numeric-string $received Sats that came on-chain.
	 */
	public static function paid( string $method = 'lightning', string $received = '49000' ): PaymentStatus {
		switch ( $method ) {
			case 'onchain':
				return PaymentStatus::paidOnchain( $received, array( Records::TXID ) );
			case 'mixed':
				return PaymentStatus::paidMixed( $received, array( Records::TXID ), array( Records::PREIMAGE ) );
			default:
				return PaymentStatus::paidByLightning( Records::PREIMAGE );
		}
	}

	/**
	 * Never paid; $received sats came all the same.
	 *
	 * @param numeric-string $received Sats that came.
	 */
	public static function expired( string $received = '0' ): PaymentStatus {
		return PaymentStatus::expired( $received );
	}

	/**
	 * The merchant of a key.
	 */
	public static function merchant(): Merchant {
		return new Merchant( 'Satoshi’s <Shop>', '1', '10000000', 25 );
	}

	/**
	 * A price in sats at PRICE: rounded up to the sat, as the SDK does.
	 *
	 * @param string $amount The price, a decimal.
	 * @return numeric-string
	 */
	public static function sats( string $amount ): string {
		return (string) (int) ceil( (float) $amount * 100000000 / (float) self::PRICE );
	}

	/**
	 * Sats as BTC.
	 *
	 * @param string $sats Sats.
	 */
	private static function btc( string $sats ): string {
		return rtrim( rtrim( number_format( (int) $sats / 100000000, 8, '.', '' ), '0' ), '.' );
	}
}
