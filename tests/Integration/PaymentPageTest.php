<?php
/**
 * The payment page.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Page\PaymentPage;
use Sattleme\WooCommerce\Page\PaymentView;
use Sattleme\WooCommerce\Page\Qr;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Tests\Integration\Support\QrReader;
use Sattleme\WooCommerce\Tests\Integration\Support\Redirected;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Tests\Support\Records;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * WooCommerce's order-pay page of the order, in the shop's theme: the QR code (an inline SVG made
 * on the server), the wallet's link, the invoice and the address, the amounts, the time left;
 * every state of the payment; no JavaScript needed; never cached nor indexed; the order's key
 * authorizes it.
 */
final class PaymentPageTest extends TestCase {

	public function test_an_open_invoice_shows_how_to_pay_it(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$html = $this->page( $order );

		$uri = (string) $invoice->payment_uri();
		self::assertStringContainsString( '<h2 class="sattleme-payment__title">Pay with Bitcoin</h2>', $html );
		self::assertStringContainsString( '<svg xmlns="http://www.w3.org/2000/svg"', $html );
		self::assertStringContainsString( 'aria-label="QR code of the Bitcoin payment"', $html );
		self::assertSame( 2, substr_count( $html, 'href="' . esc_url( $uri, array( 'bitcoin', 'lightning' ) ) . '"' ), 'the code and the wallet button' );
		self::assertStringContainsString( 'Open in my wallet', $html );
		self::assertStringContainsString( '<p class="sattleme-payment__text" id="sattleme-lightning">' . Records::BOLT11 . '</p>', $html );
		self::assertStringContainsString( '<p class="sattleme-payment__text" id="sattleme-address">' . Records::ADDRESS . '</p>', $html );
		self::assertStringContainsString( '<strong>0.00049 BTC</strong>', $html );
		self::assertStringContainsString( '(49,000 sats)', $html );
		self::assertStringContainsString( '<span class="sattleme-payment__fiat">' . self::chf( '24.50' ) . '</span>', $html, 'the invoice\'s price, in its currency' );
		self::assertStringContainsString( '<span id="sattleme-countdown" class="sattleme-payment__countdown">15:00</span>', $html );
		self::assertStringContainsString( 'To pay on-chain, send exactly 0.00049 BTC to this address.', $html );
		self::assertStringContainsString( 'data-poll-interval="3"', $html );
	}

	public function test_the_price_shown_is_the_invoice_s(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		// The merchant added an item to the order after its invoice was made.
		$order = $this->reload( $order );
		$order->add_product( $this->product( '10.00' ), 1 );
		$order->calculate_totals( false );
		$order->save();

		$html = $this->page( $this->reload( $order ) );

		self::assertStringContainsString( '<span class="sattleme-payment__fiat">' . self::chf( '24.50' ) . '</span>', $html, 'what the invoice is paid for' );
		self::assertStringContainsString( '>CHF</span>24.50</span>', str_replace( '&#067;&#072;&#070;', 'CHF', $html ) );
		self::assertStringNotContainsString( '34.50', $html );
	}

	public function test_the_qr_code_scans_to_the_payment_uri(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$html = $this->page( $order );

		self::assertSame( 1, preg_match( '/<svg .*?<\/svg>/s', $html, $svg ) );
		self::assertSame( $invoice->payment_uri(), QrReader::read( $svg[0] ) );
	}

	public function test_a_lightning_invoice_alone(): void {
		$order                  = $this->order( $this->product( '0.30' ) );
		$now                    = $this->clock()->now();
		$this->sdk()->creates[] = static fn ( array $params ): Invoice => Sdk::invoice( $params, $now, false );
		$invoice                = $this->invoice_for( $order );

		$html = $this->page( $order );

		self::assertStringStartsWith( 'lightning:', (string) $invoice->payment_uri() );
		self::assertStringContainsString( 'id="sattleme-lightning"', $html );
		self::assertStringNotContainsString( 'id="sattleme-address"', $html );
		self::assertStringNotContainsString( 'To pay on-chain', $html );
	}

	public function test_an_invoice_made_without_a_deposit_address_says_nothing_of_on_chain(): void {
		// 49,000 sats, but no address could be leased (its lot empty, or the explorer not answering).
		$order                  = $this->order();
		$now                    = $this->clock()->now();
		$this->sdk()->creates[] = static fn ( array $params ): Invoice => Sdk::invoice( $params, $now, false );
		$invoice                = $this->invoice_for( $order );

		$html = $this->page( $order );

		self::assertSame( '49000', $invoice->sats() );
		self::assertNull( $invoice->onchain_address() );
		self::assertStringStartsWith( 'lightning:', (string) $invoice->payment_uri() );
		self::assertStringContainsString( 'id="sattleme-lightning"', $html );
		self::assertStringContainsString( '(49,000 sats)', $html );
		foreach ( array( 'id="sattleme-address"', 'on-chain', 'Bitcoin address', 'confirmed by the Bitcoin network', 'bitcoin:' ) as $absent ) {
			self::assertStringNotContainsString( $absent, $html, $absent );
		}
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note  = $notes[0] ?? null;
		self::assertInstanceOf( \stdClass::class, $note );
		self::assertIsString( $note->content );
		self::assertStringContainsString( 'sats, payable in Lightning until', $note->content, 'its note says how it can be paid' );
	}

	public function test_the_page_works_without_javascript(): void {
		$order = $this->order();
		$this->invoice_for( $order );

		$html = $this->page( $order );

		// Copying needs the script: its buttons are shown by it.
		self::assertSame( 2, preg_match_all( '/<button type="button" class="button wp-element-button sattleme-payment__copy" hidden/', $html ) );
		self::assertSame( 1, preg_match( '#<noscript>\s*<p><a class="button wp-element-button" href="([^"]+)">Check the payment</a></p>\s*</noscript>#', $html, $check ) );
		self::assertStringContainsString( 'sattleme_check=1', html_entity_decode( $check[1] ) );
		self::assertStringContainsString( 'key=' . $order->get_order_key(), html_entity_decode( $check[1] ) );
		self::assertStringNotContainsString( '<script', $html );
	}

	public function test_an_underpayment_shows_its_rest(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->apply( $invoice, Sdk::open( (int) $invoice->expires_at, '20000' ) );

		$html = $this->page( $order );

		self::assertStringContainsString( 'We received 20,000 sats, less than the amount of this order. Please pay the remaining 29,000 sats.', $html );
		self::assertStringContainsString( 'Remaining amount', $html );
		self::assertStringContainsString( '<strong>0.00029 BTC</strong>', $html );
		self::assertStringContainsString( 'lnbc29000n1rest', $html );
		self::assertStringNotContainsString( '>' . Records::BOLT11 . '<', $html, 'the first invoice is not shown again' );
	}

	public function test_a_payment_being_confirmed(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->apply( $invoice, Sdk::unconfirmed( 1 ) );

		$html = $this->page( $order );

		self::assertStringContainsString( 'Your payment has arrived: it is being confirmed.', $html );
		self::assertStringContainsString( '1 of 3 confirmations', $html );
		self::assertStringNotContainsString( '<svg', $html );
		self::assertStringContainsString( 'data-poll-interval="30"', $html );
	}

	public function test_an_invoice_that_ended(): void {
		$order              = $this->order();
		$invoice            = $this->invoice_for( $order );
		$this->clock()->now = (int) $invoice->expires_at + 10;

		$html = $this->page( $order );

		self::assertStringContainsString( 'The time to pay this invoice is over.', $html );
		self::assertStringNotContainsString( '<svg', $html );
		self::assertStringContainsString( 'data-poll-interval="5"', $html );
	}

	public function test_an_invoice_that_expired_unpaid_offers_to_pay_again(): void {
		$order              = $this->order();
		$invoice            = $this->invoice_for( $order );
		$this->clock()->now = $invoice->end();
		$this->apply( $invoice, Sdk::expired() );

		$html = $this->page( $order );

		self::assertStringContainsString( 'This invoice expired without being paid.', $html );
		self::assertStringContainsString( '>Pay again</a>', $html );
		self::assertStringContainsString( esc_url( $this->reload( $order )->get_checkout_payment_url() ), $html );
		self::assertStringNotContainsString( 'data-poll-interval', $html );
		self::assertStringNotContainsString( '<noscript>', $html );
	}

	public function test_an_invoice_that_expired_underpaid(): void {
		$order              = $this->order();
		$invoice            = $this->invoice_for( $order );
		$this->clock()->now = $invoice->end();
		$this->apply( $invoice, Sdk::expired( '20000' ) );

		$html = $this->page( $order );

		self::assertStringContainsString( 'Your Bitcoin payment ended before it was complete: we received 20,000 of the 49,000 sats asked. We will contact you to settle the difference.', $html );
		self::assertStringNotContainsString( '>Pay again</a>', $html, 'what came is the merchant\'s to settle' );
	}

	public function test_no_invoice_yet_offers_to_try_again(): void {
		$order = $this->order();

		$html = $this->page( $order );

		self::assertStringContainsString( 'No Bitcoin invoice could be created for this order yet.', $html );
		self::assertStringContainsString( '>Try again</a>', $html );
	}

	public function test_what_the_page_shows_is_escaped(): void {
		$order                  = $this->order();
		$invoice                = $this->invoice_for( $order );
		$data                   = (array) $invoice->invoice;
		$data['bolt11']         = '<script>alert(1)</script>';
		$data['onchainAddress'] = '"><img src=x onerror=alert(1)>';
		$data['paymentUri']     = 'javascript:alert(1)';
		$this->services()->invoices->store_invoice( $invoice->id, $data, (int) $invoice->expires_at, $this->clock()->now() + 60 );

		$html = $this->page( $order );

		self::assertStringNotContainsString( '<script>alert(1)', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringNotContainsString( '<svg', $html, 'no code of a URI that is not a payment\'s' );
	}

	public function test_the_order_s_key_authorizes_the_page(): void {
		$order = $this->order();
		$page  = $this->services()->page;

		$this->go_to( $order->get_checkout_payment_url( true ) );
		self::assertSame( $order->get_id(), $page->order_of_request()?->get_id() );

		$this->go_to( add_query_arg( 'key', 'wc_order_' . str_repeat( 'x', 13 ), $order->get_checkout_payment_url( true ) ) );
		self::assertNull( $this->fresh_page()->order_of_request() );

		$this->go_to( remove_query_arg( 'key', $order->get_checkout_payment_url( true ) ) );
		self::assertNull( $this->fresh_page()->order_of_request() );

		$this->go_to( $order->get_checkout_payment_url() );
		self::assertNull( $this->fresh_page()->order_of_request(), 'WooCommerce\'s pay form is not the payment page' );

		$shop = get_permalink( wc_get_page_id( 'shop' ) );
		self::assertIsString( $shop );
		$this->go_to( $shop );
		self::assertNull( $this->fresh_page()->order_of_request() );
	}

	public function test_another_method_s_order_is_not_the_plugin_s(): void {
		$order = $this->order();
		$order->set_payment_method( 'cheque' );
		$order->save();

		$this->go_to( $order->get_checkout_payment_url( true ) );

		self::assertNull( $this->fresh_page()->order_of_request() );
	}

	public function test_the_page_is_never_stored_nor_indexed(): void {
		$order = $this->order();
		$this->go_to( $order->get_checkout_payment_url( true ) );
		$page = $this->fresh_page();

		$headers = $page->headers( array( 'Cache-Control' => 'no-cache, must-revalidate, max-age=0' ) );
		$robots  = $page->robots( array( 'max-image-preview' => 'large' ) );

		self::assertIsArray( $headers );
		self::assertSame( 'no-store, no-cache, must-revalidate, max-age=0, private', $headers['Cache-Control'] );
		self::assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		self::assertIsArray( $robots );
		self::assertTrue( $robots['noindex'] );
		self::assertTrue( $robots['nofollow'] );
		self::assertSame( 10, has_filter( 'wp_headers', array( $this->services()->page, 'headers' ) ), 'after WooCommerce\'s, which drops no-store for visitors' );
	}

	public function test_other_pages_keep_their_headers(): void {
		$this->go_to( home_url( '/' ) );
		$page = $this->fresh_page();

		self::assertSame( array( 'Cache-Control' => 'max-age=600' ), $page->headers( array( 'Cache-Control' => 'max-age=600' ) ) );
		self::assertSame( array(), $page->robots( array() ) );
	}

	public function test_the_script_is_a_file_of_the_plugin_on_the_payment_page_alone(): void {
		$order = $this->order();
		$this->go_to( $order->get_checkout_payment_url( true ) );

		$this->fresh_page()->enqueue();

		$script = wp_scripts()->query( PaymentPage::SCRIPT_HANDLE );
		self::assertInstanceOf( \_WP_Dependency::class, $script );
		self::assertSame( plugins_url( 'assets/js/payment-page.js', SATTLEME_FOR_WOOCOMMERCE_FILE ), $script->src );
		self::assertStringStartsWith( home_url( '/' ), $script->src, 'served by the shop' );
		self::assertSame( 'defer', wp_scripts()->get_data( PaymentPage::SCRIPT_HANDLE, 'strategy' ) );
		self::assertFalse( wp_scripts()->get_data( PaymentPage::SCRIPT_HANDLE, 'after' ), 'no inline script' );
		self::assertFalse( wp_scripts()->get_data( PaymentPage::SCRIPT_HANDLE, 'before' ), 'no inline script' );
		self::assertTrue( wp_style_is( PaymentPage::SCRIPT_HANDLE, 'enqueued' ) );
		self::assertFileExists( SATTLEME_TESTS_PLUGIN_DIR . '/assets/js/payment-page.js' );

		wp_dequeue_script( PaymentPage::SCRIPT_HANDLE );
		wp_dequeue_style( PaymentPage::SCRIPT_HANDLE );
		$this->go_to( home_url( '/' ) );
		$this->fresh_page()->enqueue();
		self::assertFalse( wp_script_is( PaymentPage::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_a_check_asked_without_javascript_runs_then_shows_the_page_again(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->clock()->advance( 10 );
		$this->go_to( add_query_arg( PaymentPage::CHECK_PARAM, '1', $order->get_checkout_payment_url( true ) ) );
		Redirected::catch_them();

		try {
			$this->fresh_page()->before_page();
			self::fail( 'No redirect' );
		} catch ( Redirected $redirect ) {
			self::assertSame( 303, $redirect->status );
			self::assertSame( $order->get_checkout_payment_url( true ), $redirect->location );
		}
		self::assertCount( 1, $this->sdk()->status_calls );
	}

	public function test_checks_asked_without_javascript_are_spaced(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->clock()->advance( 10 );
		$this->go_to( add_query_arg( PaymentPage::CHECK_PARAM, '1', $order->get_checkout_payment_url( true ) ) );
		Redirected::catch_them();

		for ( $i = 0; $i < 3; $i++ ) {
			try {
				$this->fresh_page()->before_page();
			} catch ( Redirected ) {
				// Back to the page.
			}
		}

		self::assertCount( 1, $this->sdk()->status_calls );
	}

	public function test_a_paid_order_goes_to_its_order_received_page(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->apply( $invoice, Sdk::paid() );
		$order = $this->reload( $order );
		$this->go_to( $order->get_checkout_payment_url( true ) );
		Redirected::catch_them();

		try {
			$this->fresh_page()->before_page();
			self::fail( 'No redirect' );
		} catch ( Redirected $redirect ) {
			self::assertSame( $order->get_checkout_order_received_url(), $redirect->location );
		}
	}

	public function test_an_order_cancelled_then_paid_goes_to_its_order_received_page(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->reload( $order )->update_status( 'cancelled' );
		$this->apply( $invoice, Sdk::paid() );
		$order = $this->reload( $order );
		self::assertSame( 'on-hold', $order->get_status() );
		$this->go_to( $order->get_checkout_payment_url( true ) );
		Redirected::catch_them();

		try {
			$this->fresh_page()->before_page();
			self::fail( 'No redirect' );
		} catch ( Redirected $redirect ) {
			self::assertSame( $order->get_checkout_order_received_url(), $redirect->location );
		}
	}

	public function test_the_customer_s_pages_of_an_order_on_hold_for_its_payment_say_it_was_received(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$this->reload( $order )->update_status( 'cancelled' );
		$this->apply( $invoice, Sdk::paid() );
		$order = $this->reload( $order );

		$notice = $this->notice( $order );

		self::assertSame( '<p class="woocommerce-info sattleme-received">Your Bitcoin payment has been received. We will contact you about your order.</p>', $notice );
		self::assertStringNotContainsStringIgnoringCase( 'paid', $notice );
		// WooCommerce's "order received" page (its classic template and its Order Confirmation
		// block fire the first) and the order's page in the customer's account.
		foreach ( array( 'woocommerce_thankyou_sattleme', 'woocommerce_view_order' ) as $hook ) {
			ob_start();
			do_action( $hook, $order->get_id() );
			self::assertStringContainsString( $notice, (string) ob_get_clean(), $hook );
		}
		$page = $this->page( $order );
		self::assertStringContainsString( '<p class="woocommerce-info">Your Bitcoin payment has been received. We will contact you about your order.</p>', $page, 'the payment page, were it shown' );
		self::assertStringNotContainsStringIgnoringCase( 'paid', $page );
	}

	public function test_an_order_whose_invoice_ended_underpaid_goes_to_its_order_page_which_says_what_came(): void {
		$order              = $this->order();
		$invoice            = $this->invoice_for( $order );
		$this->clock()->now = $invoice->end();
		$this->apply( $invoice, Sdk::expired( '20000' ) );
		$order = $this->reload( $order );
		self::assertSame( 'on-hold', $order->get_status() );

		$this->go_to( $order->get_checkout_payment_url( true ) );
		Redirected::catch_them();
		try {
			$this->fresh_page()->before_page();
			self::fail( 'No redirect' );
		} catch ( Redirected $redirect ) {
			self::assertSame( $order->get_checkout_order_received_url(), $redirect->location, 'WooCommerce takes no payment of an order on hold' );
		}

		$text = 'Your Bitcoin payment ended before it was complete: we received 20,000 of the 49,000 sats asked. We will contact you to settle the difference.';
		self::assertSame( '<p class="woocommerce-info sattleme-underpaid">' . $text . '</p>', $this->notice( $order ) );
		foreach ( array( 'woocommerce_thankyou_sattleme', 'woocommerce_view_order' ) as $hook ) {
			ob_start();
			do_action( $hook, $order->get_id() );
			self::assertStringContainsString( $text, (string) ob_get_clean(), $hook );
		}
	}

	public function test_an_order_changed_then_paid_says_its_payment_was_received_too(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		$changed = $this->reload( $order );
		$changed->add_product( $this->product( '10.00' ), 1 );
		$changed->calculate_totals( false );
		$changed->save();
		$this->apply( $invoice, Sdk::paid() );

		self::assertSame( 'on-hold', $this->reload( $order )->get_status() );
		self::assertStringContainsString( 'Your Bitcoin payment has been received.', $this->notice( $this->reload( $order ) ) );
	}

	public function test_other_orders_say_nothing_more(): void {
		$paid    = $this->order();
		$invoice = $this->invoice_for( $paid );
		$this->apply( $invoice, Sdk::paid() );
		$open = $this->order();
		$this->invoice_for( $open );
		$other = $this->order();
		$other->set_payment_method( 'bacs' );
		$other->save();
		$this->reload( $other )->update_status( 'on-hold' );

		self::assertSame( 'processing', $this->reload( $paid )->get_status() );
		self::assertSame( '', $this->notice( $this->reload( $paid ) ), 'WooCommerce says it is paid' );
		self::assertSame( '', $this->notice( $this->reload( $open ) ) );
		self::assertSame( '', $this->notice( $this->reload( $other ) ), 'another method\'s order on hold' );
	}

	public function test_woocommerce_shows_the_page_on_its_order_pay_endpoint(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->go_to( $order->get_checkout_payment_url( true ) );

		$html = do_shortcode( '[woocommerce_checkout]' );

		self::assertStringContainsString( '<ul class="order_details">', $html, 'WooCommerce\'s summary of the order' );
		self::assertStringContainsString( '<section id="sattleme-payment"', $html );
	}

	public function test_woocommerce_refuses_the_page_with_another_key(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->go_to( add_query_arg( 'key', 'wc_order_nope', $order->get_checkout_payment_url( true ) ) );

		$html = do_shortcode( '[woocommerce_checkout]' );

		self::assertStringNotContainsString( 'sattleme-payment', $html );
	}

	public function test_a_text_too_long_for_a_qr_code_has_none(): void {
		self::assertNull( Qr::svg( str_repeat( 'a', 5000 ), 'Label' ) );
		self::assertStringContainsString( 'aria-label="&quot;Label&quot;"', (string) Qr::svg( 'bitcoin:x', '"Label"' ) );
	}

	public function test_the_qr_code_is_printed_through_its_allowlist(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9 9" role="img" aria-label="Code" shape-rendering="crispEdges" onload="alert(1)">'
			. '<script>alert(2)</script><a href="https://example.com/"><rect width="9" height="9" fill="#fff" style="x"/></a>'
			. '<path fill="#000" d="M4 4h1v1h-1z"/></svg>';

		ob_start();
		PaymentPage::template( 'payment-page.php', self::view( 'bitcoin:bc1qexample?amount=0.0001', $svg ) );
		$html = (string) ob_get_clean();

		self::assertSame( 1, preg_match( '#<a class="sattleme-payment__qr" [^>]*>\s*(.*?)\s*</a>#s', $html, $printed ) );
		self::assertSame(
			'<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 9 9" role="img" aria-label="Code" shape-rendering="crispEdges">'
				. 'alert(2)<rect width="9" height="9" fill="#fff" />'
				. '<path fill="#000" d="M4 4h1v1h-1z" /></svg>',
			$printed[1]
		);
		self::assertSame( 'bitcoin:x', QrReader::read( wp_kses( (string) Qr::svg( 'bitcoin:x', 'Code' ), Qr::ALLOWED_HTML ) ), 'what Qr::svg() draws goes through whole' );
	}

	/**
	 * What the payment page shows of an open invoice whose QR code is $qr.
	 *
	 * @param string $uri The payment URI.
	 * @param string $qr  Its QR code.
	 */
	private static function view( string $uri, string $qr ): PaymentView {
		return new PaymentView(
			phase: 'open',
			version: 'v',
			sats: '10,000',
			received_sats: '0',
			to_pay_sats: '10,000',
			to_pay_btc: '0.0001',
			uri: $uri,
			qr: $qr,
			lightning: null,
			address: 'bc1qexample',
			has_onchain: true,
			seconds_left: 600,
			time_left: '10:00',
			until: '12:00',
			confirmations: 0,
			has_received: false,
			poll_interval: 5,
			status_url: 'https://shop.example/status',
			check_url: 'https://shop.example/check',
			pay_again_url: null,
			price: '24.50',
		);
	}

	/**
	 * $amount francs, as WooCommerce formats a price and the page prints it, as WooCommerce's own
	 * receipt page does (wp_kses_post()): their markup changes between versions.
	 *
	 * @param string $amount The amount.
	 */
	private static function chf( string $amount ): string {
		return wp_kses_post(
			wc_price(
				(float) $amount,
				array(
					'currency' => 'CHF',
					'decimals' => 2,
				)
			)
		);
	}

	/**
	 * The payment page's content for $order (WooCommerce's receipt page).
	 *
	 * @param \WC_Order $order The order.
	 */
	private function page( \WC_Order $order ): string {
		ob_start();
		do_action( 'woocommerce_receipt_sattleme', $order->get_id() );

		return (string) ob_get_clean();
	}

	/**
	 * What the plugin adds to the customer's pages of $order (its "order received" page, its page
	 * in the customer's account).
	 *
	 * @param \WC_Order $order The order.
	 */
	private function notice( \WC_Order $order ): string {
		ob_start();
		$this->services()->page->order_notice( $order->get_id() );

		return (string) ob_get_clean();
	}

	/**
	 * A page that read no request yet (each request has its own).
	 */
	private function fresh_page(): PaymentPage {
		return new PaymentPage( $this->services()->invoices, $this->services()->checker, $this->clock() );
	}

	/**
	 * Has $invoice's next check find $status.
	 *
	 * @param InvoiceRecord $invoice The invoice.
	 * @param PaymentStatus $status  The status.
	 */
	private function apply( InvoiceRecord $invoice, PaymentStatus $status ): void {
		$this->sdk()->statuses = array( $status );
		$this->services()->checker->check( $invoice->id, 0, 10 );
	}
}
