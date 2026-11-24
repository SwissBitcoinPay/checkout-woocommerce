<?php
/**
 * The page where the customer pays.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Page;

use Sattleme\WooCommerce\Payments\Checker;
use Sattleme\WooCommerce\Payments\Schedule;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Storage\InvoiceRecord;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Assets;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Dates;
use Sattleme\WooCommerce\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * The payment page: WooCommerce's own "order-pay" page of the order, as WooCommerce shows it
 * after checkout for payment methods that take the payment on a page of their own (its receipt
 * page: /checkout/order-pay/<id>/?key=<the order's key>). WooCommerce checks the order's key
 * (hash_equals) and that the order still needs payment, then fires
 * woocommerce_receipt_sattleme, which renders this page's content: in the shop's theme, classic
 * or block (the Checkout block renders WooCommerce's classic checkout on its endpoints).
 *
 * It shows what the checks found and runs no check itself, but for a customer without
 * JavaScript who asks for one (?sattleme_check=1). Its script, a file of the plugin, counts down,
 * copies, and asks StatusRoute for news every few seconds.
 *
 * Once paid, the customer goes to WooCommerce's "order received" page; there, and on the
 * order's page of the customer's account, an order on hold for its payment (Phase::RECEIVED)
 * says that the payment was received and that the shop will contact them.
 */
class PaymentPage {

	/** The query parameter of a check asked without JavaScript. */
	public const CHECK_PARAM = 'sattleme_check';

	/** A check run during a web request, in seconds (PHP's default limit is 30). */
	public const WEB_TIMEOUT = 15;

	public const SCRIPT_HANDLE = 'sattleme-for-woocommerce-payment-page';

	/**
	 * The request order_of_request() last answered for: its order id and key.
	 *
	 * @var string|null
	 */
	private ?string $request_memo = null;

	/**
	 * Its answer.
	 *
	 * @var \WC_Order|null
	 */
	private ?\WC_Order $request_order = null;

	/**
	 * A page.
	 *
	 * @param InvoiceRepository $invoices The invoices.
	 * @param Checker           $checker  The checks.
	 * @param Clock             $clock    The clock.
	 */
	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly Checker $checker,
		private readonly Clock $clock,
	) {}

	/**
	 * The receipt page's content (woocommerce_receipt_sattleme): WooCommerce checked the order's
	 * key, and that it needs payment.
	 *
	 * @param int $order_id The order.
	 */
	public function render( $order_id ): void {
		$order = wc_get_order( Input::id( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$invoice = $this->invoices->latest_for_order( $order->get_id() );
		self::template( 'payment-page.php', $this->view( $order, $invoice, $this->clock->now() ) );
	}

	/**
	 * Before the receipt page is sent (template_redirect): a check asked without JavaScript runs,
	 * then the page loads again without its parameter; a paid order goes to WooCommerce's "order
	 * received" page.
	 */
	public function before_page(): void {
		$authorized = $this->order_of_request();
		$order      = null === $authorized ? false : wc_get_order( $authorized->get_id() );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$invoice = $this->invoices->latest_for_order( $order->get_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authorized by the order's key (see order_of_request): no cookie is involved, so no request can be forged.
		if ( isset( $_GET[ self::CHECK_PARAM ] ) ) {
			if ( null !== $invoice && $invoice->has_invoice() ) {
				$this->checker->check( $invoice->id, Schedule::WEB_INTERVAL, self::WEB_TIMEOUT );
			}
			wp_safe_redirect( $order->get_checkout_payment_url( true ), 303 );
			exit;
		}
		$phase = Phase::of( $invoice, $order->get_status(), $order->is_paid(), $this->clock->now() );
		if ( Phase::goes_to_order( $phase, $invoice ) && ! $order->needs_payment() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url(), 303 );
			exit;
		}
	}

	/**
	 * On WooCommerce's "order received" page (woocommerce_thankyou_sattleme, in the classic
	 * template and the Order Confirmation block) and on the order's page of the customer's
	 * account (woocommerce_view_order), WooCommerce having checked the order's key or that it is
	 * the customer's: what customer_text() says of an order on hold for its Bitcoin payment.
	 *
	 * @param int $order_id The order.
	 */
	public function order_notice( $order_id ): void {
		$order = wc_get_order( Input::id( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$text = $this->customer_text( $order );
		if ( null !== $text ) {
			printf( '<p class="woocommerce-info sattleme-%1$s">%2$s</p>', esc_attr( $text[0] ), esc_html( $text[1] ) );
		}
	}

	/**
	 * In WooCommerce's "on hold" email to the customer (woocommerce_email_before_order_table, where
	 * payment methods add their instructions; its template unchanged): what customer_text() says,
	 * after WooCommerce's own words, which say the order waits for its payment to be confirmed.
	 *
	 * @param mixed $order         The order.
	 * @param mixed $sent_to_admin Whether the email goes to the shop.
	 * @param mixed $plain_text    Whether it is in plain text.
	 * @param mixed $email         The email.
	 */
	public function email_notice( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order || true === $sent_to_admin || ! $email instanceof \WC_Email || 'customer_on_hold_order' !== $email->id ) {
			return;
		}
		$text = $this->customer_text( $order );
		if ( null === $text ) {
			return;
		}
		if ( true === $plain_text ) {
			echo esc_html( $text[1] ) . "\n\n";
		} else {
			echo '<p>' . esc_html( $text[1] ) . '</p>';
		}
	}

	/**
	 * What the customer reads of $order when it is on hold for its Bitcoin payment, and its kind
	 * (received, underpaid), else null: that the payment was received and the shop will contact
	 * them (paid after it was cancelled, or at a price it no longer has), never that it is paid;
	 * or, its invoice having ended underpaid, what came of what was asked, and that the shop will
	 * contact them to settle the difference.
	 *
	 * @param \WC_Order $order The order.
	 * @return array{0: string, 1: string}|null
	 */
	private function customer_text( \WC_Order $order ): ?array {
		$invoice = $this->invoices->latest_for_order( $order->get_id() );
		$phase   = Phase::of( $invoice, $order->get_status(), $order->is_paid(), $this->clock->now() );
		if ( Phase::RECEIVED === $phase ) {
			return array( 'received', self::received_text() );
		}
		if ( Phase::EXPIRED === $phase && null !== $invoice && $invoice->has_received() ) {
			return array( 'underpaid', self::underpaid_text( Amounts::sats( $invoice->received() ), Amounts::sats( $invoice->sats() ) ) );
		}

		return null;
	}

	/**
	 * What the customer reads of an invoice that ended underpaid.
	 *
	 * @param string $received What came, in grouped sats.
	 * @param string $sats     The invoice's amount, in grouped sats.
	 */
	public static function underpaid_text( string $received, string $sats ): string {
		return sprintf(
			/* translators: 1: sats received, 2: the invoice's amount in sats. */
			__( 'Your Bitcoin payment ended before it was complete: we received %1$s of the %2$s sats asked. We will contact you to settle the difference.', 'sattleme-for-woocommerce' ),
			$received,
			$sats
		);
	}

	/**
	 * What the customer reads of a payment the order is on hold for (Phase::RECEIVED).
	 */
	public static function received_text(): string {
		return __( 'Your Bitcoin payment has been received. We will contact you about your order.', 'sattleme-for-woocommerce' );
	}

	/**
	 * The page's script and style, on the payment page alone (wp_enqueue_scripts).
	 */
	public function enqueue(): void {
		if ( null === $this->order_of_request() ) {
			return;
		}
		$version = defined( 'SATTLEME_FOR_WOOCOMMERCE_VERSION' ) ? SATTLEME_FOR_WOOCOMMERCE_VERSION : null;
		wp_enqueue_style( self::SCRIPT_HANDLE, Assets::url( 'assets/css/payment-page.css' ), array(), $version );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Assets::url( 'assets/js/payment-page.js' ),
			array(),
			$version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * The page is never stored by a browser or a proxy (wp_headers): WooCommerce sends
	 * "private, no-cache" on its checkout pages, and leaves out "no-store" for visitors; a
	 * payment page must not come back from a cache once paid.
	 *
	 * @param mixed $headers The response's headers.
	 * @return mixed
	 */
	public function headers( $headers ) {
		if ( ! is_array( $headers ) || null === $this->order_of_request() ) {
			return $headers;
		}
		$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0, private';
		$headers['X-Robots-Tag']  = 'noindex, nofollow';

		return $headers;
	}

	/**
	 * The page is never indexed (wp_robots): WooCommerce asks so of its checkout page already.
	 *
	 * @param mixed $robots The robots directives.
	 * @return mixed
	 */
	public function robots( $robots ) {
		if ( ! is_array( $robots ) || null === $this->order_of_request() ) {
			return $robots;
		}
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['follow'], $robots['index'] );

		return $robots;
	}

	/**
	 * What the page shows of the order's invoice and its payment.
	 *
	 * @param \WC_Order          $order   The order.
	 * @param InvoiceRecord|null $invoice Its latest attempt.
	 * @param int                $now     Now.
	 */
	public function view( \WC_Order $order, ?InvoiceRecord $invoice, int $now ): PaymentView {
		$phase = Phase::of( $invoice, $order->get_status(), $order->is_paid(), $now );
		$show  = in_array( $phase, array( Phase::OPEN, Phase::UNDERPAID ), true ) && null !== $invoice;
		$rest  = null === $invoice ? null : $invoice->rest();
		if ( Phase::UNDERPAID === $phase && null !== $rest ) {
			$uri       = self::payment_uri( $rest['paymentUri'] );
			$lightning = $rest['bolt11'];
			$amount    = $rest['sats'];
		} else {
			$uri       = null === $invoice ? null : self::payment_uri( $invoice->payment_uri() );
			$lightning = null === $invoice ? null : $invoice->bolt11();
			$amount    = null === $invoice ? '0' : $invoice->sats();
		}
		$address = null === $invoice ? null : $invoice->onchain_address();
		// On-chain to the invoice's address: its BIP-21 URI says so (a rest below 1,000 sats is
		// payable in Lightning only).
		$onchain = null !== $uri && str_starts_with( $uri, 'bitcoin:' ) && null !== $address;
		$expires = null === $invoice ? null : $invoice->expires_at;
		$left    = null === $expires ? 0 : max( 0, $expires - $now );
		$label   = __( 'QR code of the Bitcoin payment', 'sattleme-for-woocommerce' );
		$until   = null === $expires ? '' : Dates::time( $expires );

		return new PaymentView(
			$phase,
			Phase::version( $invoice, $phase ),
			Amounts::sats( null === $invoice ? '0' : $invoice->sats() ),
			Amounts::sats( null === $invoice ? '0' : $invoice->received() ),
			Amounts::sats( $amount ),
			Amounts::btc( $amount ),
			$show ? $uri : null,
			$show && null !== $uri ? Qr::svg( $uri, $label ) : null,
			$show ? $lightning : null,
			$show && $onchain ? $address : null,
			null !== $address,
			$left,
			sprintf( '%d:%02d', intdiv( $left, 60 ), $left % 60 ),
			$until,
			null === $invoice ? 0 : (int) $invoice->confirmations(),
			null !== $invoice && $invoice->has_received(),
			Phase::poll_interval( $phase ),
			StatusRoute::url( $order ),
			add_query_arg( self::CHECK_PARAM, '1', $order->get_checkout_payment_url( true ) ),
			// Paid again only once its invoice ended unpaid (an underpaid one is the merchant's to settle).
			$order->needs_payment() && ( null === $invoice || ! $invoice->has_invoice() || $invoice->ended_unpaid() ) ? $order->get_checkout_payment_url() : null,
			self::price( $invoice, $order )
		);
	}

	/**
	 * The price $invoice was made for, in its currency, as WooCommerce formats prices (HTML): what
	 * the customer pays, whatever the order became since. The order's total without one.
	 *
	 * @param InvoiceRecord|null $invoice The invoice.
	 * @param \WC_Order          $order   Its order.
	 */
	private static function price( ?InvoiceRecord $invoice, \WC_Order $order ): string {
		$fiat = null === $invoice ? null : ( $invoice->invoice['fiat'] ?? null );
		if ( ! is_array( $fiat ) || ! is_string( $fiat['amount'] ?? null ) || ! is_string( $fiat['currency'] ?? null ) || ! is_int( $fiat['digits'] ?? null ) ) {
			return $order->get_formatted_order_total();
		}

		// WooCommerce formats a float: the amount, a decimal of the currency's digits, as one.
		return wc_price(
			(float) $fiat['amount'],
			array(
				'currency' => $fiat['currency'],
				'decimals' => $fiat['digits'],
			)
		);
	}

	/**
	 * The order of the payment page this request is for, its key checked; else null. The
	 * order-pay endpoint without pay_for_order is WooCommerce's receipt page.
	 */
	public function order_of_request(): ?\WC_Order {
		global $wp;

		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) || ! $wp instanceof \WP ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: the order's key authorizes the page.
		if ( isset( $_GET['pay_for_order'] ) || ! isset( $_GET['key'] ) || ! is_string( $_GET['key'] ) ) {
			return null;
		}
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$order_id = Input::id( $wp->query_vars['order-pay'] ?? 0 );
		$memo     = $order_id . ':' . $key;
		if ( $this->request_memo !== $memo ) {
			$this->request_memo  = $memo;
			$this->request_order = $this->authorized_order( $order_id, $key );
		}

		return $this->request_order;
	}

	/**
	 * Order $order_id when $key is its key (compared in constant time) and the plugin takes its
	 * payment; else null.
	 *
	 * @param int    $order_id The order.
	 * @param string $key      The key.
	 */
	private function authorized_order( int $order_id, string $key ): ?\WC_Order {
		$order = 0 === $order_id ? false : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		if ( GatewaySettings::GATEWAY_ID !== $order->get_payment_method() && null === $this->invoices->latest_for_order( $order->get_id() ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * $uri when it is a payment URI the SDK writes (bitcoin: or lightning:), else null: nothing
	 * else is ever a link.
	 *
	 * @param string|null $uri The payment URI.
	 */
	public static function payment_uri( ?string $uri ): ?string {
		if ( null === $uri ) {
			return null;
		}

		return 1 === preg_match( '/^(bitcoin|lightning):[A-Za-z0-9?=&.:_-]+$/D', $uri ) ? $uri : null;
	}

	/**
	 * Renders a template of the plugin with its view (whose values the template escapes).
	 *
	 * @param string $name The template's file, in templates/.
	 * @param object $view What it shows, as $sattleme.
	 */
	public static function template( string $name, object $view ): void {
		$file = dirname( SATTLEME_FOR_WOOCOMMERCE_FILE ) . '/templates/' . $name;
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The template reads $sattleme.
		( static function ( string $sattleme_template, object $sattleme ): void {
			include $sattleme_template;
		} )( $file, $view );
	}
}
