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
use Hardcastle\LedgerDirect\Core\Stellar\Schema as StellarSchema;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema as XrplSchema;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** A well-formed classic address; nothing is ever sent to it. */
    public const ACCOUNT = 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh';

    /** A well-formed Stellar public key; nothing is ever sent to it. */
    public const STELLAR_ACCOUNT = 'GAHK7EEG2WWHVKDNT4CEQFZGKF2LGDSW2IVM4S5DP42RBW3K6BTODB4A';

    /** Horizon testnet, as the core's HorizonClient addresses it. */
    public const HORIZON = 'horizon-testnet.stellar.org';

    protected function getPackageProviders($app): array
    {
        return [LedgerDirectServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['LedgerDirect' => \Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect::class];
    }

    /**
     * SQLite in memory, or the MySQL or PostgreSQL test server when one is
     * configured — the whole suite then runs against it, migration
     * comparison included.
     */
    protected function defineEnvironment($app): void
    {
        $server = Databases::serverConfig();

        if ($server !== null) {
            $app['config']->set('database.connections.testing', $server + ['prefix' => 'app_']);
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

    /** Fresh tables of both chains for tests that touch the database; the counter tables included. */
    protected function createTables(): void
    {
        $tables = array_merge(array_keys(XrplSchema::tables()), array_keys(StellarSchema::tables()));

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        LedgerDirectTables::create(Schema::connection(null));
        LedgerDirectTables::createStellar(Schema::connection(null));
    }

    /** Turns Stellar on with a valid receiving account; $assets on top of XLM. */
    protected function enableStellar(array $assets = []): void
    {
        $this->app['config']->set('ledger-direct.chains.stellar.enabled', true);
        $this->app['config']->set('ledger-direct.chains.stellar.network', 'testnet');
        $this->app['config']->set('ledger-direct.chains.stellar.destination_account', self::STELLAR_ACCOUNT);
        $this->app['config']->set('ledger-direct.chains.stellar.assets', ['XLM' => true] + $assets);
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

    /**
     * A page of Horizon's /accounts/{id}/payments, as the core reads it.
     *
     * @param list<array<string, mixed>> $records
     */
    protected static function horizonPayments(array $records = []): Response
    {
        return new Response(200, [], json_encode(['_embedded' => ['records' => $records]], JSON_THROW_ON_ERROR));
    }

    /**
     * One incoming payment operation as Horizon lists it. The operation id
     * is a TOID: ledger << 32 | transaction order << 12 | operation index.
     *
     * @return array<string, mixed>
     */
    protected static function stellarPayment(string $hash, int $memoId, string $amount, int $ledger = 1000, ?string $assetCode = null, ?string $issuer = null): array
    {
        $id = (string) (($ledger << 32) | (1 << 12) | 1);

        return [
            'id' => $id,
            'paging_token' => $id,
            'type' => 'payment',
            'transaction_successful' => true,
            'transaction_hash' => $hash,
            'from' => 'GBRPYHIL2CI3FNQ4BXLFMNDLFJUNPU2HY3ZMFSHONUCEOASW7QC7OX2H',
            'to' => self::STELLAR_ACCOUNT,
            'asset_type' => $assetCode === null ? 'native' : 'credit_alphanum4',
            'asset_code' => $assetCode,
            'asset_issuer' => $issuer,
            'amount' => $amount,
            'created_at' => '2026-10-27T12:00:00Z',
            'transaction' => ['memo_type' => 'id', 'memo' => (string) $memoId],
        ];
    }

    protected static function xlmIntent(float $requested, ?int $expiry = null, int $memoId = 4242): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'stellar-xlm-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: 'XLM',
            quoteCurrency: 'EUR',
            pairing: 'XLM/EUR',
            exchangeRate: 0.25,
            amountRequested: $requested,
            destinationAccount: self::STELLAR_ACCOUNT,
            destinationTag: $memoId,
            expiry: $expiry,
        );
    }
}
