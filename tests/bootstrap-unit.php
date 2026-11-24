<?php
/**
 * The unit tests: the plugin's classes that need neither WordPress nor a database, the pinned
 * SDK and its prefixed copy. The plugin under test is the repository's, or the one in
 * SATTLEME_PLUGIN_DIR (the built archive's, extracted).
 *
 * @package Sattleme\WooCommerce
 */

// The plugin's files return at once without it (they are never loaded but by WordPress).
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

$sattleme_plugin_dir = getenv( 'SATTLEME_PLUGIN_DIR' );
$sattleme_plugin_dir = is_string( $sattleme_plugin_dir ) && '' !== $sattleme_plugin_dir ? rtrim( $sattleme_plugin_dir, '/' ) : dirname( __DIR__ );
define( 'SATTLEME_TESTS_PLUGIN_DIR', $sattleme_plugin_dir );
require SATTLEME_TESTS_PLUGIN_DIR . '/src/autoload.php';
unset( $sattleme_plugin_dir );
