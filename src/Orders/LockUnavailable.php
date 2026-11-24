<?php
/**
 * A lock not taken.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * The order's lock could not be taken: another request held it for as long as this one waited,
 * or the database refused it.
 */
final class LockUnavailable extends \RuntimeException {}
