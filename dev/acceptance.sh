#!/usr/bin/env bash
# Acceptance check in a fresh Laravel application.
#
#   dev/acceptance.sh <laravel major: 12|13> <database: sqlite|mysql|pgsql>
#
# Creates a new app with `composer create-project laravel/laravel`, installs
# this package the way Packagist will ship it (a `git archive` of
# LEDGER_DIRECT_ACCEPTANCE_REF, default HEAD, as version
# LEDGER_DIRECT_ACCEPTANCE_VERSION, default 0.2.0), then walks
# the README: vendor:publish, migrate, a funded testnet account in .env,
# `about`, `ledger-direct:price`, `ledger-direct:sync`, two quotes, a status
# answer — first on the XRP Ledger, then with Stellar enabled on a
# Friendbot-funded account — and migrate:rollback keeping both counters.
#
# Needs network (Packagist, the XRPL testnet faucet and node, the price
# oracles), PHP with pdo_sqlite/pdo_mysql/pdo_pgsql, Composer, curl and jq.
# MySQL and PostgreSQL default to the containers of `docker compose up -d
# mysql postgres` (127.0.0.1:33306 and :35432, password root); override with
# LEDGER_DIRECT_TEST_{MYSQL,PGSQL}_{HOST,PORT,USERNAME,PASSWORD}.
#
# The app is created under LEDGER_DIRECT_ACCEPTANCE_DIR (default: a new
# temporary directory) and left there for inspection.
set -euo pipefail

laravel=${1:?Laravel major version, 12 or 13}
database=${2:?Database: sqlite, mysql or pgsql}
case "$laravel" in 12|13) ;; *) echo "Laravel 12 or 13, not '$laravel'." >&2; exit 2 ;; esac
case "$database" in sqlite|mysql|pgsql) ;; *) echo "sqlite, mysql or pgsql, not '$database'." >&2; exit 2 ;; esac

repo=$(cd "$(dirname "$0")/.." && pwd)
ref=${LEDGER_DIRECT_ACCEPTANCE_REF:-HEAD}
version=${LEDGER_DIRECT_ACCEPTANCE_VERSION:-0.2.0}
work=${LEDGER_DIRECT_ACCEPTANCE_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/ledger-direct-acceptance.XXXXXX")}
app="$work/laravel-$laravel-$database"
package="$work/package"
db_name="ld_acceptance_${laravel}_${database}"

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "FAILED: $*" >&2; exit 1; }

# Sets KEY=VALUE in the app's .env, replacing a line that is there,
# commented out or not.
set_env() {
  php -r '
    [$file, $key, $value] = array_slice($argv, 1);
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match("/^#?\s*" . preg_quote($key, "/") . "=/", $line)) {
            $lines[$i] = $found ? null : "{$key}={$value}";
            $found = true;
        }
    }
    if (!$found) { $lines[] = "{$key}={$value}"; }
    file_put_contents($file, implode("\n", array_filter($lines, fn ($l) => $l !== null)) . "\n");
  ' "$app/.env" "$1" "$2"
}

step "Package: git archive of $ref → $package"
rm -rf "$package" && mkdir -p "$package"
git -C "$repo" archive "$ref" | tar -x -C "$package"
[ ! -e "$package/tests" ] || fail "tests/ must not be in the archive"

step "Laravel $laravel skeleton → $app"
rm -rf "$app"
composer create-project "laravel/laravel:^$laravel.0" "$app" --prefer-dist --no-interaction --quiet
php "$app/artisan" --version

step "Database: $database"
case "$database" in
  sqlite)
    set_env DB_CONNECTION sqlite
    ;;
  mysql)
    host=${LEDGER_DIRECT_TEST_MYSQL_HOST:-127.0.0.1}; port=${LEDGER_DIRECT_TEST_MYSQL_PORT:-33306}
    user=${LEDGER_DIRECT_TEST_MYSQL_USERNAME:-root}; pass=${LEDGER_DIRECT_TEST_MYSQL_PASSWORD:-root}
    php -r '$p = new PDO("mysql:host=$argv[1];port=$argv[2]", $argv[3], $argv[4]); $p->exec("DROP DATABASE IF EXISTS `$argv[5]`"); $p->exec("CREATE DATABASE `$argv[5]`");' "$host" "$port" "$user" "$pass" "$db_name"
    set_env DB_CONNECTION mysql; set_env DB_HOST "$host"; set_env DB_PORT "$port"
    set_env DB_DATABASE "$db_name"; set_env DB_USERNAME "$user"; set_env DB_PASSWORD "$pass"
    ;;
  pgsql)
    host=${LEDGER_DIRECT_TEST_PGSQL_HOST:-127.0.0.1}; port=${LEDGER_DIRECT_TEST_PGSQL_PORT:-35432}
    user=${LEDGER_DIRECT_TEST_PGSQL_USERNAME:-postgres}; pass=${LEDGER_DIRECT_TEST_PGSQL_PASSWORD:-root}
    php -r '$p = new PDO("pgsql:host=$argv[1];port=$argv[2];dbname=postgres", $argv[3], $argv[4]); $p->exec("DROP DATABASE IF EXISTS \"$argv[5]\""); $p->exec("CREATE DATABASE \"$argv[5]\"");' "$host" "$port" "$user" "$pass" "$db_name"
    set_env DB_CONNECTION pgsql; set_env DB_HOST "$host"; set_env DB_PORT "$port"
    set_env DB_DATABASE "$db_name"; set_env DB_USERNAME "$user"; set_env DB_PASSWORD "$pass"
    ;;
esac
php "$app/artisan" migrate --force --no-interaction

step "composer require hardcastle/ledger-direct-laravel:$version (from the archive)"
composer --working-dir="$app" config repositories.ledger-direct-laravel \
  "{\"type\":\"path\",\"url\":\"$package\",\"options\":{\"symlink\":false,\"versions\":{\"hardcastle/ledger-direct-laravel\":\"$version\"}}}"
composer --working-dir="$app" require "hardcastle/ledger-direct-laravel:$version" --no-interaction --no-progress -W
composer --working-dir="$app" show hardcastle/ledger-direct-core | grep -E '^versions'

step "vendor:publish"
php "$app/artisan" vendor:publish --tag=ledger-direct-config --no-interaction
php "$app/artisan" vendor:publish --tag=ledger-direct-migrations --no-interaction
[ -f "$app/config/ledger-direct.php" ] || fail "config/ledger-direct.php was not published"
ls "$app"/database/migrations/*_create_ledger_direct_xrpl_tables.php >/dev/null || fail "the migration was not published"

step "migrate"
php "$app/artisan" migrate --force --no-interaction
cp "$repo/dev/acceptance/checks.php" "$app/acceptance-checks.php"
php "$app/acceptance-checks.php" tables

step "A funded testnet account in .env"
account=$("$repo/dev/fund-testnet-account.sh")
set_env LEDGER_DIRECT_XRPL_NETWORK testnet
set_env LEDGER_DIRECT_XRPL_ACCOUNT "$account"
echo "$account"

step "php artisan about --only=ledgerdirect"
about=$(php "$app/artisan" about --only=ledgerdirect)
echo "$about"
grep -q "${account:0:6}" <<<"$about" || fail "about does not show the account"
grep -q testnet <<<"$about" || fail "about does not show the network"

step "php artisan ledger-direct:price XRP EUR"
php "$app/artisan" ledger-direct:price XRP EUR | tee "$work/price.txt"
grep -q 'XRP/EUR' "$work/price.txt" || fail "no XRP/EUR rate"

step "php artisan ledger-direct:sync (twice)"
php "$app/artisan" ledger-direct:sync
php "$app/artisan" ledger-direct:sync
php "$app/acceptance-checks.php" synced

step "Two quotes and a status answer"
php "$app/acceptance-checks.php" quotes

step "Stellar: a Friendbot-funded account in .env"
stellar_account=$("$repo/dev/fund-stellar-testnet-account.sh")
set_env LEDGER_DIRECT_STELLAR_ENABLED true
set_env LEDGER_DIRECT_STELLAR_NETWORK testnet
set_env LEDGER_DIRECT_STELLAR_ACCOUNT "$stellar_account"
echo "$stellar_account"

step "Stellar: about, price XLM EUR, sync"
about=$(php "$app/artisan" about --only=ledgerdirect)
echo "$about"
grep -q "${stellar_account:0:6}" <<<"$about" || fail "about does not show the Stellar account"
php "$app/artisan" ledger-direct:price XLM EUR | tee "$work/price-xlm.txt"
grep -q 'XLM/EUR' "$work/price-xlm.txt" || fail "no XLM/EUR rate"
php "$app/artisan" ledger-direct:sync --chain=stellar
php "$app/artisan" ledger-direct:sync

step "Stellar: payment options, two quotes and a status answer"
php "$app/acceptance-checks.php" stellar

step "migrate:rollback keeps the counter, migrate rebuilds the rest"
php "$app/artisan" migrate:rollback --force --no-interaction
php "$app/acceptance-checks.php" after-rollback
php "$app/artisan" migrate --force --no-interaction
php "$app/acceptance-checks.php" after-remigrate

step "Accepted: Laravel $laravel on $database ($(php "$app/artisan" --version))"
