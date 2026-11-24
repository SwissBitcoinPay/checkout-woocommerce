#!/bin/sh
# WordPress.org's Plugin Check, on the plugin as the end-to-end test's site installed it: fails
# on any error it reports (its warnings are printed).
#
# Usage: E2E_WP='<command running WP-CLI on the site>' sh tools/e2e/plugin-check.sh <Plugin Check's ZIP>
#   (the ZIP's path as that command sees it; tools/downloads.sh plugin-check fetches it).
set -eu

wp() {
  # shellcheck disable=SC2086
  $E2E_WP "$@"
}

wp plugin install "$1" --activate --quiet
status=0
report="$(wp plugin check sattleme-for-woocommerce --format=csv --fields=file,line,type,code,message)" || status=$?
echo "$report"
if [ "$status" -ne 0 ] || echo "$report" | grep -q ',ERROR,'; then
  echo "not ok - Plugin Check found errors (exit $status)" >&2
  exit 1
fi
echo "ok - Plugin Check: no error"
