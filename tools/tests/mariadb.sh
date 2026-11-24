#!/bin/sh
# A MariaDB server for the integration and end-to-end tests, run by the current user: no root, no
# Docker, no package manager (the same server on any Linux, a runner's or a developer's). MariaDB
# 11.4.14 (long-term support), its official binary archive from archive.mariadb.org, checked
# against its SHA-256 (the one mariadb.org publishes). Only its server, its client and their
# data files are extracted. The server listens on a Unix socket only (no TCP port to collide with),
# its data in its directory.
#
# Usage:
#   sh tools/tests/mariadb.sh start   Starts it (once), with the databases "wordpress_test" (the
#                                     integration tests) and "wordpress_e2e" (the end-to-end test)
#                                     and a user "wp" (password "wp"), and prints its socket.
#   sh tools/tests/mariadb.sh reset <database>   Empties a database of it.
#   sh tools/tests/mariadb.sh stop    Stops it.
#
# The directory is $SATTLEME_MARIADB_DIR, else $TMPDIR/sattleme-mariadb (in CI, the runner's own
# temporary directory), else build/mariadb. The archive is kept in build/downloads.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ -n "${SATTLEME_MARIADB_DIR:-}" ]; then
  DIR="$SATTLEME_MARIADB_DIR"
elif [ -n "${TMPDIR:-}" ]; then
  DIR="$TMPDIR/sattleme-mariadb"
else
  DIR="$ROOT/build/mariadb"
fi
VERSION=11.4.14
URL="https://archive.mariadb.org/mariadb-$VERSION/bintar-linux-systemd-x86_64/mariadb-$VERSION-linux-systemd-x86_64.tar.gz"
SHA256=6fa13ea11d07a53ae4795c8c3736dc5aa99c2ef22ece754cff07363dc1faba0d
ARCHIVE="$ROOT/build/downloads/mariadb-$VERSION.tar.gz"
BASE="$DIR/mariadb-$VERSION"
# A Unix socket's path has 107 bytes at most: a deep directory's socket goes to /tmp.
SOCKET="$DIR/mysqld.sock"
if [ "${#SOCKET}" -gt 100 ]; then
  SOCKET="/tmp/sattleme-mariadb-$(printf '%s' "$DIR" | cksum | cut -d ' ' -f 1).sock"
fi

install_server() {
  if [ -x "$BASE/bin/mariadbd" ]; then
    return
  fi
  if [ ! -f "$ARCHIVE" ] || ! echo "$SHA256  $ARCHIVE" | sha256sum -c --status -; then
    mkdir -p "$(dirname "$ARCHIVE")"
    curl -fsSL --retry 3 --retry-all-errors -o "$ARCHIVE.download" "$URL"
    if ! echo "$SHA256  $ARCHIVE.download" | sha256sum -c --status -; then
      rm -f "$ARCHIVE.download"
      echo "MariaDB $VERSION: its SHA-256 is not $SHA256" >&2
      exit 1
    fi
    mv "$ARCHIVE.download" "$ARCHIVE"
  fi
  rm -rf "$BASE.part"
  mkdir -p "$BASE.part"
  # The server, its client, its installer and their data files: not its tests nor its libraries.
  tar -xzf "$ARCHIVE" -C "$BASE.part" --strip-components=1 --wildcards \
    '*/bin/mariadbd' '*/bin/mariadb' '*/bin/my_print_defaults' '*/scripts/*' '*/share/*'
  rm -rf "$BASE"
  mv "$BASE.part" "$BASE"
}

client() {
  "$BASE/bin/mariadb" --no-defaults --socket="$SOCKET" --user=root "$@"
}

databases() {
  client -e "CREATE DATABASE IF NOT EXISTS wordpress_test; CREATE DATABASE IF NOT EXISTS wordpress_e2e; CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp'; GRANT ALL ON wordpress_test.* TO 'wp'@'localhost'; GRANT ALL ON wordpress_e2e.* TO 'wp'@'localhost';"
}

start() {
  install_server
  if [ -S "$SOCKET" ] && client -e 'SELECT 1' >/dev/null 2>&1; then
    databases
    echo "$SOCKET"
    return
  fi
  user_option=""
  if [ "$(id -u)" = "0" ]; then
    user_option="--user=root"
  fi
  if [ ! -d "$DIR/data/mysql" ]; then
    mkdir -p "$DIR/data"
    # shellcheck disable=SC2086
    "$BASE/scripts/mariadb-install-db" --no-defaults --basedir="$BASE" --datadir="$DIR/data" \
      --auth-root-authentication-method=normal --skip-test-db --skip-name-resolve --force $user_option \
      >"$DIR/install.log" 2>&1 || { cat "$DIR/install.log" >&2; exit 1; }
  fi
  # shellcheck disable=SC2086
  "$BASE/bin/mariadbd" --no-defaults --basedir="$BASE" --datadir="$DIR/data" \
    --socket="$SOCKET" --pid-file="$DIR/mysqld.pid" --skip-networking --skip-name-resolve \
    --log-error="$DIR/error.log" --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
    --innodb-buffer-pool-size=128M --skip-log-bin --max-connections=200 $user_option \
    </dev/null >/dev/null 2>&1 &
  tries=0
  until client -e 'SELECT 1' >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -gt 120 ]; then
      echo "MariaDB did not start:" >&2
      cat "$DIR/error.log" >&2 || true
      exit 1
    fi
    sleep 0.5
  done
  databases
  echo "$SOCKET"
}

stop() {
  if [ -S "$SOCKET" ]; then
    client -e 'SHUTDOWN' || true
  fi
}

reset() {
  case "$1" in
    wordpress_test | wordpress_e2e) ;;
    *) echo "Not a database of the tests: $1" >&2; exit 2 ;;
  esac
  client -e "DROP DATABASE IF EXISTS $1; CREATE DATABASE $1;"
}

case "${1:-}" in
  start) start ;;
  reset) reset "${2:-}" ;;
  stop) stop ;;
  *) echo "Usage: sh tools/tests/mariadb.sh start | reset <database> | stop" >&2; exit 2 ;;
esac
