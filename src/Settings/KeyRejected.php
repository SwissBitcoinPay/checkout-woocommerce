<?php
/**
 * A key refused.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A key the plugin does not save: its message is what to tell the merchant (translated, escaped
 * for HTML, never the key).
 */
final class KeyRejected extends \RuntimeException {}
