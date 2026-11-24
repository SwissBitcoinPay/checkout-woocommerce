<?php
/**
 * The personal data the plugin keeps.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Privacy;

use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Page\Amounts;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Storage\Shredder;
use Sattleme\WooCommerce\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's personal data tools (Tools → Export / Erase Personal Data) and WooCommerce's
 * removal of an order's personal data (an erasure request, or its automatic anonymization of old
 * orders), for what the plugin keeps: each attempt to pay an order with bitcoin. It holds no
 * name and no email address (the customer's are WooCommerce's), but an on-chain payment is
 * public: its deposit address and its transactions lead to the customer's coins. Those are
 * exported, and erased (Storage\Shredder) when WooCommerce erases the order's personal data.
 */
class Privacy {

	public const ID = 'sattleme-for-woocommerce';

	/** Orders per page of an export or an erasure, as WooCommerce's own. */
	public const PAGE_SIZE = 10;

	/**
	 * Privacy.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 */
	public function __construct( private readonly InvoiceRepository $invoices ) {}

	/**
	 * Registers the exporter (wp_privacy_personal_data_exporters).
	 *
	 * @param mixed $exporters The exporters.
	 * @return array<mixed>
	 */
	public function register_exporter( $exporters ): array {
		$exporters             = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Bitcoin payments (sattle.me)', 'sattleme-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser (wp_privacy_personal_data_erasers).
	 *
	 * @param mixed $erasers The erasers.
	 * @return array<mixed>
	 */
	public function register_eraser( $erasers ): array {
		$erasers             = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Bitcoin payments (sattle.me)', 'sattleme-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * An order deleted for good (woocommerce_before_delete_order) takes its invoices with it:
	 * their deposit addresses and transactions lead to the customer's coins, and no exporter nor
	 * eraser would find them once the order is gone. Their checks stop.
	 *
	 * @param mixed $order_id The order.
	 */
	public function order_deleted( $order_id ): void {
		$order_id = Input::id( $order_id );
		if ( 0 === $order_id ) {
			return;
		}
		foreach ( $this->invoices->delete_for_order( $order_id ) as $attempt ) {
			if ( '' !== $attempt->chain && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( Scheduler::CHECK_HOOK, array( $attempt->id, $attempt->chain ), Scheduler::GROUP );
			}
		}
	}

	/**
	 * An order's post deleted for good (before_delete_post): its order deleted, when WooCommerce
	 * stores orders as posts and the post is deleted without WooCommerce's own hook (the trash
	 * emptied, wp_delete_post()).
	 *
	 * @param mixed $post_id The post.
	 * @param mixed $post    The post's object.
	 */
	public function post_deleted( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && 'shop_order' === $post->post_type ) {
			$this->order_deleted( $post_id );
		}
	}

	/**
	 * The Bitcoin payments of the orders of $email: one item per attempt.
	 *
	 * @param mixed $email The customer's email address.
	 * @param mixed $page  The page, from 1.
	 * @return array{data: list<array{group_id: string, group_label: string, group_description: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$orders = $this->orders_of( $email, $page );
		$data   = array();
		foreach ( $orders as $order ) {
			foreach ( $this->invoices->for_order( $order->get_id() ) as $attempt ) {
				if ( ! $attempt->has_invoice() ) {
					continue;
				}
				$items   = array(
					array(
						'name'  => __( 'Order', 'sattleme-for-woocommerce' ),
						'value' => $order->get_order_number(),
					),
					array(
						'name'  => __( 'Reference', 'sattleme-for-woocommerce' ),
						'value' => $attempt->ref,
					),
					array(
						'name'  => __( 'Amount', 'sattleme-for-woocommerce' ),
						/* translators: %s: an amount in sats. */
						'value' => sprintf( __( '%s sats', 'sattleme-for-woocommerce' ), Amounts::sats( $attempt->sats() ) ),
					),
					array(
						'name'  => __( 'Created', 'sattleme-for-woocommerce' ),
						'value' => gmdate( 'c', $attempt->created_at ),
					),
					array(
						'name'  => __( 'Status', 'sattleme-for-woocommerce' ),
						'value' => $attempt->status_state() ?? '',
					),
				);
				$address = $attempt->onchain_address();
				if ( null !== $address && Shredder::SHREDDED !== $address ) {
					$items[] = array(
						'name'  => __( 'Bitcoin address', 'sattleme-for-woocommerce' ),
						'value' => $address,
					);
				}
				if ( array() !== $attempt->txids() ) {
					$items[] = array(
						'name'  => __( 'Transactions', 'sattleme-for-woocommerce' ),
						'value' => implode( ', ', $attempt->txids() ),
					);
				}
				$data[] = array(
					'group_id'          => self::ID,
					'group_label'       => __( 'Bitcoin payments', 'sattleme-for-woocommerce' ),
					'group_description' => __( 'The Bitcoin invoices of the customer’s orders: an on-chain payment’s address and transactions are public, on the blockchain.', 'sattleme-for-woocommerce' ),
					'item_id'           => 'sattleme-' . $attempt->id,
					'data'              => $items,
				);
			}
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erases what leads to the coins of the customer $email, for the orders whose personal data
	 * WooCommerce erases (its setting "Remove personal data from orders on request"); the others
	 * are retained, as WooCommerce retains them.
	 *
	 * @param mixed $email The customer's email address.
	 * @param mixed $page  The page, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		$orders   = $this->orders_of( $email, $page );
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PAGE_SIZE,
		);
		$enabled  = 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		foreach ( $orders as $order ) {
			if ( array() === $this->invoices->for_order( $order->get_id(), 1 ) ) {
				continue;
			}
			/** This filter is documented in WooCommerce's includes/class-wc-privacy-erasers.php. */
			// As WooCommerce reads it: a truthy value erases.
			if ( (bool) apply_filters( 'woocommerce_privacy_erase_order_personal_data', $enabled, $order ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read as WooCommerce reads it.
				$this->invoices->shred_order( $order->get_id() );
				$response['items_removed'] = true;
				/* translators: %s: the order's number. */
				$response['messages'][] = sprintf( __( 'Removed the Bitcoin address and transactions of order %s.', 'sattleme-for-woocommerce' ), $order->get_order_number() );
			} else {
				$response['items_retained'] = true;
				/* translators: %s: the order's number. */
				$response['messages'][] = sprintf( __( 'The Bitcoin payment data of order %s has been retained.', 'sattleme-for-woocommerce' ), $order->get_order_number() );
			}
		}

		return $response;
	}

	/**
	 * WooCommerce removed an order's personal data (woocommerce_privacy_remove_order_personal_data:
	 * an erasure, or its anonymization of old orders): its invoices lose what leads to the
	 * customer's coins (now if final, else once final: its checks need them until then).
	 *
	 * @param \WC_Order|mixed $order The order.
	 */
	public function order_erased( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->invoices->shred_order( $order->get_id() );
		}
	}

	/**
	 * Suggests a privacy policy's text (Settings → Privacy).
	 */
	public function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p class="privacy-policy-tutorial">' . __( 'This sample text is about the Bitcoin payments the sattle.me plugin takes. Adapt it to your shop.', 'sattleme-for-woocommerce' ) . '</p>'
			. '<p>' . __( 'When you pay with Bitcoin, we store the invoice of your payment with your order: its reference, its amount in sats and in our currency, the exchange rate, the Lightning invoice and, for an on-chain payment, the address you paid and the transactions that paid it. An on-chain payment is public: its address and transactions are visible on the Bitcoin blockchain.', 'sattleme-for-woocommerce' ) . '</p>'
			. '<p>' . __( 'To make and check the invoice, our server asks our sattle.me wallet’s Nostr relays and Lightning address, exchange rate services (CoinGecko, mempool.space, Coinbase, Kraken, OpenNode) and, for an on-chain payment, the explorer mempool.space (or our own Bitcoin node, when we set one) and BlockCypher. They see our server’s IP address and the invoice or address they are asked about, never your name, email or IP address: your browser talks to our shop only.', 'sattleme-for-woocommerce' ) . '</p>';
		wp_add_privacy_policy_content( __( 'sattle.me for WooCommerce', 'sattleme-for-woocommerce' ), wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * The orders of $email (and of its account, if any), a page of them.
	 *
	 * @param mixed $email The customer's email address, as WordPress gives it.
	 * @param mixed $page  The page, from 1.
	 * @return list<\WC_Order>
	 */
	private function orders_of( mixed $email, mixed $page ): array {
		if ( ! is_string( $email ) || false === is_email( $email ) ) {
			return array();
		}
		$customers = array( $email );
		$user      = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$customers[] = $user->ID;
		}
		$orders = wc_get_orders(
			array(
				'limit'    => self::PAGE_SIZE,
				'page'     => max( 1, Input::id( $page ) ),
				'customer' => $customers,
				'type'     => 'shop_order',
			)
		);

		return is_array( $orders ) ? array_values( $orders ) : array();
	}
}
