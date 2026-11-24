<?php
/**
 * The order's box.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What the order's box shows: its attempts, newest first.
 */
final class OrderBoxView {

	/**
	 * A view.
	 *
	 * @param array<int, OrderBoxRow> $rows The attempts.
	 */
	public function __construct( public readonly array $rows ) {}
}
