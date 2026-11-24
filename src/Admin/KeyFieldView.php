<?php
/**
 * The integration key's field.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What the integration key's field shows (templates/admin-key-field.php): never the key.
 */
final class KeyFieldView {

	/**
	 * A view.
	 *
	 * @param string $field_key   The field's name.
	 * @param string $title       Its title.
	 * @param string $description Its description.
	 * @param bool   $has_key     Whether a key is saved.
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly string $title,
		public readonly string $description,
		public readonly bool $has_key,
	) {}
}
