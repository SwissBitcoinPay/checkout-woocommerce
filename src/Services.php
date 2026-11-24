<?php
/**
 * The plugin's parts.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce;

use Sattleme\WooCommerce\Admin\OrderBox;
use Sattleme\WooCommerce\Background\Scheduler;
use Sattleme\WooCommerce\Orders\Guards;
use Sattleme\WooCommerce\Orders\OrderLock;
use Sattleme\WooCommerce\Orders\StockHold;
use Sattleme\WooCommerce\Page\PaymentPage;
use Sattleme\WooCommerce\Page\StatusRoute;
use Sattleme\WooCommerce\Payments\Checker;
use Sattleme\WooCommerce\Payments\InvoiceCreator;
use Sattleme\WooCommerce\Payments\Settlement;
use Sattleme\WooCommerce\Privacy\Privacy;
use Sattleme\WooCommerce\Sdk\ClientFactory;
use Sattleme\WooCommerce\Sdk\SdkClientFactory;
use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Settings\KeyStore;
use Sattleme\WooCommerce\Settings\KeyVerifier;
use Sattleme\WooCommerce\Settings\MerchantInfo;
use Sattleme\WooCommerce\Storage\InvoiceRepository;
use Sattleme\WooCommerce\Support\Clock;
use Sattleme\WooCommerce\Support\Logger;
use Sattleme\WooCommerce\Support\SystemClock;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's parts, made once per request. In production, the server's clock and the SDK's
 * clients; tests give their own (Plugin::boot(), with the testing constant alone).
 */
final class Services {

	/**
	 * The log (WooCommerce's logger, source sattleme-for-woocommerce).
	 *
	 * @var Logger
	 */
	public readonly Logger $logger;

	/**
	 * The SDK's clients, made from the integration key.
	 *
	 * @var ClientFactory
	 */
	public readonly ClientFactory $clients;

	/**
	 * The payment method's settings.
	 *
	 * @var GatewaySettings
	 */
	public readonly GatewaySettings $settings;

	/**
	 * The integration key, as stored.
	 *
	 * @var KeyStore
	 */
	public readonly KeyStore $keys;

	/**
	 * What the key's last check found of the merchant.
	 *
	 * @var MerchantInfo
	 */
	public readonly MerchantInfo $merchant_info;

	/**
	 * The check of a key entered in the settings.
	 *
	 * @var KeyVerifier
	 */
	public readonly KeyVerifier $key_verifier;

	/**
	 * The invoices' table.
	 *
	 * @var InvoiceRepository
	 */
	public readonly InvoiceRepository $invoices;

	/**
	 * The order's lock in the database.
	 *
	 * @var OrderLock
	 */
	public readonly OrderLock $lock;

	/**
	 * WooCommerce's hold of the order's stock.
	 *
	 * @var StockHold
	 */
	public readonly StockHold $stock;

	/**
	 * What a status does to the order.
	 *
	 * @var Settlement
	 */
	public readonly Settlement $settlement;

	/**
	 * The checks of an invoice's status.
	 *
	 * @var Checker
	 */
	public readonly Checker $checker;

	/**
	 * The background checks (Action Scheduler).
	 *
	 * @var Scheduler
	 */
	public readonly Scheduler $scheduler;

	/**
	 * The making of an order's invoice.
	 *
	 * @var InvoiceCreator
	 */
	public readonly InvoiceCreator $creator;

	/**
	 * What keeps an order with a live invoice from being cancelled or paid twice.
	 *
	 * @var Guards
	 */
	public readonly Guards $guards;

	/**
	 * The payment page.
	 *
	 * @var PaymentPage
	 */
	public readonly PaymentPage $page;

	/**
	 * The payment page's status endpoint.
	 *
	 * @var StatusRoute
	 */
	public readonly StatusRoute $status_route;

	/**
	 * The order's box in the admin.
	 *
	 * @var OrderBox
	 */
	public readonly OrderBox $order_box;

	/**
	 * The personal data exporter and eraser.
	 *
	 * @var Privacy
	 */
	public readonly Privacy $privacy;

	/**
	 * The parts, around a clock and the SDK's clients.
	 *
	 * @param Clock              $clock   The clock.
	 * @param ClientFactory|null $clients The SDK's clients (null: the SDK's own).
	 * @param Logger|null        $logger  The log (null: WooCommerce's).
	 */
	public function __construct( public readonly Clock $clock, ?ClientFactory $clients = null, ?Logger $logger = null ) {
		$this->logger        = $logger ?? new Logger();
		$this->clients       = $clients ?? new SdkClientFactory( $this->logger );
		$this->settings      = new GatewaySettings();
		$this->keys          = new KeyStore();
		$this->merchant_info = new MerchantInfo();
		$this->key_verifier  = new KeyVerifier( $this->clients, $this->logger );
		$this->invoices      = new InvoiceRepository();
		$this->lock          = new OrderLock();
		$this->stock         = new StockHold();
		$this->settlement    = new Settlement( $this->invoices, $this->lock, $this->stock, $this->settings, $this->clock, $this->logger );
		$this->checker       = new Checker( $this->invoices, $this->clients, $this->keys, $this->settlement, $this->clock, $this->logger );
		$this->scheduler     = new Scheduler( $this->invoices, $this->checker, $this->lock, $this->clock, $this->logger );
		$this->creator       = new InvoiceCreator( $this->invoices, $this->clients, $this->keys, $this->settings, $this->lock, $this->stock, $this->checker, $this->scheduler, $this->clock, $this->logger );
		$this->guards        = new Guards( $this->invoices, $this->clock );
		$this->page          = new PaymentPage( $this->invoices, $this->checker, $this->clock );
		$this->status_route  = new StatusRoute( $this->invoices, $this->checker, $this->clock );
		$this->order_box     = new OrderBox( $this->invoices, $this->clock );
		$this->privacy       = new Privacy( $this->invoices );
	}

	/**
	 * The production parts.
	 */
	public static function production(): self {
		return new self( new SystemClock() );
	}
}
