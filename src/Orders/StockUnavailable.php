<?php
/**
 * Stock no longer available.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Some of an order's products could not be held again: no longer in stock. Its message is
 * WooCommerce's own (translated, safe to show).
 */
final class StockUnavailable extends \RuntimeException {}
