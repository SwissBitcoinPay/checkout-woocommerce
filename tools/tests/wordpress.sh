#!/bin/sh
# WordPress, its PHPUnit test library and WooCommerce, for the integration tests, at pinned
# versions: build/test-env/wp-<WordPress>-wc-<WooCommerce>/ (wordpress/, tests/, and the
# wp-tests-config.php the test library reads). The archives are tools/downloads.sh's, checked
# against their SHA-256; the test library is the wordpress-develop repository's tests/phpunit at
# the version's tag, by its commit.
#
# Usage: sh tools/tests/wordpress.sh <WordPress version> <WooCommerce version>
#   Versions: WordPress 7.0.9 or 7.1.5, WooCommerce 11.1.2 or 11.3.0 (the oldest and the latest
#   supported). Prints the directory.
#
# The database: tools/tests/mariadb.sh, or any MySQL or MariaDB server, named by the environment
# when the tests run (WP_TESTS_DB_HOST, WP_TESTS_DB_NAME, WP_TESTS_DB_USER, WP_TESTS_DB_PASSWORD).
set -eu

WP_VERSION="${1:-}"
WC_VERSION="${2:-}"
case "$WP_VERSION" in
  7.1.5) WP_COMMIT=984163c92ef66b6186538ecf01bacb97fc097541 ;;
  7.0.9) WP_COMMIT=940b56303257196f968f1465ff20293967e348f2 ;;
  *) echo "Usage: sh tools/tests/wordpress.sh <7.0.9|7.1.5> <11.1.2|11.3.0>" >&2; exit 2 ;;
esac
case "$WC_VERSION" in
  11.3.0 | 11.1.2) ;;
  *) echo "Usage: sh tools/tests/wordpress.sh <7.0.9|7.1.5> <11.1.2|11.3.0>" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ENV="$ROOT/build/test-env/wp-$WP_VERSION-wc-$WC_VERSION"
mkdir -p "$ENV"

# unzip <archive> <directory>, with PHP's zip extension (no unzip binary needed).
extract() {
  php -r '$z = new ZipArchive(); if (true !== $z->open($argv[1]) || !$z->extractTo($argv[2])) { fwrite(STDERR, "Cannot extract $argv[1]\n"); exit(1); }' "$1" "$2"
}

if [ ! -f "$ENV/wordpress/wp-settings.php" ]; then
  archive="$(sh "$ROOT/tools/downloads.sh" wordpress "$WP_VERSION")"
  rm -rf "$ENV/wordpress"
  extract "$archive" "$ENV"
fi

if [ ! -f "$ENV/wordpress/wp-content/plugins/woocommerce/woocommerce.php" ]; then
  archive="$(sh "$ROOT/tools/downloads.sh" woocommerce "$WC_VERSION")"
  rm -rf "$ENV/wordpress/wp-content/plugins/woocommerce"
  extract "$archive" "$ENV/wordpress/wp-content/plugins"
fi

if [ ! -f "$ENV/tests/includes/bootstrap.php" ]; then
  checkout="$ENV/wordpress-develop"
  rm -rf "$checkout" "$ENV/tests"
  git init -q "$checkout"
  git -C "$checkout" sparse-checkout set --no-cone /tests/phpunit/includes/ /tests/phpunit/data/themedir1/
  git -C "$checkout" fetch -q --depth 1 --filter=blob:none https://github.com/WordPress/wordpress-develop.git "$WP_COMMIT"
  git -C "$checkout" -c advice.detachedHead=false checkout -q FETCH_HEAD
  if [ "$(git -C "$checkout" rev-parse HEAD)" != "$WP_COMMIT" ]; then
    echo "wordpress-develop: not at $WP_COMMIT" >&2
    exit 1
  fi
  mkdir -p "$ENV/tests/data/languages"
  mv "$checkout/tests/phpunit/includes" "$ENV/tests/includes"
  mv "$checkout/tests/phpunit/data/themedir1" "$ENV/tests/data/themedir1"
  rm -rf "$checkout"
fi

cat >"$ENV/wp-tests-config.php" <<PHP
<?php
// Written by tools/tests/wordpress.sh: WordPress $WP_VERSION, WooCommerce $WC_VERSION.
define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'wp' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'wp' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'sattleme-tests' );
define( 'SECURE_AUTH_KEY', 'sattleme-tests' );
define( 'LOGGED_IN_KEY', 'sattleme-tests' );
define( 'NONCE_KEY', 'sattleme-tests' );
define( 'AUTH_SALT', 'sattleme-tests' );
define( 'SECURE_AUTH_SALT', 'sattleme-tests' );
define( 'LOGGED_IN_SALT', 'sattleme-tests' );
define( 'NONCE_SALT', 'sattleme-tests' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Shop' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
PHP

echo "$ENV"
