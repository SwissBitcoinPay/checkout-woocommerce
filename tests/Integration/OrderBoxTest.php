<?php
/**
 * The merchant's view of an order's Bitcoin payment.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Tests\Support\Arrays;
use Sattleme\WooCommerce\Tests\Support\Records;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\NetworkException;

/**
 * A box on the order's edit screen, HPOS's or the posts': each attempt with its reference, its
 * amount and exchange rate, its address and transactions (linked to mempool.space), its proof of
 * payment, its last check and its error in plain words.
 */
final class OrderBoxTest extends TestCase {

	public function test_the_box_is_on_the_order_s_edit_screen(): void {
		set_current_screen( 'dashboard' );

		$this->services()->order_box->register();

		$screen = OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		$boxes  = Arrays::at( $GLOBALS['wp_meta_boxes'] ?? null, $screen, 'normal', 'default' );
		self::assertIsArray( $boxes );
		self::assertArrayHasKey( 'sattleme-for-woocommerce', $boxes );
	}

	public function test_an_open_invoice(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$html = $this->box( $order );

		self::assertStringContainsString( '<code>' . $invoice->ref . '</code>', $html );
		self::assertStringContainsString( '<tr><th scope="row">Status</th><td>not checked yet</td></tr>', $html );
		self::assertStringContainsString( '0.00049 BTC', $html );
		self::assertStringContainsString( '(49,000 sats)', $html );
		self::assertStringContainsString( '— 24.50 CHF', $html );
		self::assertStringContainsString( '1 BTC = 50000 CHF', $html );
		self::assertStringContainsString( '(coingecko, ', $html );
		self::assertStringContainsString( 'in Lightning, on-chain', $html );
		self::assertStringContainsString( '<code>' . str_repeat( 'b', 64 ) . '</code>', $html, 'the payment hash' );
		self::assertStringContainsString( 'href="https://mempool.space/address/' . Records::ADDRESS . '"', $html );
		self::assertStringContainsString( 'Payable until', $html );
		self::assertStringContainsString( 'Next check', $html );
	}

	public function test_an_invoice_found_open(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->services()->checker->check( $invoice->id, 0, 10 );

		$html = $this->box( $order );

		self::assertStringContainsString( '<tr><th scope="row">Status</th><td>waiting for the payment</td></tr>', $html );
		self::assertStringNotContainsString( '<tr><th scope="row">Last check</th><td>\n\t\t\t\t\t\t–', $html );
	}

	public function test_a_payment_on_chain_and_its_transactions(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::paid( 'mixed', '20000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );

		$html = $this->box( $order );

		self::assertStringContainsString( 'paid (on-chain and in Lightning)', $html );
		self::assertStringContainsString( 'href="https://mempool.space/tx/' . Records::TXID . '"', $html );
		self::assertStringContainsString( '<code>' . Records::PREIMAGE . '</code>', $html, 'the proof of payment' );
		self::assertStringContainsString( 'Preimage of the payment hash <code>' . hash( 'sha256', (string) hex2bin( Records::PREIMAGE ) ) . '</code>', $html, 'with the payment hash it proves' );
		self::assertStringNotContainsString( 'On the address’s page', $html, 'the status lists the transactions' );
		self::assertStringNotContainsString( 'Next check', $html );
	}

	public function test_an_invoice_that_ended_underpaid_leads_to_its_address_s_transactions(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->clock()->now    = $invoice->end() + 60;
		$this->sdk()->statuses = array( Sdk::expired( '30000' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );

		$html = $this->box( $order );

		self::assertStringContainsString( '<tr><th scope="row">Status</th><td>ended underpaid</td></tr>', $html );
		self::assertStringContainsString( '30,000 sats', $html );
		self::assertStringContainsString( '<a href="https://mempool.space/address/' . Records::ADDRESS . '" target="_blank" rel="noopener noreferrer">On the address’s page (mempool.space)</a>', $html );
	}

	public function test_a_failed_check_in_plain_words(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( new NetworkException() );
		$this->services()->checker->check( $invoice->id, 0, 10 );

		$html = $this->box( $order );

		self::assertStringContainsString( 'Your relays, your Lightning address, a rate source, your Bitcoin node or an explorer did not answer. Checks go on.', $html );
		self::assertStringContainsString( '<tr><th scope="row">Status</th><td>its first check failed, checks go on</td></tr>', $html, 'not "not checked yet"' );
	}

	public function test_an_attempt_that_made_no_invoice(): void {
		$order                  = $this->order();
		$this->sdk()->creates[] = new \Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RevokedException();
		try {
			$this->invoice_for( $order );
		} catch ( \Sattleme\WooCommerce\Payments\PaymentFailure ) {
			// Refused.
		}

		$html = $this->box( $order );

		self::assertStringContainsString( 'no invoice (failed)', $html );
		self::assertStringContainsString( 'The integration was removed in the sattle.me app: create a new one and enter its key.', $html );
	}

	public function test_personal_data_removed(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::paid( 'onchain' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		$this->services()->invoices->shred_order( $order->get_id() );

		$html = $this->box( $order );

		self::assertStringNotContainsString( Records::ADDRESS, $html );
		self::assertStringNotContainsString( Records::TXID, $html );
		self::assertStringContainsString( 'in Lightning, on-chain', $html );
		self::assertStringContainsString( 'Removed: the address and the transactions are erased once the payment is final.', $html );
	}

	public function test_what_the_box_shows_is_escaped(): void {
		$order                  = $this->order();
		$invoice                = $this->invoice_for( $order );
		$data                   = (array) $invoice->invoice;
		$data['rate']           = array(
			'source'    => '<script>x</script>',
			'currency'  => 'CHF',
			'price'     => '"><b>1</b>',
			'fetchedAt' => 1,
		);
		$data['onchainAddress'] = 'bc1<i>x</i>';
		$this->services()->invoices->store_invoice( $invoice->id, $data, (int) $invoice->expires_at, $this->clock()->now() + 60 );

		$html = $this->box( $order );

		self::assertStringNotContainsString( '<script>x', $html );
		self::assertStringNotContainsString( '<b>1</b>', $html );
		self::assertStringNotContainsString( '<i>x</i>', $html );
		self::assertStringNotContainsString( 'mempool.space/address/bc1', $html, 'no link for what is not an address' );
	}

	public function test_an_order_without_an_attempt(): void {
		self::assertStringContainsString( 'No Bitcoin invoice was made for this order.', $this->box( $this->order() ) );
	}

	/**
	 * The box of $order, as its edit screen renders it.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function box( \WC_Order $order ): string {
		ob_start();
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->services()->order_box->render( $this->reload( $order ) );
		} else {
			$this->services()->order_box->render( get_post( $order->get_id() ) );
		}

		return (string) ob_get_clean();
	}
}
