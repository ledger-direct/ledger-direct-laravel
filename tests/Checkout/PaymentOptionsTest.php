<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Checkout;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Laravel\Checkout\PaymentOption;
use Hardcastle\LedgerDirect\Laravel\Checkout\PaymentOptions;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;

final class PaymentOptionsTest extends TestCase
{
    /** Circle's USDC issuer on the Stellar testnet, from the core's registry. */
    private const USDC_TESTNET_ISSUER = 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5';

    private FakeHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = $this->fakeHttp();
        $this->logger = $this->recordLogs();
    }

    public function testByDefaultOnlyXrpIsOffered(): void
    {
        self::assertSame(['xrp-payment'], $this->types());
        self::assertSame('XRP (XRP Ledger)', $this->paymentOptions()->available()[0]->label());
    }

    public function testEnabledStellarAddsXlmWithoutAnyHorizonRequest(): void
    {
        $this->enableStellar();

        self::assertSame(['xrp-payment', 'stellar-xlm-payment'], $this->types());
        self::assertSame([], $this->http->sentRequests(), 'the native asset needs no trust line');
    }

    public function testAStellarStablecoinIsOfferedOnlyWithATrustLine(): void
    {
        $this->enableStellar(['USDC' => true, 'EURC' => true]);
        $this->http->queueResponse('/accounts/' . self::STELLAR_ACCOUNT, self::account([
            ['asset_type' => 'credit_alphanum4', 'asset_code' => 'USDC', 'asset_issuer' => self::USDC_TESTNET_ISSUER, 'balance' => '0.0000000', 'limit' => '1000.0000000', 'is_authorized' => true],
        ]));

        self::assertSame(['xrp-payment', 'stellar-xlm-payment', 'stellar-usdc-payment'], $this->types(), 'EURC has no trust line');
        self::assertCount(1, $this->http->sentRequests(), 'one account lookup answers both assets');
    }

    /** Horizon down must not make a payment method vanish mid-checkout. */
    public function testHorizonDownOffersTheAssetAndLogs(): void
    {
        $this->enableStellar(['USDC' => true]);
        $this->http->queueResponse('/accounts/' . self::STELLAR_ACCOUNT, new Response(503));

        self::assertContains('stellar-usdc-payment', $this->types());
        self::assertStringContainsString('could not check the Stellar trust line', $this->lastWarning());
    }

    public function testAnEnabledChainWithoutAnAccountOffersNothingAndSaysWhy(): void
    {
        $this->enableStellar();
        $this->app['config']->set('ledger-direct.chains.stellar.destination_account', null);

        self::assertSame(['xrp-payment'], $this->types());
        self::assertStringContainsString('chain enabled but not usable', $this->lastWarning());
    }

    public function testForTypeFindsAnAvailableOption(): void
    {
        $this->enableStellar();

        $option = $this->paymentOptions()->forType('stellar-xlm-payment');

        self::assertInstanceOf(PaymentOption::class, $option);
        self::assertSame(self::STELLAR_ACCOUNT, $option->account);
        self::assertSame('testnet', $option->network);
        self::assertNull($this->paymentOptions()->forType('stellar-eurc-payment'));
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_map(static fn (PaymentOption $o): string => $o->type(), $this->paymentOptions()->available());
    }

    private function paymentOptions(): PaymentOptions
    {
        return $this->app->make(PaymentOptions::class);
    }

    /** @param list<array<string, mixed>> $balances */
    private static function account(array $balances): Response
    {
        return new Response(200, [], json_encode([
            'id' => self::STELLAR_ACCOUNT,
            'balances' => [...$balances, ['asset_type' => 'native', 'balance' => '100.0000000']],
            'flags' => ['auth_required' => false, 'auth_revocable' => false, 'auth_clawback_enabled' => false],
        ], JSON_THROW_ON_ERROR));
    }

    private function lastWarning(): string
    {
        $warnings = array_filter($this->logger->records(), static fn (array $r): bool => $r['level'] === 'warning');

        return (string) (end($warnings)['message'] ?? '');
    }
}
