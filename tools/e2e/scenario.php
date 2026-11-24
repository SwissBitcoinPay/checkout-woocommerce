<?php
/**
 * The end-to-end scenario, over HTTP, as a merchant and customers use the shop (run.sh, or
 * docker.sh on WordPress's Docker image, set it up: shop.sh):
 *
 * 1. the merchant logs in, opens the payment method's settings, saves the key: it is checked,
 *    never shown again;
 * 2. a customer orders through the Checkout block's API (the Store API), lands on the payment
 *    page (in the theme, the QR code, never cached nor indexed, the plugin's script as a file),
 *    its status endpoint answers, a check without JavaScript works, the payment is made: the
 *    order is paid, with the invoice's reference;
 * 3. another customer orders through the classic checkout; the background check (Action
 *    Scheduler) finds it paid;
 * 4. a third customer orders, the merchant cancels the order, its invoice is paid all the same:
 *    the background check puts it on hold, never paid, and the customer's "order received" page
 *    says the payment was received;
 * 5. two customers order a virtual, downloadable product (an order WooCommerce completes at
 *    once) and never come back to the payment page: the background check alone (WP-CLI's run of
 *    Action Scheduler, without a web request) completes each order, WooCommerce's emails sent
 *    from it; for the second, the first email fails as a fatal error would, and the next check
 *    finishes the completion: its payment noted once, never "paid otherwise";
 * 6. the merchant sees the order's box.
 *
 * Environment: E2E_URL (the site), E2E_WP (the command running WP-CLI on it), E2E_KEY (a
 * well-formed integration key), E2E_PRODUCT (a product's id).
 *
 * @package Sattleme\WooCommerce
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions -- A test's command-line driver, outside WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * The scenario's steps and checks.
 */
final class Sattleme_E2e_Scenario {

	/**
	 * Every body the site sent: the key must be in none.
	 *
	 * @var list<string>
	 */
	private array $bodies = array();

	/**
	 * Checks passed.
	 *
	 * @var int
	 */
	private int $passed = 0;

	/**
	 * The scenario.
	 *
	 * @param string $url     The site.
	 * @param string $wp      The command running WP-CLI.
	 * @param string $key     The integration key.
	 * @param int    $product A product.
	 */
	public function __construct(
		private readonly string $url,
		private readonly string $wp,
		private readonly string $key,
		private readonly int $product,
	) {}

	/**
	 * Runs it.
	 */
	public function run(): void {
		$admin = $this->browser();
		$this->settings( $admin );
		$this->block_checkout();
		$order = $this->classic_checkout();
		$this->cancelled_then_paid();
		$this->completed_by_the_background_check_alone();
		$this->order_box( $admin, $order );
		$this->ok( 'the key is in no page nor answer of the site', ! $this->leaked() );
		echo "# {$this->passed} checks passed\n";
	}

	/**
	 * The merchant saves the key.
	 *
	 * @param CurlHandle $admin The merchant's browser.
	 */
	private function settings( CurlHandle $admin ): void {
		$login = $this->request(
			$admin,
			'POST',
			'/wp-login.php',
			array(
				'log'        => 'admin',
				'pwd'        => 'e2e-admin-password',
				'wp-submit'  => 'Log In',
				'testcookie' => '1',
			),
			array( 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' )
		);
		$this->ok( 'the merchant logs in', 302 === $login['status'] );

		$page = $this->request( $admin, 'GET', '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=sattleme' );
		$this->ok( 'the payment method\'s settings open', 200 === $page['status'] && str_contains( $page['body'], 'name="woocommerce_sattleme_key"' ), $page['status'] . ' ' . substr( $page['body'], 0, 1500 ) );
		$this->ok( 'the key\'s field is empty', 1 === preg_match( '/<input[^>]+name="woocommerce_sattleme_key"[^>]+value=""/', $page['body'] ) );
		$this->ok( 'the settings page shows the logo', str_contains( $page['body'], 'sattleme-logo.svg' ) );
		$form                                 = $this->form_fields( $page['body'], 'mainform' );
		$form['woocommerce_sattleme_enabled'] = '1';
		$form['woocommerce_sattleme_key']     = $this->key;

		$saved = $this->request( $admin, 'POST', '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=sattleme', $form );
		$this->ok( 'the key is checked with sattle.me and saved', 200 === $saved['status'] && str_contains( $saved['body'], 'Connected to E2E Shop.' ) );
		$this->ok( 'the key is never shown again', ! str_contains( $saved['body'], '17wserpSaW' ) );
		$this->ok( 'the key is not in the payment method\'s settings', ! str_contains( $this->wp( 'option get woocommerce_sattleme_settings --format=json' ), '17wserpSaW' ) );
		$this->ok( 'the key is stored apart, not autoloaded', 'off' === trim( $this->wp( 'eval \'global $wpdb; echo $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = \\"sattleme_for_woocommerce_integration_key\\"" );\'' ) ) );
		$options = $this->request( $admin, 'GET', '/wp-admin/options.php' );
		$this->ok( 'WordPress\'s options page shows no key', 200 === $options['status'] && str_contains( $options['body'], 'name="sattleme_for_woocommerce_integration_key"' ) && ! str_contains( $options['body'], 'sattleme_ck_' ), (string) $options['status'] );
	}

	/**
	 * A customer pays through the Checkout block's API, on the payment page.
	 */
	private function block_checkout(): void {
		$customer = $this->browser();
		$this->wp( 'option update sattleme_e2e_status open' );
		$cart  = $this->request( $customer, 'GET', '/wp-json/wc/store/v1/cart' );
		$nonce = $cart['headers']['nonce'] ?? '';
		$this->ok( 'the Store API gives its nonce', '' !== $nonce );
		$added = $this->json(
			$customer,
			'POST',
			'/wp-json/wc/store/v1/cart/add-item',
			array(
				'id'       => $this->product,
				'quantity' => 1,
			),
			$nonce
		);
		$this->ok( 'the product is in the cart', 201 === $added['status'] || 200 === $added['status'], $added['status'] . ' ' . $added['body'] );
		$this->ok( 'the Checkout block offers the method', in_array( 'sattleme', (array) ( $added['data']['payment_methods'] ?? array() ), true ), $added['body'] );

		$address  = array(
			'first_name' => 'Alice',
			'last_name'  => 'Nakamoto',
			'address_1'  => 'Bahnhofstrasse 1',
			'city'       => 'Zurich',
			'postcode'   => '8001',
			'country'    => 'CH',
			'email'      => 'alice@example.com',
			'phone'      => '',
		);
		$checkout = $this->json(
			$customer,
			'POST',
			'/wp-json/wc/store/v1/checkout',
			array(
				'billing_address'  => $address,
				'shipping_address' => $address,
				'payment_method'   => 'sattleme',
			),
			$nonce
		);
		$redirect = self::at( $checkout['data'], 'payment_result', 'redirect_url' );
		$redirect = is_string( $redirect ) ? $redirect : '';
		$this->ok( 'the order is placed and sent to the payment page', 200 === $checkout['status'] && str_contains( $redirect, '/order-pay/' ) && str_contains( $redirect, 'key=wc_order_' ), $checkout['body'] );
		$order_id = self::at( $checkout['data'], 'order_id' );
		$order_id = is_int( $order_id ) ? $order_id : 0;

		$page = $this->request( $customer, 'GET', $redirect );
		$html = $page['body'];
		$this->ok( 'the payment page opens', 200 === $page['status'] && str_contains( $html, '<section id="sattleme-payment"' ) );
		$this->ok( 'it is in the shop\'s theme', str_contains( $html, 'wp-site-blocks' ) || str_contains( $html, 'site-header' ) );
		$this->ok( 'it shows the QR code, made on the server', 1 === preg_match( '/<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" viewBox="0 0 \d+ \d+" role="img"/i', $html ) );
		$this->ok( 'it opens the wallet', str_contains( $html, 'href="bitcoin:bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297?amount=0.00049' ) );
		$this->ok( 'it shows the amount in sats and in francs', str_contains( $html, '49,000 sats' ) && str_contains( $html, '24.50' ) );
		$this->ok( 'it is never stored by a browser or a proxy', str_contains( strtolower( $page['headers']['cache-control'] ?? '' ), 'no-store' ) );
		$this->ok( 'it is never indexed', str_contains( $page['headers']['x-robots-tag'] ?? '', 'noindex' ) && 1 === preg_match( '/<meta name=\'robots\' content=\'[^\']*noindex/', $html ) );
		$script = 1 === preg_match( '#<script[^>]*\ssrc="' . preg_quote( $this->url, '#' ) . '/wp-content/plugins/sattleme-for-woocommerce/assets/js/payment-page\.js\?ver=[^"]+"[^>]*>#', $html, $tag ) ? $tag[0] : '';
		$this->ok( 'its script is a file of the plugin, deferred', 1 === preg_match( '/\sdefer[\s>]/', $script ) );
		$this->ok( 'nothing is loaded from elsewhere for it', 0 === preg_match( '#(src|href)="https?://(?!127\.0\.0\.1)[^"]*sattleme#', $html ) );
		$this->ok( 'it works without JavaScript', str_contains( $html, '<noscript>' ) && str_contains( $html, 'Check the payment' ) );

		$this->ok( 'one invoice was made', 1 === substr_count( $this->wp( 'option get sattleme_e2e_calls --format=json' ), 'createInvoice' ) );
		preg_match( '/data-status-url="([^"]+)"/', $html, $status_url );
		$status = $this->request( $customer, 'GET', html_entity_decode( $status_url[1] ?? '' ) );
		$this->ok( 'the status endpoint says it is open', 200 === $status['status'] && str_contains( $status['body'], '"phase":"open"' ) );
		$wrong = $this->request( $customer, 'GET', (string) preg_replace( '/key=[^&]+/', 'key=wc_order_wrong', html_entity_decode( $status_url[1] ?? '' ) ) );
		$this->ok( 'another order\'s key opens nothing', 404 === $wrong['status'] );

		preg_match( '#<noscript>\s*<p><a class="button wp-element-button" href="([^"]+)"#', $html, $check );
		sleep( 7 );
		$checked = $this->request( $customer, 'GET', html_entity_decode( $check[1] ?? '' ) );
		$this->ok( 'a check without JavaScript shows the page again', 303 === $checked['status'] && str_contains( $checked['headers']['location'] ?? '', '/order-pay/' . $order_id ) );

		$this->wp( 'option update sattleme_e2e_status paid' );
		sleep( 7 );
		$paid = $this->request( $customer, 'GET', html_entity_decode( $status_url[1] ?? '' ) );
		$this->ok( 'once paid, the page goes to the order received', str_contains( $paid['body'], '"phase":"paid"' ) && str_contains( $paid['body'], 'order-received' ) );
		$this->paid( $order_id );
		$again = $this->request( $customer, 'GET', $redirect );
		$this->ok( 'the payment page of a paid order goes to its order received page', 303 === $again['status'] && str_contains( $again['headers']['location'] ?? '', 'order-received' ) );
	}

	/**
	 * A customer orders through the classic checkout; the background check finds the payment.
	 */
	private function classic_checkout(): int {
		$customer                = $this->browser();
		[ $order_id, $redirect ] = $this->classic_order( $customer, 'Bob', 'bob@example.com' );
		$page                    = $this->request( $customer, 'GET', $redirect );
		$this->ok( 'its payment page shows the QR code', str_contains( $page['body'], '<svg xmlns="http://www.w3.org/2000/svg"' ) );

		$this->wp( 'option update sattleme_e2e_status paid' );
		$this->ok( 'its background checks are scheduled', $this->run_checks() > 0 );
		$this->paid( $order_id );

		return $order_id;
	}

	/**
	 * A customer orders through the classic checkout; the merchant cancels the order; its invoice
	 * is paid all the same (it was in the customer's wallet): the background check puts the order
	 * on hold, and the customer's "order received" page says the payment was received.
	 */
	private function cancelled_then_paid(): void {
		$customer                = $this->browser();
		[ $order_id, $redirect ] = $this->classic_order( $customer, 'Carol', 'carol@example.com' );
		$this->wp( 'eval \'wc_get_order( ' . $order_id . ' )->update_status( "cancelled", "Cancelled by the merchant." );\'' );
		$this->wp( 'option update sattleme_e2e_status paid' );
		$this->run_checks();

		$state = trim( $this->wp( 'eval \'$o = wc_get_order( ' . $order_id . ' ); echo $o->get_status(), " ", $o->get_transaction_id() === $o->get_meta( "_sattleme_ref" ) ? "ref" : "-", " ", null === $o->get_date_paid() ? "unpaid" : "dated";\'' ) );
		$this->ok( "order {$order_id}, cancelled then paid, is on hold, its transaction the invoice's reference, no payment dated", 'on-hold ref unpaid' === $state, $state );
		$again    = $this->request( $customer, 'GET', $redirect );
		$location = $again['headers']['location'] ?? '';
		$this->ok( 'its payment page goes to its order received page', 303 === $again['status'] && str_contains( $location, 'order-received' ) );
		$received = $this->request( $customer, 'GET', $location );
		$this->ok( 'which says the payment was received and the shop will contact them', 200 === $received['status'] && str_contains( $received['body'], 'Your Bitcoin payment has been received. We will contact you about your order.' ), (string) $received['status'] );
	}

	/**
	 * Orders of a virtual, downloadable product, which WooCommerce completes at once, paid while
	 * their customers never came back to the payment page: the background check alone completes
	 * them, WooCommerce's emails sent from it (lesson of GrandNode, whose scheduler ran without
	 * the request's context the shop's emails needed). The second one's first email fails as a
	 * fatal error would, after WooCommerce saved the order: the next check finishes it.
	 */
	private function completed_by_the_background_check_alone(): void {
		$ebook = (int) trim( $this->wp( 'wc product create --name="Hodl e-book" --regular_price=24.50 --virtual=true --downloadable=true --user=admin --porcelain' ) );
		$this->ok( 'a virtual, downloadable product', $ebook > 0 );

		[ $order_id ] = $this->classic_order( $this->browser(), 'Dave', 'dave@example.com', $ebook );
		$this->wp( 'option update sattleme_e2e_mails "[]" --format=json' );
		$this->wp( 'option update sattleme_e2e_status paid' );
		$this->run_checks();
		$this->ok( "order {$order_id}, paid, is completed by the background check alone", 'completed final 1' === $this->settled( $order_id ), $this->settled( $order_id ) );
		$mails   = json_decode( $this->wp( 'option get sattleme_e2e_mails --format=json' ), true );
		$subject = trim( $this->wp( 'eval ' . escapeshellarg( '$e = WC()->mailer()->emails["WC_Email_Customer_Completed_Order"]; echo $e->format_string( $e->get_default_subject() );' ) ) );
		$this->ok( 'WooCommerce emailed its customer from the background check that the order is complete', is_array( $mails ) && in_array( 'dave@example.com: ' . $subject, $mails, true ), (string) json_encode( $mails ) . ' ' . $subject );

		[ $order_id ] = $this->classic_order( $this->browser(), 'Erin', 'erin@example.com', $ebook );
		$this->wp( 'option update sattleme_e2e_status paid' );
		$this->wp( 'option update sattleme_e2e_fail_mails 1' );
		$this->run_checks();
		$this->ok( "order {$order_id}: the check completed it, then an email failed, its invoice not final", 'completed open 1' === $this->settled( $order_id ), $this->settled( $order_id ) );
		// Its next check, as Action Scheduler runs it: never within 6 seconds of the last.
		sleep( 7 );
		$this->ok( 'the next background check is scheduled', $this->run_checks() > 0 );
		$this->ok( 'the next check finishes it: its invoice final, its payment noted once, never paid otherwise', 'completed final 1' === $this->settled( $order_id ), $this->settled( $order_id ) );
	}

	/**
	 * Where order $order_id is: its status, whether its latest invoice is final, how many notes say
	 * it was paid ("<status> final|open <notes>"), its transaction the invoice's reference and no
	 * "paid otherwise" note, else "wrong: …".
	 *
	 * @param int $order_id The order.
	 */
	private function settled( int $order_id ): string {
		$code = '$o = wc_get_order( ' . $order_id . ' );'
			. ' $i = \Sattleme\WooCommerce\Plugin::instance()->services()->invoices->latest_for_order( ' . $order_id . ' );'
			. ' $notes = array_map( static fn ( $n ) => $n->content, wc_get_order_notes( array( "order_id" => ' . $order_id . ' ) ) );'
			. ' $paid = count( array_filter( $notes, static fn ( $n ) => str_contains( $n, " paid in Lightning" ) ) );'
			. ' $twice = count( array_filter( $notes, static fn ( $n ) => str_contains( $n, "paid otherwise" ) ) );'
			. ' echo ( null === $i || $o->get_transaction_id() !== $i->ref || 0 !== $twice ) ? "wrong: " . $o->get_transaction_id() . " " . $twice : $o->get_status() . " " . ( $i->is_final ? "final" : "open" ) . " " . $paid;';

		return trim( $this->wp( 'eval ' . escapeshellarg( $code ) ) );
	}

	/**
	 * $name places an order of $product (else the shop's product) through the classic checkout,
	 * with the method: the order, and the payment page it goes to.
	 *
	 * @param CurlHandle $customer The customer's browser.
	 * @param string     $name     Their first name.
	 * @param string     $email    Their email address.
	 * @param int|null   $product  The product.
	 * @return array{int, string}
	 */
	private function classic_order( CurlHandle $customer, string $name, string $email, ?int $product = null ): array {
		$this->wp( 'option update sattleme_e2e_status open' );
		$this->request( $customer, 'GET', '/?add-to-cart=' . ( $product ?? $this->product ) );
		$page = $this->request( $customer, 'GET', '/classic-checkout/' );
		$this->ok( 'the classic checkout offers the method', str_contains( $page['body'], 'payment_method_sattleme' ) );
		preg_match( '/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $page['body'], $nonce );
		$result   = $this->request(
			$customer,
			'POST',
			'/?wc-ajax=checkout',
			array(
				'billing_first_name'                 => $name,
				'billing_last_name'                  => 'Finney',
				'billing_address_1'                  => 'Rue du Rhône 2',
				'billing_city'                       => 'Geneva',
				'billing_postcode'                   => '1204',
				'billing_country'                    => 'CH',
				'billing_email'                      => $email,
				'billing_phone'                      => '0790000000',
				'payment_method'                     => 'sattleme',
				'woocommerce-process-checkout-nonce' => $nonce[1] ?? '',
				'_wp_http_referer'                   => '/classic-checkout/',
			)
		);
		$answer   = json_decode( $result['body'], true );
		$redirect = self::at( $answer, 'redirect' );
		$redirect = is_string( $redirect ) ? $redirect : '';
		$this->ok( 'the classic checkout places the order and goes to the payment page', is_array( $answer ) && 'success' === ( $answer['result'] ?? '' ) && str_contains( $redirect, '/order-pay/' ), $result['body'] );
		preg_match( '#/order-pay/(\d+)/#', $redirect, $id );

		return array( (int) ( $id[1] ?? 0 ), $redirect );
	}

	/**
	 * Runs the background checks Action Scheduler holds: how many.
	 */
	private function run_checks(): int {
		$actions = trim( $this->wp( 'action-scheduler action list --hook=sattleme_for_woocommerce_check --status=pending --format=ids' ) );
		$ids     = preg_split( '/\s+/', $actions, -1, PREG_SPLIT_NO_EMPTY ); // WP-CLI's ids: on one line.
		$ids     = is_array( $ids ) ? $ids : array();
		foreach ( $ids as $action ) {
			$this->wp( 'action-scheduler action run ' . (int) $action );
		}

		return count( $ids );
	}

	/**
	 * The merchant sees the order's Bitcoin payment.
	 *
	 * @param CurlHandle $admin    The merchant's browser.
	 * @param int        $order_id The order.
	 */
	private function order_box( CurlHandle $admin, int $order_id ): void {
		$hpos = '1' === trim( $this->wp( 'eval \'echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 1 : 0;\'' ) );
		$path = $hpos ? '/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $order_id : '/wp-admin/post.php?post=' . $order_id . '&action=edit';
		$page = $this->request( $admin, 'GET', $path );
		$ref  = trim( $this->wp( 'eval \'echo wc_get_order( ' . $order_id . ' )->get_transaction_id();\'' ) );
		$this->ok( 'the order\'s screen shows its Bitcoin payment', 200 === $page['status'] && str_contains( $page['body'], 'Bitcoin payment (sattle.me)' ) && str_contains( $page['body'], $ref ) && str_contains( $page['body'], 'paid (in Lightning)' ) );
	}

	/**
	 * Checks that order $order_id is paid, with its invoice's reference.
	 *
	 * @param int $order_id The order.
	 */
	private function paid( int $order_id ): void {
		$state = trim( $this->wp( 'eval \'$o = wc_get_order( ' . $order_id . ' ); echo $o->get_status(), " ", $o->get_transaction_id(), " ", $o->get_meta( "_sattleme_ref" );\'' ) );
		$parts = explode( ' ', $state );
		$this->ok( "order {$order_id} is paid, its transaction the invoice's reference", 3 === count( $parts ) && 'processing' === $parts[0] && 1 === preg_match( '/^wc-' . $order_id . '-[0-9a-f]{32}$/D', $parts[1] ) && $parts[1] === $parts[2], $state );
	}

	/**
	 * A browser: its own cookies.
	 */
	private function browser(): CurlHandle {
		$curl = curl_init();
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_COOKIEFILE     => '',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_PROXY          => '',
				CURLOPT_NOPROXY        => '*',
			)
		);

		return $curl;
	}

	/**
	 * A request: its status, its headers (names in lower case), its body.
	 *
	 * @param CurlHandle                       $curl    The browser.
	 * @param non-empty-string                 $method  GET or POST.
	 * @param string                           $path    A path of the site, or a URL.
	 * @param array<string, mixed>|string|null $body    A form to post, or a body as it is.
	 * @param array<int, string>               $headers More headers.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws RuntimeException When the site does not answer.
	 */
	private function request( CurlHandle $curl, string $method, string $path, array|string|null $body = null, array $headers = array() ): array {
		$url = str_starts_with( $path, 'http' ) ? $path : $this->url . $path;
		if ( '' === $url ) {
			throw new RuntimeException( 'No URL' );
		}
		$received = array();
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_POSTFIELDS     => is_array( $body ) ? http_build_query( $body ) : ( $body ?? '' ),
				CURLOPT_HTTPGET        => 'GET' === $method,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_HEADERFUNCTION => static function ( CurlHandle $curl, string $line ) use ( &$received ): int {
					$parts = explode( ':', $line, 2 );
					if ( 2 === count( $parts ) ) {
						$received[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}

					return strlen( $line );
				},
			)
		);
		$answer = curl_exec( $curl );
		if ( ! is_string( $answer ) ) {
			throw new RuntimeException( $path . ': ' . curl_error( $curl ) );
		}
		$this->bodies[] = $answer;

		return array(
			'status'  => curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ),
			'headers' => $received,
			'body'    => $answer,
		);
	}

	/**
	 * A JSON request to the Store API.
	 *
	 * @param CurlHandle           $curl   The browser.
	 * @param non-empty-string     $method The method.
	 * @param string               $path   The path.
	 * @param array<string, mixed> $data   The body.
	 * @param string               $nonce  The Store API's nonce.
	 * @return array{status: int, data: array<mixed>, body: string}
	 */
	private function json( CurlHandle $curl, string $method, string $path, array $data, string $nonce ): array {
		$response = $this->request( $curl, $method, $path, (string) json_encode( $data ), array( 'Content-Type: application/json', 'Nonce: ' . $nonce ) );

		return array(
			'status' => $response['status'],
			'data'   => (array) json_decode( $response['body'], true ),
			'body'   => $response['body'],
		);
	}

	/**
	 * The fields of form $id of $html, as the browser would post them.
	 *
	 * @param string $html The page.
	 * @param string $id   The form's id.
	 * @return array<string, string>
	 */
	private function form_fields( string $html, string $id ): array {
		$fields = array();
		if ( 1 !== preg_match( '/<form[^>]+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html, $form ) ) {
			return $fields;
		}
		preg_match_all( '/<input[^>]*>/', $form[0], $inputs );
		foreach ( $inputs[0] as $input ) {
			if ( 1 !== preg_match( '/name="([^"]+)"/', $input, $name ) ) {
				continue;
			}
			$type = 1 === preg_match( '/type="([^"]+)"/', $input, $t ) ? $t[1] : 'text';
			if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! str_contains( $input, 'checked' ) ) {
				continue;
			}
			$fields[ html_entity_decode( $name[1] ) ] = 1 === preg_match( '/value="([^"]*)"/', $input, $value ) ? html_entity_decode( $value[1] ) : '';
		}
		preg_match_all( '/<textarea[^>]*name="([^"]+)"[^>]*>(.*?)<\/textarea>/s', $form[0], $areas, PREG_SET_ORDER );
		foreach ( $areas as $area ) {
			$fields[ html_entity_decode( $area[1] ) ] = html_entity_decode( $area[2] );
		}
		$fields['save'] = 'Save changes';

		return $fields;
	}

	/**
	 * Runs WP-CLI on the site: its output.
	 *
	 * @param string $command The command, after "wp".
	 * @throws RuntimeException When the command fails.
	 */
	private function wp( string $command ): string {
		// Its output alone: what PHP or WP-CLI report goes to stderr, shown should it fail.
		$process = proc_open(
			$this->wp . ' ' . $command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( "wp {$command}: cannot run it" );
		}
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( "wp {$command} failed:\n{$output}\n{$errors}" );
		}

		return rtrim( $output, "\n" );
	}

	/**
	 * Whether a body the site sent holds the key.
	 */
	private function leaked(): bool {
		foreach ( $this->bodies as $body ) {
			if ( str_contains( $body, '17wserpSaW' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The value at $keys in nested arrays (decoded JSON), or null when one of them is missing.
	 *
	 * @param mixed  $data    The arrays.
	 * @param string ...$keys The keys, the outermost first.
	 */
	private static function at( mixed $data, string ...$keys ): mixed {
		foreach ( $keys as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * One check.
	 *
	 * @param string $what    What is checked.
	 * @param bool   $passed  Whether it passed.
	 * @param string $context What to show when it failed.
	 */
	private function ok( string $what, bool $passed, string $context = '' ): void {
		if ( ! $passed ) {
			fwrite( STDERR, "not ok - {$what}\n" . ( '' !== $context ? substr( $context, 0, 2000 ) . "\n" : '' ) );
			exit( 1 );
		}
		++$this->passed;
		echo "ok - {$what}\n";
	}
}

( new Sattleme_E2e_Scenario( (string) getenv( 'E2E_URL' ), (string) getenv( 'E2E_WP' ), (string) getenv( 'E2E_KEY' ), (int) getenv( 'E2E_PRODUCT' ) ) )->run();
