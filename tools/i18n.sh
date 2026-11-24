#!/bin/sh
# The plugin's translations, from its sources (WP-CLI's i18n commands; no gettext needed):
#
#   languages/sattleme-for-woocommerce.pot   the strings of the PHP files, the plugin's headers
#                                            but its author's name (a name: never translated);
#   languages/sattleme-for-woocommerce-<locale>.po   brought up to date with the POT;
#   .mo and .l10n.php                        compiled from each .po (WordPress 6.5 and later
#                                            read the PHP files first).
#
# Usage: sh tools/i18n.sh          Regenerates all of them.
#        sh tools/i18n.sh check    Fails when the POT is not the sources' (CI).
#
# WP-CLI: tools/wp-cli.sh.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHAR="$(sh "$ROOT/tools/wp-cli.sh")"
DOMAIN=sattleme-for-woocommerce
POT="$ROOT/languages/$DOMAIN.pot"

wp() {
  php "$PHAR" --allow-root --skip-packages "$@"
}

# make_pot <file>: the POT of the sources, without its creation date and the author's name.
make_pot() {
  wp i18n make-pot "$ROOT" "$1" --slug="$DOMAIN" --domain="$DOMAIN" \
    --exclude=build,languages,sdk,tests,tools,vendor,vendor-prefixed \
    --file-comment="Translations of sattle.me for WooCommerce. This file is distributed under the same license as the plugin (MIT)." \
    --headers='{"Report-Msgid-Bugs-To":"https://github.com/sattleme/checkout-woocommerce/issues","Language-Team":"sattle.me <https://sattle.me>","POT-Creation-Date":""}' \
    --no-location --skip-js --skip-audit >/dev/null
  php -r '
    $pot = (string) file_get_contents($argv[1]);
    // The author header (a name, never translated) is no string to translate.
    $pot = (string) preg_replace("/#\\. Author of the plugin\\n(#: [^\\n]*\\n)*msgid \"[^\"]*\"\\nmsgstr \"\"\\n\\n/", "", $pot);
    file_put_contents($argv[1], $pot);
  ' "$1"
}

if [ "${1:-}" = "check" ]; then
  fresh="$(mktemp)"
  make_pot "$fresh"
  if ! diff -u "$POT" "$fresh"; then
    rm -f "$fresh"
    echo "languages/$DOMAIN.pot is not the sources': run sh tools/i18n.sh" >&2
    exit 1
  fi
  rm -f "$fresh"
  echo "languages/$DOMAIN.pot is the sources'"
  exit 0
fi

make_pot "$POT"
wp i18n update-po "$POT" "$ROOT/languages/" >/dev/null
rm -f "$ROOT/languages/"*.mo "$ROOT/languages/"*.l10n.php
wp i18n make-mo "$ROOT/languages/" >/dev/null
wp i18n make-php "$ROOT/languages/" >/dev/null
ls "$ROOT/languages/"
