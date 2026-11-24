<?php
/**
 * The end-to-end tests' router for PHP's built-in web server: a file is served as it is, every
 * other path goes to WordPress (its pretty permalinks), as Apache's rewrite rules would.
 *
 * Usage: php -S 127.0.0.1:<port> -t <WordPress> tools/e2e/router.php
 *
 * @package Sattleme\WooCommerce
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- The test server's own request.

$sattleme_uri  = $_SERVER['REQUEST_URI'] ?? '/';
$sattleme_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
$sattleme_path = is_string( $sattleme_uri ) ? parse_url( $sattleme_uri, PHP_URL_PATH ) : '/'; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded yet.
$sattleme_path = is_string( $sattleme_path ) ? $sattleme_path : '/';
$sattleme_root = is_string( $sattleme_root ) ? $sattleme_root : '';
$sattleme_file = $sattleme_root . $sattleme_path;
if ( '/' !== $sattleme_path && is_file( $sattleme_file ) && ! str_ends_with( $sattleme_path, '.php' ) ) {
	return false;
}
if ( str_ends_with( $sattleme_path, '.php' ) && is_file( $sattleme_file ) ) {
	$_SERVER['SCRIPT_NAME']     = $sattleme_path;
	$_SERVER['SCRIPT_FILENAME'] = $sattleme_file;
	chdir( dirname( $sattleme_file ) );
	require $sattleme_file;

	return true;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $sattleme_root . '/index.php';
chdir( $sattleme_root );
require $sattleme_root . '/index.php';
