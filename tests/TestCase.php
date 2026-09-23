<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\LedgerDirectServiceProvider;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** A well-formed classic address; nothing is ever sent to it. */
    public const ACCOUNT = 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh';

    protected function getPackageProviders($app): array
    {
        return [LedgerDirectServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['LedgerDirect' => \Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect::class];
    }

    /**
     * SQLite in memory, or the MySQL test server when one is configured —
     * the whole suite then runs against it, migration comparison included.
     */
    protected function defineEnvironment($app): void
    {
        $mysql = Databases::mysqlConfig();

        if ($mysql !== null) {
            $app['config']->set('database.connections.testing', $mysql + ['prefix' => 'app_']);
        } else {
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => 'app_',
            ]);
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ledger-direct.chains.xrpl.network', 'testnet');
        $app['config']->set('ledger-direct.chains.xrpl.destination_account', self::ACCOUNT);
    }

    /** Fresh tables for tests that touch the database; the counter table included. */
    protected function createTables(): void
    {
        foreach (['ledger_direct_xrpl_tx', 'ledger_direct_xrpl_destination_tag'] as $table) {
            Schema::dropIfExists($table);
        }

        LedgerDirectTables::create(Schema::connection(null));
    }

    protected function fakeHttp(): FakeHttpClient
    {
        $client = new FakeHttpClient();
        $this->app->instance(LedgerDirectServiceProvider::HTTP_CLIENT, $client);

        return $client;
    }

    protected function recordLogs(): RecordingLogger
    {
        $logger = new RecordingLogger();
        $this->app->instance(LedgerDirectServiceProvider::LOGGER, $logger);

        return $logger;
    }

    /**
     * @param list<array<string, mixed>> $transactions raw account_tx entries
     */
    protected static function accountTx(array $transactions = []): Response
    {
        return new Response(200, [], json_encode([
            'result' => ['status' => 'success', 'transactions' => $transactions],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * One incoming XRP payment as account_tx returns it.
     *
     * @return array<string, mixed>
     */
    protected static function xrpPayment(string $hash, int $tag, int $drops, int $ledgerIndex = 1000): array
    {
        return [
            'meta' => ['delivered_amount' => (string) $drops, 'TransactionResult' => 'tesSUCCESS'],
            'tx' => [
                'TransactionType' => 'Payment',
                'Account' => 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe',
                'Destination' => self::ACCOUNT,
                'DestinationTag' => $tag,
                'Amount' => (string) $drops,
                'hash' => $hash,
                'ctid' => 'C000' . strtoupper(substr(md5($hash), 0, 12)),
                'ledger_index' => $ledgerIndex,
                'date' => 800000000,
            ],
        ];
    }

    protected static function xrpIntent(float $requested, ?int $expiry = null, int $tag = 4242): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: $requested,
            destinationAccount: self::ACCOUNT,
            destinationTag: $tag,
            expiry: $expiry,
        );
    }
}
