#!/bin/sh
# WP-CLI, its release's phar checked against its published SHA-512, kept in build/tools/: prints
# its path (tools/i18n.sh and the end-to-end tests run it with PHP).
set -eu

VERSION=2.12.0
SHA512=be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832
URL="https://github.com/wp-cli/wp-cli/releases/download/v$VERSION/wp-cli-$VERSION.phar"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHAR="$ROOT/build/tools/wp-cli-$VERSION.phar"

if [ ! -f "$PHAR" ] || ! echo "$SHA512  $PHAR" | sha512sum -c --status -; then
  mkdir -p "$ROOT/build/tools"
  curl -fsSL --retry 3 --retry-all-errors -o "$PHAR.download" "$URL"
  if ! echo "$SHA512  $PHAR.download" | sha512sum -c --status -; then
    rm -f "$PHAR.download"
    echo "WP-CLI $VERSION: its SHA-512 is not $SHA512" >&2
    exit 1
  fi
  mv "$PHAR.download" "$PHAR"
fi
echo "$PHAR"
