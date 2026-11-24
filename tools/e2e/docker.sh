#!/usr/bin/env bash
# The end-to-end test on WordPress's official Docker image (Apache and PHP, as many hosts run
# WordPress), with a MariaDB container: the same shop, scenario and Plugin Check as run.sh, the
# plugin installed from its archive. The SDK is faked by the must-use plugin of mu-plugins/,
# through the plugin's testing filter, which only the testing constant of the site's
# wp-config.php opens (WORDPRESS_CONFIG_EXTRA). Needs Docker, as GitHub's hosted runners have it.
#
# Usage: bash tools/e2e/docker.sh <WordPress's image> <WooCommerce>
#   e.g. bash tools/e2e/docker.sh wordpress:7.1.5-php8.5-apache@sha256:… 11.3.0
#
# Needs the plugin's archive (bash tools/build.sh), Docker, and a PHP with curl on the host (PHP=…,
# else php) for the scenario. The site answers on 127.0.0.1:$E2E_PORT (default 8891).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
IMAGE="${1:?Usage: bash tools/e2e/docker.sh <WordPress image> <WooCommerce version>}"
WC_VERSION="${2:?Usage: bash tools/e2e/docker.sh <WordPress image> <WooCommerce version>}"
# MariaDB 11.4 (long-term support), pinned by its digest (E2E_MARIADB_IMAGE: the same image from a
# mirror of Docker Hub, by the same digest).
MARIADB_IMAGE="${E2E_MARIADB_IMAGE:-mariadb:11.4.13@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873}"
PHP="${PHP:-php}"
PORT="${E2E_PORT:-8891}"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/sattleme-for-woocommerce.php")"
ZIP="$ROOT/build/sattleme-for-woocommerce-$VERSION.zip"
# A well-formed integration key of no integration (the SDK is faked).
KEY='sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg.cmVsYXkuZXhhbXBsZQ'
NAME="sattleme-e2e-$$"
WEB="$NAME-wordpress"
DB="$NAME-mariadb"
LOGS="$ROOT/build/e2e/docker-$NAME"

if [ ! -f "$ZIP" ]; then
  echo "No archive: run bash tools/build.sh first" >&2
  exit 1
fi
for image in "$IMAGE" "$MARIADB_IMAGE"; do
  case "$image" in
    *@sha256:*) ;;
    *) echo "An image must be pinned by its digest: $image" >&2; exit 2 ;;
  esac
done

WOOCOMMERCE_ZIP="$(sh "$ROOT/tools/downloads.sh" woocommerce "$WC_VERSION")"
PLUGIN_CHECK_ZIP="$(sh "$ROOT/tools/downloads.sh" plugin-check 2.2.0)"
WPCLI="$(sh "$ROOT/tools/wp-cli.sh")"
mkdir -p "$LOGS"

cleanup() {
  docker logs "$WEB" >"$LOGS/apache.log" 2>&1 || true
  # With their volumes: both images keep their data (WordPress's files, MariaDB's tables) in an
  # anonymous volume, which would otherwise outlive the test.
  docker rm -f -v "$WEB" "$DB" >/dev/null 2>&1 || true
  docker network rm "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$NAME" >/dev/null
docker run -d --name "$DB" --network "$NAME" \
  -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
  "$MARIADB_IMAGE" >/dev/null
docker run -d --name "$WEB" --network "$NAME" -p "127.0.0.1:$PORT:80" \
  -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wordpress \
  -e WORDPRESS_DEBUG=1 \
  -e WORDPRESS_CONFIG_EXTRA="// The end-to-end test's fake of the SDK (mu-plugins/sattleme-e2e.php): never on a shop.
define( 'SATTLEME_FOR_WOOCOMMERCE_TESTING', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );" \
  "$IMAGE" >/dev/null

# The image copies WordPress into its document root and writes wp-config.php as it starts; the
# database takes a moment to accept connections.
ready=""
for _ in $(seq 1 180); do
  if docker exec "$WEB" php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(is_file("/var/www/html/wp-config.php") && @mysqli_connect(getenv("WORDPRESS_DB_HOST"), "wp", "wp", "wordpress") ? 0 : 1);' 2>/dev/null; then
    ready=yes
    break
  fi
  sleep 1
done
if [ -z "$ready" ]; then
  echo "WordPress or MariaDB did not start" >&2
  docker logs "$WEB" >&2 || true
  docker logs "$DB" >&2 || true
  exit 1
fi

docker cp "$WPCLI" "$WEB:/usr/local/bin/wp-cli.phar"
docker cp "$WOOCOMMERCE_ZIP" "$WEB:/tmp/woocommerce.zip"
docker cp "$ZIP" "$WEB:/tmp/sattleme-for-woocommerce.zip"
docker cp "$PLUGIN_CHECK_ZIP" "$WEB:/tmp/plugin-check.zip"
docker exec "$WEB" chmod 0644 /usr/local/bin/wp-cli.phar /tmp/woocommerce.zip /tmp/sattleme-for-woocommerce.zip /tmp/plugin-check.zip

# What PHP reports of WP-CLI itself goes to stderr: its output is the commands' alone.
export E2E_WP="docker exec -u www-data $WEB php -d display_errors=stderr /usr/local/bin/wp-cli.phar --path=/var/www/html --skip-packages"
export E2E_URL="http://127.0.0.1:$PORT"

echo "# $IMAGE, WooCommerce $WC_VERSION, $(docker exec "$WEB" php -r 'echo "PHP ", PHP_VERSION;'), $(docker exec "$DB" mariadb --version | cut -d' ' -f1-4), the plugin's archive $(sha256sum "$ZIP" | cut -c1-16)…"
PRODUCT="$(sh "$ROOT/tools/e2e/shop.sh" /tmp/woocommerce.zip /tmp/sattleme-for-woocommerce.zip | tail -n 1)"
case "$PRODUCT" in
  '' | *[!0-9]*) echo "No product made: $PRODUCT" >&2; exit 1 ;;
esac
docker exec -u www-data "$WEB" mkdir -p /var/www/html/wp-content/mu-plugins
docker cp "$ROOT/tools/e2e/mu-plugins/." "$WEB:/var/www/html/wp-content/mu-plugins/"
docker exec "$WEB" chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
# What WooCommerce's activation logged as it set up the shop is not the plugin's.
docker exec -u www-data "$WEB" sh -c ': > /var/www/html/wp-content/debug.log'

for _ in $(seq 1 60); do
  if curl -fs -o /dev/null "$E2E_URL/"; then
    break
  fi
  sleep 1
done

E2E_KEY="$KEY" E2E_PRODUCT="$PRODUCT" "$PHP" "$ROOT/tools/e2e/scenario.php"

echo "# Plugin Check 2.2.0"
sh "$ROOT/tools/e2e/plugin-check.sh" /tmp/plugin-check.zip

# The key is in no log: WordPress's, WooCommerce's, Apache's.
docker exec "$WEB" sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null' >"$LOGS/debug.log"
docker exec "$WEB" sh -c 'cat /var/www/html/wp-content/uploads/wc-logs/*.log 2>/dev/null || true' >"$LOGS/wc-logs.log"
docker logs "$WEB" >"$LOGS/apache.log" 2>&1
if grep -qs -- "17wserpSaW" "$LOGS/debug.log" "$LOGS/wc-logs.log" "$LOGS/apache.log"; then
  echo "not ok - the key is in a log" >&2
  exit 1
fi
echo "ok - the key is in no log"
# PHP's reports of the scenario (WP-CLI's own and WordPress's update checks may be there): none
# of the plugin's, nor any fatal error.
if [ -s "$LOGS/debug.log" ]; then
  echo "# debug.log:"
  cat "$LOGS/debug.log"
fi
if grep -qE 'sattleme|PHP Fatal error' "$LOGS/debug.log"; then
  echo "not ok - PHP reported an error of the plugin's, or a fatal error, during the scenario" >&2
  exit 1
fi
echo "ok - PHP reported nothing of the plugin's during the scenario"
