#!/bin/sh
# The pinned archives the tests install, each downloaded once into build/downloads/ and checked
# against its SHA-256 every time: WordPress and WooCommerce (WordPress.org publishes their SHA-1
# and its plugins' file checksums: the SHA-256 here were taken from archives checked against
# those), and WordPress.org's Plugin Check. Prints the archive's path.
#
# Usage: sh tools/downloads.sh wordpress <7.0.9|7.1.5>
#        sh tools/downloads.sh woocommerce <11.1.2|11.3.0>
#        sh tools/downloads.sh plugin-check <2.2.0>
set -eu

NAME="${1:-}"
VERSION="${2:-}"
case "$NAME $VERSION" in
  "wordpress 7.1.5") SHA256=cd44160a3fcce179fe170331132fcfc05e84e2f9284e2aad38df2aea6edaeab1 ;;
  "wordpress 7.0.9") SHA256=5a46f3c0443a321afa1f511ec3007fc636fdbd2e7598871020351edeffa1e1a0 ;;
  "woocommerce 11.3.0") SHA256=d9eb1e46df8309eb37b6f7f3fba51480f2a9a00e7feb10b1487592fde39accc6 ;;
  "woocommerce 11.1.2") SHA256=9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e ;;
  "plugin-check 2.2.0") SHA256=c9d26a644c4be2f7eb6223e837b2b38a9780a7873e7db30f49507412d541243b ;;
  *) echo "Usage: sh tools/downloads.sh wordpress <7.0.9|7.1.5> | woocommerce <11.1.2|11.3.0> | plugin-check <2.2.0>" >&2; exit 2 ;;
esac
case "$NAME" in
  wordpress) URL="https://wordpress.org/wordpress-$VERSION.zip"; FILE="wordpress-$VERSION.zip" ;;
  *) URL="https://downloads.wordpress.org/plugin/$NAME.$VERSION.zip"; FILE="$NAME.$VERSION.zip" ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOWNLOADS="$ROOT/build/downloads"
ARCHIVE="$DOWNLOADS/$FILE"
mkdir -p "$DOWNLOADS"

if [ ! -f "$ARCHIVE" ] || ! echo "$SHA256  $ARCHIVE" | sha256sum -c --status -; then
  curl -fsSL --retry 3 --retry-all-errors -o "$ARCHIVE.download" "$URL"
  if ! echo "$SHA256  $ARCHIVE.download" | sha256sum -c --status -; then
    rm -f "$ARCHIVE.download"
    echo "$URL: its SHA-256 is not $SHA256" >&2
    exit 1
  fi
  mv "$ARCHIVE.download" "$ARCHIVE"
fi
echo "$ARCHIVE"
