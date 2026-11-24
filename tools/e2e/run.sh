#!/usr/bin/env bash
# The end-to-end test on a real WordPress and WooCommerce, without Docker nor root (PHP's built-in
# web server): the plugin's archive installed as a merchant installs it, a customer's order
# placed through the Checkout block's API and through the classic checkout, the payment page
# shown in the default (block) theme, the order paid; then WordPress.org's Plugin
# Check. The SDK is faked by a must-use plugin (mu-plugins/), through the plugin's testing filter,
# which only the testing constant of the site's wp-config.php opens. tools/e2e/docker.sh runs the
# same on WordPress's official Docker image.
#
# Usage: bash tools/e2e/run.sh [<WordPress> <WooCommerce>]   (default: 7.1.5 11.3.0)
#
# Needs the plugin's archive (bash tools/build.sh) and a PHP with mysqli, curl and zip (PHP=…,
# else php). The database is tools/tests/mariadb.sh's; WordPress, WooCommerce, WP-CLI and Plugin
# Check are pinned and checked (tools/downloads.sh, tools/wp-cli.sh). The site is in build/e2e/,
# its server PHP's built-in one on 127.0.0.1:$E2E_PORT (default 8890).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP_VERSION="${1:-7.1.5}"
WC_VERSION="${2:-11.3.0}"
PHP="${PHP:-php}"
PORT="${E2E_PORT:-8890}"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/sattleme-for-woocommerce.php")"
ZIP="$ROOT/build/sattleme-for-woocommerce-$VERSION.zip"
# A well-formed integration key of no integration (the SDK is faked).
KEY='sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg.cmVsYXkuZXhhbXBsZQ'

if [ ! -f "$ZIP" ]; then
  echo "No archive: run bash tools/build.sh first" >&2
  exit 1
fi

WORDPRESS_ZIP="$(sh "$ROOT/tools/downloads.sh" wordpress "$WP_VERSION")"
WOOCOMMERCE_ZIP="$(sh "$ROOT/tools/downloads.sh" woocommerce "$WC_VERSION")"
PLUGIN_CHECK_ZIP="$(sh "$ROOT/tools/downloads.sh" plugin-check 2.2.0)"
WPCLI="$(sh "$ROOT/tools/wp-cli.sh")"
SOCKET="$(sh "$ROOT/tools/tests/mariadb.sh" start)"
SITE="$ROOT/build/e2e/wp-$WP_VERSION-wc-$WC_VERSION"
# What PHP reports of WP-CLI itself goes to stderr: its output is the commands' alone.
export E2E_WP="$PHP -d display_errors=stderr $WPCLI --path=$SITE/wordpress --allow-root --skip-packages"
export E2E_URL="http://127.0.0.1:$PORT"

wp() {
  # shellcheck disable=SC2086
  $E2E_WP "$@"
}

rm -rf "$SITE"
mkdir -p "$SITE"
"$PHP" -r '$z = new ZipArchive(); if (true !== $z->open($argv[1]) || !$z->extractTo($argv[2])) { exit(1); }' "$WORDPRESS_ZIP" "$SITE"

echo "# WordPress $WP_VERSION, WooCommerce $WC_VERSION, $("$PHP" -r 'echo "PHP ", PHP_VERSION;'), the plugin's archive $(sha256sum "$ZIP" | cut -c1-16)…"
wp config create --dbname=wordpress_e2e --dbuser=wp --dbpass=wp --dbhost="localhost:$SOCKET" --skip-check --quiet --extra-php <<'PHP'
// The end-to-end test's fake of the SDK (mu-plugins/sattleme-e2e.php): never on a shop.
define( 'SATTLEME_FOR_WOOCOMMERCE_TESTING', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
PHP
sh "$ROOT/tools/tests/mariadb.sh" reset wordpress_e2e
PRODUCT="$(sh "$ROOT/tools/e2e/shop.sh" "$WOOCOMMERCE_ZIP" "$ZIP" | tail -n 1)"
case "$PRODUCT" in
  '' | *[!0-9]*) echo "No product made: $PRODUCT" >&2; exit 1 ;;
esac
mkdir -p "$SITE/wordpress/wp-content/mu-plugins"
cp -R "$ROOT/tools/e2e/mu-plugins/." "$SITE/wordpress/wp-content/mu-plugins/"
# What WooCommerce's activation logged as it set up the shop is not the plugin's.
: >"$SITE/wordpress/wp-content/debug.log"

if curl -s -o /dev/null --max-time 5 "$E2E_URL/"; then
  echo "Something answers on $E2E_URL already: set E2E_PORT to a free port" >&2
  exit 1
fi
# PHP's built-in server and its workers, in a process group of their own: stopped together.
PHP_CLI_SERVER_WORKERS=4 setsid "$PHP" -S "127.0.0.1:$PORT" -t "$SITE/wordpress" "$ROOT/tools/e2e/router.php" >"$SITE/server.log" 2>&1 &
SERVER=$!
trap 'kill -TERM -- "-$SERVER" 2>/dev/null || true' EXIT
for _ in $(seq 1 100); do
  if curl -fs -o /dev/null "$E2E_URL/"; then
    break
  fi
  sleep 0.5
done

E2E_KEY="$KEY" E2E_PRODUCT="$PRODUCT" "$PHP" "$ROOT/tools/e2e/scenario.php"

echo "# Plugin Check 2.2.0"
sh "$ROOT/tools/e2e/plugin-check.sh" "$PLUGIN_CHECK_ZIP"

# The key is in no log.
if grep -rqs -- "17wserpSaW" "$SITE/wordpress/wp-content/debug.log" "$SITE/wordpress/wp-content/uploads/wc-logs" "$SITE/server.log"; then
  echo "not ok - the key is in a log" >&2
  exit 1
fi
echo "ok - the key is in no log"
# PHP's reports of the scenario (WP-CLI's own and WordPress's update checks may be there): none
# of the plugin's, nor any fatal error.
if [ -s "$SITE/wordpress/wp-content/debug.log" ]; then
  echo "# debug.log:"
  cat "$SITE/wordpress/wp-content/debug.log"
fi
if grep -qE 'sattleme|PHP Fatal error' "$SITE/wordpress/wp-content/debug.log"; then
  echo "not ok - PHP reported an error of the plugin's, or a fatal error, during the scenario" >&2
  exit 1
fi
echo "ok - PHP reported nothing of the plugin's during the scenario"
