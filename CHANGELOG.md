# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
  request), `Contracts\PaymentIntentStore`, and the events `PaymentSettled`,
  `PaymentPartiallyReceived` and `PaymentReceivedInWrongAsset`, dispatched on state changes only.
- `Testing\InMemoryPaymentIntentStore` for shop packages' tests.
- PostgreSQL support: the migration and the repository run on PostgreSQL 17, covered by the
  core's repository contract test and a multi-process counter test in CI.

### Changed

- The CTID column is `tx_ctid`, following core 0.9 — PostgreSQL reserves `ctid` as a system
  column name. No released installation carries the old name.
- Synced transactions are stored with an upsert on the hash instead of `INSERT IGNORE`, so only
  duplicates are ignored; a value that does not fit its column fails instead of being truncated.
