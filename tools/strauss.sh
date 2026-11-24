#!/bin/sh
# Prefixes the plugin's dependencies with Strauss (the sattle.me SDK, the QR code library and its
# own dependency): vendor-prefixed/, namespace Sattleme\WooCommerce\Vendor\, as composer.json's
# extra.strauss says. Composer runs it after each install (post-install-cmd).
#
# Strauss is its release's PHAR, checked against its SHA-256, kept in build/tools/.
#
# Usage: sh tools/strauss.sh [<project directory>]   (default: the repository's root)
set -eu

VERSION=0.31.1
SHA256=17dc2baf27b9a3f52834e3db729c8365d8915a80e37a087d253cf705387aa887
URL="https://github.com/BrianHenryIE/strauss/releases/download/$VERSION/strauss.phar"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROJECT="${1:-$ROOT}"
PHAR="$ROOT/build/tools/strauss-$VERSION.phar"

sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d ' ' -f 1
  else
    shasum -a 256 "$1" | cut -d ' ' -f 1
  fi
}

if [ ! -f "$PHAR" ] || [ "$(sha256 "$PHAR")" != "$SHA256" ]; then
  mkdir -p "$ROOT/build/tools"
  curl -fsSL --retry 3 --retry-all-errors -o "$PHAR.download" "$URL"
  if [ "$(sha256 "$PHAR.download")" != "$SHA256" ]; then
    rm -f "$PHAR.download"
    echo "Strauss $VERSION: its SHA-256 is not $SHA256" >&2
    exit 1
  fi
  mv "$PHAR.download" "$PHAR"
fi

cd "$PROJECT"
rm -rf vendor-prefixed
php "$PHAR"

# Strauss names its autoloader's classes after a random suffix: named after the locked
# dependencies instead, the same for every build of them (the archive is reproducible).
php -r '
  $lock = json_decode((string) file_get_contents("composer.lock"), true);
  $suffix = md5("sattleme-for-woocommerce:" . $lock["content-hash"]);
  foreach (array("autoload.php", "composer/autoload_real.php", "composer/autoload_static.php") as $file) {
    $path = "vendor-prefixed/" . $file;
    $code = (string) file_get_contents($path);
    $code = (string) preg_replace("/(Composer(?:AutoloaderInit|StaticInit))[0-9a-f]{32}/", "\${1}" . $suffix, $code, -1, $count);
    if (0 === $count) {
      fwrite(STDERR, "No autoloader suffix in " . $path . "\n");
      exit(1);
    }
    file_put_contents($path, $code);
  }
'
