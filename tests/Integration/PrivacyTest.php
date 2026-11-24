<?php
/**
 * The customer's personal data.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Tests\Support\Arrays;
use Sattleme\WooCommerce\Tests\Support\Records;

/**
 * WordPress's personal data exporter and eraser cover the Bitcoin payments of a customer's
 * orders; an erasure follows WooCommerce's own setting; what leads to the customer's coins (the
 * address, the transactions) goes once the payment is final.
 */
final class PrivacyTest extends TestCase {

	public function test_the_exporter_and_the_eraser_are_registered(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		self::assertSame( 'Bitcoin payments (sattle.me)', Arrays::at( $exporters, 'sattleme-for-woocommerce', 'exporter_friendly_name' ) );
		self::assertSame( 'Bitcoin payments (sattle.me)', Arrays::at( $erasers, 'sattleme-for-woocommerce', 'eraser_friendly_name' ) );
	}

	public function test_the_export_of_a_customer_s_payments(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::paid( 'onchain' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		$this->order( null, 1, 'bob@example.com' );

		$export = $this->services()->privacy->export( 'alice@example.com' );

		self::assertTrue( $export['done'] );
		self::assertCount( 1, $export['data'] );
		$item = $export['data'][0];
		self::assertSame( 'sattleme-for-woocommerce', $item['group_id'] );
		$values = array_column( $item['data'], 'value', 'name' );
		self::assertSame( $invoice->ref, $values['Reference'] );
		self::assertSame( '49,000 sats', $values['Amount'] );
		self::assertSame( 'paid', $values['Status'] );
		self::assertSame( Records::ADDRESS, $values['Bitcoin address'] );
		self::assertSame( Records::TXID, $values['Transactions'] );
	}

	public function test_nothing_is_exported_for_another_email(): void {
		$this->invoice_for( $this->order() );

		self::assertSame( array(), $this->services()->privacy->export( 'nobody@example.com' )['data'] );
		self::assertSame( array(), $this->services()->privacy->export( 'not an email' )['data'] );
	}

	public function test_an_erasure_removes_the_address_and_transactions_when_woocommerce_does(): void {
		update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::paid( 'onchain' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );

		$erased = $this->services()->privacy->erase( 'alice@example.com' );

		self::assertTrue( $erased['items_removed'] );
		self::assertFalse( $erased['items_retained'] );
		self::assertSame( array( 'Removed the Bitcoin address and transactions of order ' . $order->get_order_number() . '.' ), $erased['messages'] );
		$attempt = $this->attempt( $invoice->id );
		self::assertTrue( $attempt->shredded );
		self::assertSame( '█', $attempt->onchain_address() );
		self::assertSame( array(), $attempt->txids() );
		self::assertSame( $invoice->ref, $attempt->ref, 'the merchant\'s records stay' );
		self::assertSame( '49000', $attempt->sats() );
		$values = array_column( $this->services()->privacy->export( 'alice@example.com' )['data'][0]['data'], 'value', 'name' );
		self::assertArrayNotHasKey( 'Bitcoin address', $values );
	}

	public function test_an_invoice_that_may_still_be_paid_keeps_them_until_it_is_final(): void {
		update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$this->services()->privacy->erase( 'alice@example.com' );

		self::assertSame( Records::ADDRESS, $this->attempt( $invoice->id )->onchain_address(), 'its checks need it' );
		$this->sdk()->statuses = array( Sdk::paid( 'onchain' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertSame( '█', $this->attempt( $invoice->id )->onchain_address() );
	}

	public function test_an_erasure_retains_them_when_woocommerce_retains_orders(): void {
		update_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$erased = $this->services()->privacy->erase( 'alice@example.com' );

		self::assertFalse( $erased['items_removed'] );
		self::assertTrue( $erased['items_retained'] );
		self::assertFalse( $this->attempt( $invoice->id )->shredded );
	}

	public function test_woocommerce_s_anonymization_of_an_order_removes_them(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::expired( '20000' ) );
		$this->clock()->now    = $invoice->end();
		$this->services()->checker->check( $invoice->id, 0, 10 );

		\WC_Privacy_Erasers::remove_order_personal_data( $this->reload( $order ) );

		self::assertSame( '█', $this->attempt( $invoice->id )->onchain_address() );
	}

	public function test_a_deleted_order_takes_its_payments_with_it(): void {
		$order                 = $this->order();
		$invoice               = $this->invoice_for( $order );
		$this->sdk()->statuses = array( Sdk::paid( 'onchain' ) );
		$this->services()->checker->check( $invoice->id, 0, 10 );
		self::assertNotSame( array(), $this->attempt( $invoice->id )->txids() );
		$other = $this->invoice_for( $this->order( null, 1, 'bob@example.com' ) );

		$this->reload( $order )->delete( true );

		self::assertNull( $this->services()->invoices->find( $invoice->id ), 'its address and transactions gone with it' );
		self::assertSame( array(), $this->services()->invoices->for_order( $order->get_id() ) );
		self::assertNotNull( $this->services()->invoices->find( $other->id ), 'another order\'s kept' );
	}

	public function test_a_deleted_order_s_checks_stop(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );
		self::assertTrue( as_has_scheduled_action( Scheduler::CHECK_HOOK, array( $invoice->id, $invoice->chain ), Scheduler::GROUP ) );

		$this->reload( $order )->delete( true );

		self::assertFalse( as_has_scheduled_action( Scheduler::CHECK_HOOK, array( $invoice->id, $invoice->chain ), Scheduler::GROUP ) );
		self::assertNull( $this->services()->invoices->find( $invoice->id ) );
	}

	public function test_an_order_s_post_deleted_takes_its_payments_with_it(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		if ( SATTLEME_TESTS_HPOS ) {
			// The order's post is a placeholder of its row in WooCommerce's tables: deleting the
			// post alone leaves the order, and its payments.
			$this->services()->privacy->post_deleted( $order->get_id(), new \WP_Post( (object) array( 'post_type' => 'shop_order_placehold' ) ) );
			self::assertNotNull( $this->services()->invoices->find( $invoice->id ) );

			return;
		}
		// Orders as posts: the trash emptied, without WooCommerce's own hook.
		wp_delete_post( $order->get_id(), true );

		self::assertNull( $this->services()->invoices->find( $invoice->id ) );
	}

	public function test_a_trashed_order_keeps_its_payments(): void {
		$order   = $this->order();
		$invoice = $this->invoice_for( $order );

		$this->reload( $order )->delete( false );

		self::assertNotNull( $this->services()->invoices->find( $invoice->id ), 'it may be restored' );
	}

	public function test_the_privacy_policy_suggests_a_text(): void {
		// As in the admin, where WordPress takes a policy's text while admin_init runs only.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'options-privacy' );
		global $wp_current_filter;
		self::assertIsArray( $wp_current_filter );
		$wp_current_filter[] = 'admin_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As do_action() would have it.
		$this->services()->privacy->policy_content();
		array_pop( $wp_current_filter );

		$policies = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		$texts    = array_column( $policies, 'policy_text', 'plugin_name' );
		$text     = $texts['sattle.me for WooCommerce'] ?? null;
		self::assertIsString( $text );
		self::assertStringContainsString( 'the explorer mempool.space (or our own Bitcoin node, when we set one) and BlockCypher', $text );
	}
}
