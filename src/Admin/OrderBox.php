<?php
/**
 * The merchant's view of an order's Bitcoin payment.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Sattleme\WooCommerce\Page\Amounts;
use Sattleme\WooCommerce\Page\PaymentPage;
use Sattleme\WooCommerce\Sdk\Errors;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Storage\Shredder;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A box on the order's edit screen (HPOS or posts): each attempt to pay it with bitcoin, newest
 * first, with its reference, amount and exchange rate, deposit address, transactions (linked to
 * mempool.space, or its address's page for an underpayment whose status lists none), proofs of
 * payment (each preimage with the payment hash it proves), its last check and its error in plain
 * words.
 */
class OrderBox {

	/** A deposit address (bech32m, BIP-350) or a transaction id, as a link's path may hold them. */
	private const ADDRESS = '/^bc1[ac-hj-np-z02-9]{11,87}$/D';

	/**
	 * A box.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 * @param Clock             $clock    The clock.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly Clock $clock,
	) {}

	/**
	 * Adds the box to the order's edit screen (add_meta_boxes), HPOS's or the posts'.
	 */
	public function register(): void {
		$screen = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'sattleme-for-woocommerce', __( 'Bitcoin payment (sattle.me)', 'sattleme-for-woocommerce' ), array( $this, 'render' ), $screen, 'normal', 'default' );
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post|\WC_Order|mixed $post_or_order The post (posts storage) or the order (HPOS).
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = array();
		foreach ( $this->invoices->for_order( $order->get_id() ) as $attempt ) {
			$rows[] = $this->row( $attempt );
		}
		PaymentPage::template( 'admin-order-box.php', new OrderBoxView( $rows ) );
	}

	/**
	 * What the box shows of one attempt.
	 *
	 * @param InvoiceRecord $attempt The attempt.
	 */
	public function row( InvoiceRecord $attempt ): OrderBoxRow {
		$invoice = $attempt->invoice ?? array();
		$rate    = is_array( $invoice['rate'] ?? null ) ? $invoice['rate'] : array();
		$fiat    = is_array( $invoice['fiat'] ?? null ) ? $invoice['fiat'] : array();
		$address = $attempt->onchain_address();
		$onchain = null !== $address;
		$address = Shredder::SHREDDED === $address ? null : $address;
		$txids   = array();
		foreach ( $attempt->txids() as $txid ) {
			$txids[] = array( $txid, 'https://mempool.space/tx/' . $txid );
		}
		$address_link = null !== $address && 1 === preg_match( self::ADDRESS, $address ) ? 'https://mempool.space/address/' . $address : null;
		// Each proof with the payment hash it proves (its SHA-256): the invoice's, or a rest's.
		$preimages = array();
		foreach ( $attempt->preimages() as $preimage ) {
			$preimages[] = array( $preimage, hash( 'sha256', (string) hex2bin( $preimage ) ) );
		}
		$fiat_amount   = $fiat['amount'] ?? null;
		$fiat_currency = $fiat['currency'] ?? null;
		$price         = $rate['price'] ?? null;
		$rate_currency = $rate['currency'] ?? null;
		$source        = $rate['source'] ?? null;
		$fetched_at    = $rate['fetchedAt'] ?? null;

		return new OrderBoxRow(
			$attempt->ref,
			$attempt->has_invoice(),
			$this->state_text( $attempt ),
			Amounts::sats( $attempt->sats() ),
			Amounts::btc( $attempt->sats() ),
			is_string( $fiat_amount ) && is_string( $fiat_currency ) ? $fiat_amount . ' ' . $fiat_currency : null,
			is_string( $price ) && is_string( $rate_currency ) ? '1 BTC = ' . $price . ' ' . $rate_currency : null,
			is_string( $source ) ? $source : null,
			is_int( $fetched_at ) ? self::date( $fetched_at ) : null,
			null !== $attempt->bolt11(),
			$onchain,
			$attempt->payment_hash(),
			$address,
			$address_link,
			self::date( $attempt->created_at ),
			null === $attempt->expires_at ? null : self::date( $attempt->expires_at ),
			$attempt->has_received() ? Amounts::sats( $attempt->received() ) : null,
			$attempt->confirmations(),
			$txids,
			// What came on-chain without a status that lists its transactions (an underpayment):
			// they are on the address's page.
			$attempt->has_received() && array() === $txids ? $address_link : null,
			$preimages,
			null === $attempt->checked_at ? null : self::date( $attempt->checked_at ),
			$attempt->checks,
			Errors::explain( $attempt->error ),
			$attempt->is_final || null === $attempt->next_check_at ? null : self::date( $attempt->next_check_at ),
			$attempt->shredded
		);
	}

	/**
	 * Where the attempt is, in words.
	 *
	 * @param InvoiceRecord $attempt The attempt.
	 */
	private function state_text( InvoiceRecord $attempt ): string {
		if ( ! $attempt->has_invoice() ) {
			if ( InvoiceRecord::STATE_FAILED === $attempt->state ) {
				return __( 'no invoice (failed)', 'sattleme-for-woocommerce' );
			}

			return InvoiceRecord::STATE_RETRY === $attempt->state ? __( 'no invoice yet (asked again at the next try)', 'sattleme-for-woocommerce' ) : __( 'being created', 'sattleme-for-woocommerce' );
		}
		if ( InvoiceRecord::OUTCOME_ABANDONED === $attempt->outcome ) {
			return __( 'given up: no final status after 90 days', 'sattleme-for-woocommerce' );
		}
		switch ( $attempt->status_state() ) {
			case 'paid':
				switch ( $attempt->method() ) {
					case 'lightning':
						return __( 'paid (in Lightning)', 'sattleme-for-woocommerce' );
					case 'onchain':
						return __( 'paid (on-chain)', 'sattleme-for-woocommerce' );
					default:
						return __( 'paid (on-chain and in Lightning)', 'sattleme-for-woocommerce' );
				}
			case 'unconfirmed':
				return __( 'received, being confirmed', 'sattleme-for-woocommerce' );
			case 'expired':
				return $attempt->has_received() ? __( 'ended underpaid', 'sattleme-for-woocommerce' ) : __( 'expired unpaid', 'sattleme-for-woocommerce' );
			case 'open':
				if ( $attempt->has_received() ) {
					return __( 'partly paid, waiting for the rest', 'sattleme-for-woocommerce' );
				}

				return null !== $attempt->expires_at && $this->clock->now() >= $attempt->expires_at ? __( 'ended, final status being checked', 'sattleme-for-woocommerce' ) : __( 'waiting for the payment', 'sattleme-for-woocommerce' );
			default:
				// No status yet: its checks failed so far (the error below says why), or none ran.
				return $attempt->errors > 0 ? __( 'its first check failed, checks go on', 'sattleme-for-woocommerce' ) : __( 'not checked yet', 'sattleme-for-woocommerce' );
		}
	}

	/**
	 * A date and time, in the site's format and timezone.
	 *
	 * @param int $timestamp The moment, in Unix seconds.
	 */
	private static function date( int $timestamp ): string {
		return Dates::date_time( $timestamp );
	}
}
