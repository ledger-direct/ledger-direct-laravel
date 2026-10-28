# ledger-direct-laravel

LedgerDirect for Laravel: binds [`hardcastle/ledger-direct-core`](https://github.com/ledger-direct/ledger-direct-core-php)
— XRP, RLUSD and USDC payments on the XRP Ledger, XLM, USDC and EURC on Stellar — into any
Laravel 12 or 13 application.

A thin bridge, and deliberately nothing more: a service provider, a facade, config, a migration,
the storage the core needs, Artisan commands and the building blocks of a payment-status
endpoint, for every chain the core supports. It knows no orders. Prices, conversion, destination
tags and memo ids, syncing, matching and the
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

# Stellar is opt-in:
LEDGER_DIRECT_STELLAR_ENABLED=true
LEDGER_DIRECT_STELLAR_NETWORK=testnet          # mainnet | testnet
LEDGER_DIRECT_STELLAR_ACCOUNT=GYourReceivingPublicKey
```

Check it:

```bash
php artisan ledger-direct:price XRP EUR       # current rate from the oracles (XLM, EURC, … too)
php artisan ledger-direct:sync                # pulls incoming payments of every enabled chain
php artisan about --only=ledgerdirect         # core version, cache, and per chain: network, account, assets
```

The package is auto-discovered; the provider and the `LedgerDirect` and `LedgerDirectStellar`
aliases register themselves. Upgrading from 0.1: publish the migrations again
(`vendor:publish --tag=ledger-direct-migrations`) and `migrate` — that adds the Stellar tables. A
config file published by 0.1 keeps working; it simply has no Stellar block until you publish the
config again or add one.

## Configuration

`config/ledger-direct.php`, one block per chain the core's `ChainCatalog` lists:

| Key | Default | |
|---|---|---|
| `chains.xrpl.enabled` | `true` | takes payments on the XRP Ledger (`LEDGER_DIRECT_XRPL_ENABLED`) |
| `chains.xrpl.network` | `testnet` | `mainnet` or `testnet` (`LEDGER_DIRECT_XRPL_NETWORK`) |
| `chains.xrpl.destination_account` | — | your classic `r…` address (`LEDGER_DIRECT_XRPL_ACCOUNT`) |
| `chains.xrpl.assets` | XRP on, RLUSD/USDC off | stablecoins are opt-in; only an explicit `true` enables an asset |
| `chains.stellar.enabled` | `false` | takes payments on Stellar (`LEDGER_DIRECT_STELLAR_ENABLED`) |
| `chains.stellar.network` | `testnet` | `mainnet` or `testnet` (`LEDGER_DIRECT_STELLAR_NETWORK`) |
| `chains.stellar.destination_account` | — | your `G…` public key (`LEDGER_DIRECT_STELLAR_ACCOUNT`) |
| `chains.stellar.assets` | XLM on, USDC/EURC off | stablecoins are opt-in, and offered only while the account has a trust line for them |
| `quote_expiry_seconds` | `300` | 60–3600 |
| `native_asset_tolerance` | core default (`'0.0015'`) | how far an XRP payment may fall short and still settle; a decimal string in [0, 1) |
| `rate_fresh_ttl_seconds` | core default (60) | how long an exchange rate is served from the cache |
| `cache_store` | default store | for the rate cache and the sync throttle — **must persist between requests** |
| `log_channel` | default channel | |
| `connection` | default connection | where the `ledger_direct_*` tables live |

A disabled chain accepts nothing, its native asset included. There are no issuer addresses in the
config: which issuer an RLUSD, USDC or EURC payment has to come from is fixed in the core's
registries and never configurable — a wrong issuer means lost funds.

Values are validated when they are first used, not at boot: an application that has not been
configured yet still boots and migrates, and the first payment code path fails with a message
naming the setting (`Hardcastle\LedgerDirect\Laravel\Config\InvalidConfigurationException`, a
`LedgerDirectException` like everything the core throws).

**Cache.** One store serves the rate cache, the sync throttle and the Stellar account lookups of
both chains. With the `array` or `null` store nothing survives the request: rates are not cached,
the status throttle never engages so every poll hits the node, and every checkout asks Horizon
again — against a public limit of 3600 requests per hour. The provider logs a warning when it sees
one. In tests `array` is right; in production use `redis`, `database` or `file`.

## Usage

The facade is the core's composition root, `Hardcastle\LedgerDirect\Core\LedgerDirect`, as a
singleton. Every core service comes from it:

```php
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect;

$intent = LedgerDirect::paymentIntentService()->quoteForOrder(49.90, 'EUR', 'XRP');
// $intent->destinationAccount, $intent->destinationTag, $intent->amountRequested, $intent->expiry

$status = LedgerDirect::paymentStatus($intent)->toArray();
```

Stellar has a composition root of its own, `Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar`,
with the facade `LedgerDirectStellar`. The intent is the same record; on Stellar `chain` is
`STELLAR`, `type` is `stellar-xlm-payment` (`-usdc-`, `-eurc-`), and `destinationTag` carries the
**memo id** the customer has to put on the payment:

```php
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirectStellar;

$intent = LedgerDirectStellar::paymentIntentService()->quoteForOrder(49.90, 'EUR', 'XLM');
```

Or inject the roots wherever the container resolves your classes. Code that handles intents of
either chain — a payment page, a status endpoint, a cron job — asks
`Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains` for the root by the intent's chain
instead of branching itself; both roots offer the same calls (`syncService()`, `settlementPolicy()`,
`paymentStatus()`, …):

```php
$root = app(LedgerDirectChains::class)->forIntent($intent);
$uri  = app(LedgerDirectChains::class)->paymentUri($intent, $amountShown);   // XRPL or SEP-0007, for the QR code
```

Store the intent with your order as `$intent->toArray()` and read it back with
`PaymentIntent::fromArray()` — that array is the cross-platform record, `schema_version` first.

### What a checkout can offer

`Hardcastle\LedgerDirect\Laravel\Checkout\PaymentOptions::available()` lists the options a checkout
can offer right now, one per chain and asset, in catalog order: enabled chains, enabled assets,
and an issued asset on Stellar only if the receiving account has a usable trust line for it — a
payment to an account without one fails with `op_no_trust` and never arrives. The check goes
through the core's `AccountInspector`, cached; if Horizon cannot answer, the asset is offered and
the failure logged. Each `PaymentOption` carries the chain and asset descriptors, network,
account, `type()` (the intent's type, the key to map your payment method to) and `label()`
(`"XLM (Stellar)"`).

Laravel's clock drives the core: `Date::setTestNow()`, `Carbon::setTestNow()` and `$this->travel()`
in your tests move quote expiry and `seconds_left` too.

## Scheduling the sync

The status endpoint syncs while a customer waits; the scheduled sync is the safety net for
everyone who closed the page. It syncs the configured account of every enabled chain without
throttling (`--chain=stellar` for one), and matches nothing — it knows no orders. Your shop matches
its open orders after it. One chain failing does not keep the others from syncing.

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

**`StatusResponder`** works for intents of either chain — it takes the root the intent's `chain`
names. It syncs the receiving account (at most once every 5 s per account, however many
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

Two migrations, built from the core's schema classes, under your connection's table prefix:
`ledger_direct_xrpl_tx` and `ledger_direct_xrpl_destination_tag` from `Xrpl\Schema::tables()`,
`ledger_direct_stellar_payment` and `ledger_direct_stellar_memo` from `Stellar\Schema::tables()`.
The Stellar tables are created whether or not Stellar is enabled; empty tables cost nothing.

`migrate:rollback` drops only the payment tables — the next sync rebuilds them from the ledger. It
**keeps** the counters, `ledger_direct_xrpl_destination_tag` and `ledger_direct_stellar_memo`: a
counter is the only record of which destination tags or memo ids were already issued, and a fresh
one hands out identifiers that open orders are still waiting on, so one customer's payment settles
another customer's order.

> **`php artisan migrate:fresh` and `db:wipe` drop every table, the counters included.** Never run
> them against a database with orders that may still receive a payment.

Supported databases: MySQL, MariaDB, PostgreSQL and SQLite. On MySQL and MariaDB the integer
columns are unsigned, as in the core's DDL; elsewhere the unsigned columns become `bigint`, so
destination tags up to 4294967295 fit. The CTID is stored in a column named `tx_ctid` (core 0.9):
PostgreSQL gives every table a system column called `ctid`, which no user column may be named
after.

Stellar's `memo_id` holds whatever identifier a payment to the account carried, and a stranger's
may be any unsigned 64-bit number. It is `BIGINT UNSIGNED` on MySQL, `numeric(20,0)` on PostgreSQL
and a string on SQLite, so the largest one is stored exactly and cannot fail a sync.

Inserting synced payments ignores duplicates (by hash; on Stellar by hash and operation index) and
nothing else: a value that does not fit its column is an error, never a silent truncation.

## Swapping infrastructure

Both roots are built from the same container entries, which you can rebind in a service provider
or a test:

| Key (`LedgerDirectServiceProvider::…`) | Default |
|---|---|
| `HTTP_CLIENT` | Guzzle, connect timeout 5 s, total 15 s, TLS verified |
| `CACHE` | `Cache::store(config('ledger-direct.cache_store'))` |
| `LOGGER` | `Log::channel(config('ledger-direct.log_channel'))` |
| `CLOCK` | `Clock\LaravelClock` |

`XrplTransactionRepositoryInterface`, `StellarPaymentRepositoryInterface` and
`ConfigProviderInterface` are bound to the bridge's implementations and can be rebound the same way.

## Testing your integration

The core ships a testing kit under `Hardcastle\LedgerDirect\Core\Testing\` (`FakeHttpClient`,
`InMemoryCache`, `FrozenClock`, `RecordingLogger`, `InMemoryStellarPaymentRepository`, …); this
package adds
`Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore` and
`PaymentIntentStoreContractTestCase`, the contract your own store has to keep.

```php
$this->app->instance(LedgerDirectServiceProvider::HTTP_CLIENT, $http = new FakeHttpClient());
$http->queueResponse('s.altnet.rippletest.net', new Response(200, [], $accountTxJson));   // XRPL node
$http->queueResponse('/payments', new Response(200, [], $horizonPaymentsJson));          // Horizon
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

LEDGER_DIRECT_TEST_XRPL_ACCOUNT=$(dev/fund-testnet-account.sh) \
LEDGER_DIRECT_TEST_STELLAR_ACCOUNT=$(dev/fund-stellar-testnet-account.sh) \
vendor/bin/phpunit --testsuite integration  # live oracles, XRPL testnet and Horizon testnet
```

**Acceptance in a fresh app.** `dev/acceptance.sh <12|13> <sqlite|mysql|pgsql>` creates a new
Laravel app with `composer create-project`, installs this package from a `git archive` of `HEAD`
(what Packagist ships) and walks this README: publish, migrate, a faucet-funded XRPL testnet
account, `about`, `price`, `sync`, two quotes from consecutive counter values, a status answer;
then Stellar on a Friendbot-funded account with the same steps, the payment options and the
SEP-0007 request; and a rollback that keeps both counters. It needs network, and for MySQL/PostgreSQL the Docker containers
above. CI runs all six combinations together with the integration suite when the workflow is
started by hand with *integration* ticked.

The repository runs the core's `XrplTransactionRepositoryContractTestCase` and
`StellarPaymentRepositoryContractTestCase` against SQLite, MySQL and PostgreSQL, plus multi-process
tests that the identifier counter stays gapless and duplicate-free on MySQL, PostgreSQL and a
SQLite file. CI covers Laravel 12 × PHP 8.2–8.4 and Laravel 13 × PHP 8.3–8.4, each with lowest
and newest dependencies.

To test against an unreleased core, point Composer at a local checkout — and don't commit it:

```bash
composer config repositories.core path ../LedgerDirectCorePHP/ledger-direct-core-php
```
