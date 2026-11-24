<?php
/**
 * What the integration tests share.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Gateway;
use Sattleme\WooCommerce\Plugin;
use Sattleme\WooCommerce\Services;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Support\Db;
use Sattleme\WooCommerce\Tests\Integration\Support\FakeCheckout;
use Sattleme\WooCommerce\Tests\Integration\Support\FakeClock;
use Sattleme\WooCommerce\Tests\Integration\Support\Fakes;
use Sattleme\WooCommerce\Tests\Integration\Support\RecordingLogger;

/**
 * A shop in CHF, the payment method enabled with a key, stock held 60 minutes as WooCommerce
 * holds it by default; the SDK faked, the clock at the real time, the log empty.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/** A fake integration key for these tests: well-formed, its relay relay.example (a reserved name: no integration answers there). */
	public const KEY = 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg.cmVsYXkuZXhhbXBsZQ';

	/**
	 * WooCommerce's pages (cart, checkout, my account): the test library deletes every post
	 * after each class of tests.
	 */
	public static function wpSetUpBeforeClass(): void {
		\WC_Install::create_pages();
	}

	/**
	 * The shop and the fakes, as each test starts.
	 */
	public function set_up(): void {
		parent::set_up();
		Fakes::reset();
		update_option( 'woocommerce_currency', 'CHF' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_hold_stock_minutes', '60' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option(
			GatewaySettings::OPTION,
			array(
				'enabled'         => 'yes',
				'key'             => '',
				'invoice_minutes' => '15',
				'title'           => '',
				'description'     => '',
				'min_total'       => '',
				'max_total'       => '',
			)
		);
		$this->services()->keys->save( self::KEY );
		WC()->payment_gateways()->init();
	}

	/**
	 * Whatever the test did, the log never holds the key.
	 */
	public function tear_down(): void {
		self::assertStringNotContainsString( '17wserpSaW', $this->log()->text(), 'the key in the log' );
		self::assertStringNotContainsString( 'AAAAAAAAAAAAAAAA', $this->log()->text(), 'a key in the log' );
		parent::tear_down();
	}

	/**
	 * The plugin's parts.
	 */
	protected function services(): Services {
		return Plugin::instance()->services();
	}

	/**
	 * The clock.
	 */
	protected function clock(): FakeClock {
		return Fakes::clock();
	}

	/**
	 * The SDK's client.
	 */
	protected function sdk(): FakeCheckout {
		return Fakes::sdk();
	}

	/**
	 * The log.
	 */
	protected function log(): RecordingLogger {
		return Fakes::logger();
	}

	/**
	 * The payment method, as WooCommerce has it now (its settings read again).
	 */
	protected function gateway(): Gateway {
		WC()->payment_gateways()->init();
		$gateway = WC()->payment_gateways()->payment_gateways()[ GatewaySettings::GATEWAY_ID ] ?? null;
		self::assertInstanceOf( Gateway::class, $gateway );

		return $gateway;
	}

	/**
	 * A simple product at $price, managing $stock when given.
	 *
	 * @param string   $price Its price.
	 * @param int|null $stock Its stock, when managed.
	 */
	protected function product( string $price = '24.50', ?int $stock = null ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Hodl mug' );
		$product->set_regular_price( $price );
		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
		}
		$product->save();

		return $product;
	}

	/**
	 * A pending order placed at checkout for this method: $quantity of $product (by default a
	 * product of 24.50), its stock held as checkout holds it.
	 *
	 * @param \WC_Product|null $product  The product.
	 * @param int              $quantity How many.
	 * @param string           $email    The customer's email address.
	 */
	protected function order( ?\WC_Product $product = null, int $quantity = 1, string $email = 'alice@example.com' ): \WC_Order {
		$order = wc_create_order(
			array(
				'status'      => 'pending',
				'created_via' => 'checkout',
			)
		);
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->add_product( $product ?? $this->product(), $quantity );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_billing_email( $email );
		$order->set_billing_first_name( 'Alice' );
		$order->set_payment_method( GatewaySettings::GATEWAY_ID );
		$order->set_payment_method_title( 'Bitcoin' );
		$order->calculate_totals( false );
		$order->save();
		wc_reserve_stock_for_order( $order );

		return $this->reload( $order );
	}

	/**
	 * $order, read again from the database.
	 *
	 * @param \WC_Order $order The order.
	 */
	protected function reload( \WC_Order $order ): \WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		self::assertInstanceOf( \WC_Order::class, $fresh );

		return $fresh;
	}

	/**
	 * Makes $order last changed $seconds ago, as WooCommerce's cancellation of unpaid orders
	 * reads it (HPOS: date_updated_gmt; posts: post_modified).
	 *
	 * @param \WC_Order $order   The order.
	 * @param int       $seconds Seconds ago.
	 */
	protected function backdate( \WC_Order $order, int $seconds ): void {
		$wpdb = Db::wpdb();
		$gmt  = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		if ( SATTLEME_TESTS_HPOS ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $gmt ), array( 'id' => $order->get_id() ) );
		} else {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => get_date_from_gmt( $gmt ),
					'post_modified_gmt' => $gmt,
				),
				array( 'ID' => $order->get_id() )
			);
			clean_post_cache( $order->get_id() );
		}
		wp_cache_flush();
	}

	/**
	 * A new user of $role.
	 *
	 * @param string $role The role.
	 */
	protected static function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		self::assertIsInt( $id );

		return $id;
	}

	/**
	 * The invoice of $order (the plugin's InvoiceCreator), as checkout makes it.
	 *
	 * @param \WC_Order $order The order.
	 */
	protected function invoice_for( \WC_Order $order ): InvoiceRecord {
		return $this->services()->creator->invoice_for( $order );
	}

	/**
	 * Attempt $id, read again.
	 *
	 * @param int $id The attempt.
	 */
	protected function attempt( int $id ): InvoiceRecord {
		$attempt = $this->services()->invoices->find( $id );
		self::assertNotNull( $attempt );

		return $attempt;
	}

	/**
	 * The notes of $order, oldest first.
	 *
	 * @param \WC_Order $order The order.
	 * @return list<string>
	 */
	protected function notes( \WC_Order $order ): array {
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			self::assertIsString( $note->content );
			$notes[] = $note->content;
		}

		return array_reverse( $notes );
	}

	/**
	 * Whether one of $notes contains $text.
	 *
	 * @param string   $text  The text.
	 * @param string[] $notes The notes.
	 */
	protected function assertNoteContains( string $text, array $notes ): void {
		foreach ( $notes as $note ) {
			if ( str_contains( $note, $text ) ) {
				$this->addToAssertionCount( 1 );

				return;
			}
		}
		self::fail( "No note contains \"{$text}\":\n" . implode( "\n", $notes ) );
	}
}
