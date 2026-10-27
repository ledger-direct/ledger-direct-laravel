# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Stellar as the second chain, on core 0.10.

### Added

- `chains.stellar` in the config (opt-in, `LEDGER_DIRECT_STELLAR_*`) and an `enabled` flag per
  chain. XRPL is enabled without one, so a config file published by 0.1 keeps working.
- `Repository\DatabaseStellarPaymentRepository` for the core's Stellar port, keyed by
  `(hash, op_index)` with identifiers as strings; passes the core's
  `StellarPaymentRepositoryContractTestCase` on SQLite, MySQL and PostgreSQL.
- A second publishable migration creating `ledger_direct_stellar_payment` and
  `ledger_direct_stellar_memo` from `Stellar\Schema`; `down()` keeps the memo-id counter.
  `memo_id` takes the full uint64 range on every driver.
- `Stellar\LedgerDirectStellar` bound as a singleton on the same HTTP client, cache, logger and
  clock, with the facade and alias `LedgerDirectStellar`.
- `Chains\LedgerDirectChains`: the root for an intent's chain, the enabled chains, and the
  payment URI for the QR code (XRPL or SEP-0007).
- `Checkout\PaymentOptions` / `PaymentOption`: what a checkout can offer now; a Stellar issued
  asset only while the receiving account has a usable trust line.
- `ledger-direct:sync --chain=`, `ledger-direct:price --chain=`; Stellar in `php artisan about`.

### Changed

- `LaravelConfigProvider` reads chains and assets from the core's `ChainCatalog`. Validation
  messages name the chain ("not a valid XRP Ledger receiving account").
- `StatusResponder` takes `LedgerDirectChains` instead of the XRPL root and serves intents of
  either chain. Resolved from the container, nothing changes for callers.
- `ledger-direct:sync` without `--chain` syncs every enabled chain; one failing does not stop the
  others. `ledger-direct:price` prices any catalog asset via `PriceService::quoteAmount()`.
- The cache warning is logged once for both roots, when the cache store is first resolved.
- The identifier counter moved into `Repository\SequenceCounter`, shared by both repositories.

## [0.1.0] – 2026-10-27

First release: the Laravel bridge over `hardcastle/ledger-direct-core` 0.10. Requires 0.10 rather than
0.9 for its fix that a payment from another network never settles an order (XRPL only; Stellar is not
bound by this release).

### Added

- `LedgerDirectServiceProvider` binding the core's composition root `Core\LedgerDirect` as a
  singleton, with Guzzle (bounded timeouts, TLS verified), the configured cache store, log channel
  and a Laravel-backed PSR-20 clock. A warning is logged when the cache store does not persist
  between requests.
- `Facades\LedgerDirect`, auto-discovered alias `LedgerDirect`.
- `config/ledger-direct.php` with one block per chain; `Config\LaravelConfigProvider` validating
  network, receiving address and quote expiry on first use with messages that name the setting.
- `Repository\DatabaseXrplTransactionRepository` on the query builder: atomic destination-tag
  counter (MySQL/MariaDB `LAST_INSERT_ID()`, elsewhere a locked transaction), newest-first with an
  `id` tie-break, cursor per account and network. Passes the core's repository contract test on
  SQLite and MySQL.
- A publishable migration built from `Xrpl\Schema::tables()`; its `down()` keeps the
  destination-tag counter table.
- `Clock\LaravelClock`, so `Date::setTestNow()` reaches the core.
- `ledger-direct:sync` and `ledger-direct:price` Artisan commands and a `php artisan about` section.
- Payment-status building blocks: `Status\StatusResponder` (throttled sync, marked before the node
  request), `Contracts\PaymentIntentStore` with a compare-and-set `replace()`, and the events
  `PaymentSettled`, `PaymentPartiallyReceived` and `PaymentReceivedInWrongAsset`, dispatched on
  state changes only, by the request whose `replace()` won, after the surrounding transaction
  commits.
- `Testing\InMemoryPaymentIntentStore` and `Testing\PaymentIntentStoreContractTestCase` for shop
  packages' tests.
- PostgreSQL support: the migration and the repository run on PostgreSQL 17, covered by the
  core's repository contract test and a multi-process counter test in CI.

### Changed

- The CTID column is `tx_ctid`, following core 0.9 — PostgreSQL reserves `ctid` as a system
  column name. No released installation carries the old name.
- Synced transactions are stored with an upsert on the hash instead of `INSERT IGNORE`, so only
  duplicates are ignored; a value that does not fit its column fails instead of being truncated.
- The destination-tag counter on PostgreSQL and SQLite increments before it reads. Reading first
  made SQLite fail concurrent checkouts with "database is locked"; a file-SQLite test with six
  processes covers it.
- The receiving address is checked with the core's `ChainCatalog` instead of a pattern of its own,
  which also rejects a trailing newline.
