#!/usr/bin/env bash
# Builds the plugin's archive, as WordPress installs it: build/sattleme-for-woocommerce-<version>.zip,
# one folder sattleme-for-woocommerce/ holding
#
# - the plugin's own files, as git tracks them, from an allowlist (the main file, uninstall.php,
#   readme.txt, LICENSE, wpml-config.xml, src/, templates/, assets/, languages/), and its
#   composer.json and composer.lock, which say where its dependencies come from (WordPress.org's
#   reviewers ask for them);
# - its dependencies (the SDK and the QR code library), installed from composer.lock without the
#   development ones, then prefixed by Strauss (vendor-prefixed/): their code and their licences,
#   without their manifests and readmes, nor Composer's records of the machine that built them.
#
# Nothing else: no test, tool, configuration or pinned copy (tools/package.php checks it). The
# archive is reproducible: the same files, in order, dated with the last commit, with fixed
# permissions. Prints the archive's path and SHA-256.
#
# Usage: bash tools/build.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/sattleme-for-woocommerce.php")"
STAGE="$ROOT/build/package"
PLUGIN="$STAGE/sattleme-for-woocommerce"
DEPS="$STAGE/deps"
ZIP="$ROOT/build/sattleme-for-woocommerce-$VERSION.zip"
COMPOSER="${COMPOSER_BIN:-composer}"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$PLUGIN" "$DEPS"

# copy <destination> <paths…>: the files git tracks under those paths, as they are now.
copy() {
  destination="$1"
  shift
  git -C "$ROOT" ls-files -z -- "$@" | while IFS= read -r -d '' file; do
    mkdir -p "$destination/$(dirname "$file")"
    cp "$ROOT/$file" "$destination/$file"
  done
}

copy "$PLUGIN" sattleme-for-woocommerce.php uninstall.php readme.txt LICENSE wpml-config.xml composer.json composer.lock src templates assets languages
copy "$DEPS" composer.json composer.lock sdk tools/strauss.sh

# The dependencies, as composer.lock pins them, prefixed.
(cd "$DEPS" && "$COMPOSER" install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --quiet)
mkdir -p "$DEPS/build"
if [ -d "$ROOT/build/tools" ]; then
  cp -R "$ROOT/build/tools" "$DEPS/build/"
fi
sh "$DEPS/tools/strauss.sh" "$DEPS" >/dev/null
rm -f "$DEPS/vendor-prefixed/composer/installed.json" "$DEPS/vendor-prefixed/composer/installed.php"
find "$DEPS/vendor-prefixed" -mindepth 3 -maxdepth 3 -type f \( -name composer.json -o -name README.md \) -delete
mv "$DEPS/vendor-prefixed" "$PLUGIN/vendor-prefixed"
rm -rf "$DEPS"

# Dated with the last commit (SOURCE_DATE_EPOCH when set).
EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$ROOT" log -1 --format=%ct)}"
php "$ROOT/tools/package.php" zip "$STAGE" "$ZIP" "$EPOCH"
php "$ROOT/tools/package.php" check "$ZIP" "$VERSION"
echo "$ZIP"
sha256sum "$ZIP" | cut -d ' ' -f 1
