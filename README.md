# ledger-direct-laravel

LedgerDirect for Laravel: binds [`hardcastle/ledger-direct-core`](https://github.com/ledger-direct/ledger-direct-core-php)
— XRP, RLUSD and USDC payments on the XRP Ledger — into any Laravel 12 or 13 application.

A thin bridge, and deliberately nothing more: a service provider, a facade, config, a migration,
the storage the core needs, Artisan commands and the building blocks of a payment-status
endpoint. It knows no orders. Prices, conversion, destination tags, syncing, matching and the
"is this paid?" decision all come from the core, so every LedgerDirect platform answers them the
same way. A shop integration (Bagisto first) sits on top of this package.

| | |
|---|---|
| Composer | `hardcastle/ledger-direct-laravel` |
| Namespace | `Hardcastle\LedgerDirect\Laravel\` |
| Requires | PHP 8.2+ · Laravel 12 (PHP 8.2–8.4) or Laravel 13 (PHP 8.3+) · core `^0.10` |
| License | MIT |

## Installation

```bash
composer require hardcastle/ledger-direct-laravel
php artisan vendor:publish --tag=ledger-direct-config      # optional
php artisan vendor:publish --tag=ledger-direct-migrations
php artisan migrate
```

Then in `.env`:

```dotenv
LEDGER_DIRECT_XRPL_NETWORK=testnet             # mainnet | testnet
LEDGER_DIRECT_XRPL_ACCOUNT=rYourReceivingAddress
```

Check it:

```bash
php artisan ledger-direct:price XRP EUR       # current rate from the oracles
php artisan ledger-direct:sync                # pulls incoming transactions into the table
php artisan about --only=ledgerdirect         # core version, network, account, assets, cache
```

The package is auto-discovered; the provider and the `LedgerDirect` alias register themselves.

## Configuration

`config/ledger-direct.php`, one block per chain:

| Key | Default | |
|---|---|---|
| `chains.xrpl.network` | `testnet` | `mainnet` or `testnet` (`LEDGER_DIRECT_XRPL_NETWORK`) |
| `chains.xrpl.destination_account` | — | your classic `r…` address (`LEDGER_DIRECT_XRPL_ACCOUNT`) |
| `chains.xrpl.assets` | XRP on, RLUSD/USDC off | stablecoins are opt-in; only an explicit `true` enables an asset |
| `quote_expiry_seconds` | `300` | 60–3600 |
| `native_asset_tolerance` | core default (`'0.0015'`) | how far an XRP payment may fall short and still settle; a decimal string in [0, 1) |
| `rate_fresh_ttl_seconds` | core default (60) | how long an exchange rate is served from the cache |
| `cache_store` | default store | for the rate cache and the sync throttle — **must persist between requests** |
| `log_channel` | default channel | |
| `connection` | default connection | where the `ledger_direct_*` tables live |

There are no issuer addresses in the config. Which issuer an RLUSD or USDC payment has to come
from is fixed in the core's `StablecoinRegistry` and is never configurable — a wrong issuer means
lost funds.

Values are validated when they are first used, not at boot: an application that has not been
configured yet still boots and migrates, and the first payment code path fails with a message
naming the setting (`Hardcastle\LedgerDirect\Laravel\Config\InvalidConfigurationException`, a
`LedgerDirectException` like everything the core throws).

**Cache.** With the `array` or `null` store nothing survives the request: rates are not cached and
the status throttle never engages, so every poll hits the node. The provider logs a warning when
it sees one. In tests `array` is right; in production use `redis`, `database` or `file`.

## Usage

The facade is the core's composition root, `Hardcastle\LedgerDirect\Core\LedgerDirect`, as a
singleton. Every core service comes from it:

```php
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect;

$intent = LedgerDirect::paymentIntentService()->quoteForOrder(49.90, 'EUR', 'XRP');
// $intent->destinationAccount, $intent->destinationTag, $intent->amountRequested, $intent->expiry

$status = LedgerDirect::paymentStatus($intent)->toArray();
```

Or inject `Hardcastle\LedgerDirect\Core\LedgerDirect` wherever the container resolves your
classes. Store the intent with your order as `$intent->toArray()` and read it back with
`PaymentIntent::fromArray()` — that array is the cross-platform record, `schema_version` first.

Laravel's clock drives the core: `Date::setTestNow()`, `Carbon::setTestNow()` and `$this->travel()`
in your tests move quote expiry and `seconds_left` too.

## Scheduling the sync

The status endpoint syncs while a customer waits; the scheduled sync is the safety net for
everyone who closed the page. It syncs the configured account without throttling and matches
nothing — it knows no orders. Your shop matches its open orders after it.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ledger-direct:sync')->everyMinute()->withoutOverlapping();
```

## A payment-status endpoint

The contract is fixed by the core (`INVARIANTS.md`, "Payment status"): five states, the payload of
`PaymentStatus::toArray()`, a mandatory throttle, and access by a per-order secret instead of a
login — guest checkout is the rule for crypto payments. The route is yours; the bridge supplies the
parts.

**`StatusResponder`** syncs the receiving account (at most once every 5 s per account, however many
customers are polling — the mark is set *before* the node request, so a hanging node is not hit by
every poll that queues up behind it), matches the intent via the core and derives the status. A
node failure is logged and the answer comes from what is already stored.

**`PaymentIntentStore`** is a small contract for wherever your orders keep their intent: `find()`,
`save()` for a new or refreshed quote, and `replace($orderRef, $expected, $new)` — a
compare-and-set that writes only if the stored intent is still `$expected`. With it,
`respondFor()` loads, matches, stores a change with `replace()` and dispatches events only if its
`replace()` won. Two polls for the same order that run at once and match the same payment
therefore dispatch one event, not two. `replace()` must be atomic for the order's row (one
`UPDATE … WHERE`, or `lockForUpdate()` and the write in one transaction); extend
`Testing\PaymentIntentStoreContractTestCase` to check your implementation.

**Signed URLs** are the recommended secret:

```php
use Hardcastle\LedgerDirect\Laravel\Contracts\PaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Status\StatusResponder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

// When the payment page is rendered:
$statusUrl = URL::temporarySignedRoute('ledger-direct.status', now()->addDay(), ['order' => $order->uuid]);

// routes/web.php
Route::get('/ledger-direct/status/{order}', function (string $order, StatusResponder $responder, PaymentIntentStore $store) {
    $result = $responder->respondFor($order, $store) ?? abort(404);

    return response()->json($result->toArray() + [
        // Only the shop knows where the customer goes next.
        'redirect' => $result->status->isTerminal() ? route('checkout.success', $order) : null,
    ]);
})->name('ledger-direct.status')->middleware('signed');
```

Poll until `redirect` is set. `partial` and `wrong_asset` keep polling so a top-up is noticed,
`expired` too, so a late payment is; only `settled` is terminal.

Without a store, call `respond($intent, $orderRef)` and save `$result->intent` yourself when
`$result->changed()`. `respond()` cannot tell concurrent polls apart: each one that sees the
change dispatches. Prefer `respondFor()`.

### Events

Dispatched when a sync moves an order into the state — never for a poll that finds it where it
was, never for the quote simply running out, and through `respondFor()` only by the request whose
`replace()` stored the change:

| Event | State |
|---|---|
| `Events\PaymentSettled` | `settled` — paid |
| `Events\PaymentPartiallyReceived` | `partial` — some of it, in the quoted asset |
| `Events\PaymentReceivedInWrongAsset` | `wrong_asset` — another currency or issuer; nothing credited |

Each carries `orderRef`, the new `intent`, the `previousIntent`, the `status` and the
`previousState`. Order status, invoices and mails hang off these in your shop.

**Make your listeners queued and idempotent.** The change is stored before the event is
dispatched, so a synchronous listener that throws loses the event — the next poll sees nothing new
and the order would stay unpaid in your shop. A queued listener (`ShouldQueue`) is retried by the
queue; a retry can repeat work, hence idempotent. Inside a database transaction the events wait
for the commit and are dropped on a rollback (`ShouldDispatchAfterCommit`).

## Tables and `migrate:fresh`

The migration creates `ledger_direct_xrpl_tx` and `ledger_direct_xrpl_destination_tag` from the
core's `Xrpl\Schema::tables()`, under your connection's table prefix.

`migrate:rollback` drops only `ledger_direct_xrpl_tx` — the next sync rebuilds it from the ledger.
It **keeps** `ledger_direct_xrpl_destination_tag`: that counter is the only record of which
destination tags were already issued, and a fresh one hands out tags that open orders are still
waiting on, so one customer's payment settles another customer's order.

> **`php artisan migrate:fresh` and `db:wipe` drop every table, the counter included.** Never run
> them against a database with orders that may still receive a payment.

Supported databases: MySQL, MariaDB, PostgreSQL and SQLite. On MySQL and MariaDB the integer
columns are unsigned, as in the core's DDL; elsewhere the unsigned columns become `bigint`, so
destination tags up to 4294967295 fit. The CTID is stored in a column named `tx_ctid` (core 0.9):
PostgreSQL gives every table a system column called `ctid`, which no user column may be named
after.

Inserting synced transactions ignores duplicates by hash and nothing else: a value that does not
fit its column is an error, never a silent truncation.

## Swapping infrastructure

The root is built from container entries you can rebind in a service provider or a test:

| Key (`LedgerDirectServiceProvider::…`) | Default |
|---|---|
| `HTTP_CLIENT` | Guzzle, connect timeout 5 s, total 15 s, TLS verified |
| `CACHE` | `Cache::store(config('ledger-direct.cache_store'))` |
| `LOGGER` | `Log::channel(config('ledger-direct.log_channel'))` |
| `CLOCK` | `Clock\LaravelClock` |

`XrplTransactionRepositoryInterface` and `ConfigProviderInterface` are bound to the bridge's
implementations and can be rebound the same way.

## Testing your integration

The core ships a testing kit under `Hardcastle\LedgerDirect\Core\Testing\` (`FakeHttpClient`,
`InMemoryCache`, `FrozenClock`, `RecordingLogger`, …); this package adds
`Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore` and
`PaymentIntentStoreContractTestCase`, the contract your own store has to keep.

```php
$this->app->instance(LedgerDirectServiceProvider::HTTP_CLIENT, $http = new FakeHttpClient());
$http->queueResponse('s.altnet.rippletest.net', new Response(200, [], $accountTxJson));
```

## Developing this package

```bash
composer install
vendor/bin/phpunit                          # unit suite, SQLite, no network
vendor/bin/phpstan analyse                  # Larastan, level 6

docker compose up -d mysql                  # MySQL 8.4 on localhost:33306
LEDGER_DIRECT_TEST_MYSQL_HOST=127.0.0.1 LEDGER_DIRECT_TEST_MYSQL_PORT=33306 \
LEDGER_DIRECT_TEST_MYSQL_PASSWORD=root vendor/bin/phpunit    # everything against MySQL

docker compose up -d postgres               # PostgreSQL 17 on localhost:35432
LEDGER_DIRECT_TEST_PGSQL_HOST=127.0.0.1 LEDGER_DIRECT_TEST_PGSQL_PORT=35432 \
LEDGER_DIRECT_TEST_PGSQL_PASSWORD=root vendor/bin/phpunit    # everything against PostgreSQL

docker compose run --rm php vendor/bin/phpunit                # the same inside the PHP 8.4 container

vendor/bin/phpunit --testsuite integration  # live oracles; set LEDGER_DIRECT_TEST_XRPL_ACCOUNT
                                            # to a funded testnet account for the sync test
```

**Acceptance in a fresh app.** `dev/acceptance.sh <12|13> <sqlite|mysql|pgsql>` creates a new
Laravel app with `composer create-project`, installs this package from a `git archive` of `HEAD`
(what Packagist ships) and walks this README: publish, migrate, a faucet-funded testnet account,
`about`, `price`, `sync`, two quotes from consecutive counter values, a status answer, and a
rollback that keeps the counter. It needs network, and for MySQL/PostgreSQL the Docker containers
above. CI runs all six combinations together with the integration suite when the workflow is
started by hand with *integration* ticked.

The repository runs the core's `XrplTransactionRepositoryContractTestCase` against SQLite, MySQL
and PostgreSQL, plus a multi-process test that the destination-tag counter is atomic on MySQL and
on PostgreSQL (SQLite takes no row locks, so atomicity is not provable there). CI covers Laravel 12 × PHP 8.2–8.4 and
Laravel 13 × PHP 8.3–8.4, each with lowest and newest dependencies.

To test against an unreleased core, point Composer at a local checkout — and don't commit it:

```bash
composer config repositories.core path ../LedgerDirectCorePHP/ledger-direct-core-php
```
