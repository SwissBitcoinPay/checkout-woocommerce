<?php
/**
 * What PHPStan knows of the plugin's runtime: its constants, as its main file defines them
 * (ABSPATH and WordPress's own come from phpstan-wordpress's bootstrap); and those of its tests,
 * as their bootstraps and the configuration of WordPress's test library define them as they run
 * (phpstan.neon.dist: dynamicConstantNames, their values being the run's).
 *
 * @package Sattleme\WooCommerce
 */

define( 'SATTLEME_FOR_WOOCOMMERCE_VERSION', '1.0.0' );
define( 'SATTLEME_FOR_WOOCOMMERCE_FILE', dirname( __DIR__, 2 ) . '/sattleme-for-woocommerce.php' );
define( 'WC_VERSION', '11.3.0' );

// tests/bootstrap-unit.php, tests/Integration/bootstrap.php.
define( 'SATTLEME_TESTS_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'SATTLEME_TESTS_ENV', dirname( __DIR__, 2 ) . '/build/test-env/wp-7.1.5-wc-11.3.0' );
define( 'SATTLEME_TESTS_HPOS', true );

// The test library's wp-tests-config.php (tools/tests/wordpress.sh).
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', 'wp' );
define( 'DB_HOST', 'localhost' );
