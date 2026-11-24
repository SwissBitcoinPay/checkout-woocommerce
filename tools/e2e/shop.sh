#!/bin/sh
# The end-to-end test's shop, on a WordPress whose wp-config.php is written (run.sh, docker.sh):
# installed, WooCommerce and the plugin installed from their ZIPs and activated as a merchant
# does it, a shop in Switzerland in CHF with a product in stock, a page with the classic
# checkout. Prints the product's id, on its last line (what PHP reports may come before).
#
# Usage: E2E_WP='<command running WP-CLI on the site>' E2E_URL=<the site's URL> \
#          sh tools/e2e/shop.sh <WooCommerce's ZIP> <the plugin's ZIP>
#   (each ZIP's path as that command sees it).
set -eu

WOOCOMMERCE="$1"
PLUGIN="$2"

wp() {
  # shellcheck disable=SC2086
  $E2E_WP "$@"
}

# Steps on stderr: stdout is the product's id alone.
step() {
  echo "# $*" >&2
}

step "Installing WordPress"
wp core install --url="$E2E_URL" --title='E2E Shop' --admin_user=admin --admin_password=e2e-admin-password \
  --admin_email=admin@example.com --skip-email --quiet
wp rewrite structure '/%postname%/' --quiet
step "Installing WooCommerce from its ZIP, activating it"
wp plugin install "$WOOCOMMERCE" --activate --quiet
step "Installing the plugin from its ZIP, activating it"
wp plugin install "$PLUGIN" --activate --quiet
step "Setting up the shop"

# WooCommerce's setup wizard skipped (its first admin page would redirect there), the shop open.
wp transient delete _wc_activation_redirect --quiet
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json --quiet
wp option update woocommerce_coming_soon no --quiet
wp option update woocommerce_currency CHF --quiet
wp option update woocommerce_default_country CH:ZH --quiet
wp option update woocommerce_store_address 'Bahnhofstrasse 1' --quiet
wp option update woocommerce_store_city Zurich --quiet
wp option update woocommerce_store_postcode 8001 --quiet
wp wc tool run install_pages --user=admin --quiet
wp post create --post_type=page --post_status=publish --post_title='Classic checkout' \
  --post_name=classic-checkout --post_content='[woocommerce_checkout]' --quiet
wp wc product create --name='Hodl mug' --regular_price=24.50 --manage_stock=true --stock_quantity=10 \
  --user=admin --porcelain
