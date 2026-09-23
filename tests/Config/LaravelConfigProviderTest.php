<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Config;

use Hardcastle\LedgerDirect\Laravel\Config\InvalidConfigurationException;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaravelConfigProviderTest extends TestCase
{
    private const ACCOUNT = 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh';

    public function testReadsTheXrplBlockUnderTheCoreChainName(): void
    {
        $provider = $this->provider(['network' => 'mainnet', 'destination_account' => self::ACCOUNT]);

        self::assertSame('mainnet', $provider->getNetwork('XRPL'));
        self::assertSame(self::ACCOUNT, $provider->getDestinationAccount('XRPL'));
        self::assertSame(300, $provider->getQuoteExpirySeconds());
        self::assertSame(['XRPL'], $provider->configuredChains());
    }

    public function testOnlyAnExplicitTrueEnablesAnAsset(): void
    {
        $provider = $this->provider(['assets' => ['XRP' => true, 'RLUSD' => false, 'USDC' => 'yes']]);

        self::assertTrue($provider->isAssetEnabled('XRPL', 'XRP'));
        self::assertFalse($provider->isAssetEnabled('XRPL', 'RLUSD'));
        self::assertFalse($provider->isAssetEnabled('XRPL', 'USDC'));
        self::assertFalse($provider->isAssetEnabled('XRPL', 'SOLO'), 'an asset the config does not list is off');
    }

    public function testAMissingAccountNamesTheEnvVariable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('LEDGER_DIRECT_XRPL_ACCOUNT');

        $this->provider(['destination_account' => null])->getDestinationAccount('XRPL');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedAccounts(): iterable
    {
        yield 'X-address' => ['XVLhHMPHU98es4dbozjVtdWzVrDjtV18pX8yuPT7y4xaEHi'];
        yield 'Stellar key' => ['GAHK7EEG2WWHVKDNT4CEQFZGKF2LGDSW2IVM4S5DP42RBW3K6BTODB4A'];
        yield 'truncated' => ['rHb9CJAWyB4rj91'];
        yield 'contains 0' => ['rHb9CJAWyB4rj91VRWn96DkukG4bwdty0h'];
        yield 'whitespace' => [' ' . self::ACCOUNT];
        yield 'not a string' => [12345];
    }

    #[DataProvider('malformedAccounts')]
    public function testAMalformedAccountIsRejected(mixed $account): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not a classic XRPL address');

        $this->provider(['destination_account' => $account])->getDestinationAccount('XRPL');
    }

    public function testTheNetworkIsMainnetOrTestnet(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be "mainnet" or "testnet", got \'devnet\'');

        $this->provider(['network' => 'devnet'])->getNetwork('XRPL');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidExpiries(): iterable
    {
        yield 'too short' => [59];
        yield 'too long' => [3601];
        yield 'fraction' => [300.5];
        yield 'text' => ['five minutes'];
        yield 'missing' => [null];
    }

    #[DataProvider('invalidExpiries')]
    public function testTheExpiryIsBounded(mixed $seconds): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('between 60 and 3600');

        $this->provider([], ['quote_expiry_seconds' => $seconds])->getQuoteExpirySeconds();
    }

    public function testTheExpiryAcceptsTheBoundsAndADigitString(): void
    {
        self::assertSame(60, $this->provider([], ['quote_expiry_seconds' => 60])->getQuoteExpirySeconds());
        self::assertSame(3600, $this->provider([], ['quote_expiry_seconds' => '3600'])->getQuoteExpirySeconds());
    }

    public function testAnUnknownChainIsAClearError(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not support the chain "STELLAR"');

        $this->provider()->getNetwork('STELLAR');
    }

    public function testMapsCliChainNames(): void
    {
        self::assertSame('XRPL', LaravelConfigProvider::coreChainName('xrpl'));
        self::assertSame('XRPL', LaravelConfigProvider::coreChainName('XRPL'));
        self::assertNull(LaravelConfigProvider::coreChainName('stellar'));
    }

    /**
     * @param array<string, mixed> $xrpl
     * @param array<string, mixed> $root
     */
    private function provider(array $xrpl = [], array $root = []): LaravelConfigProvider
    {
        $config = require __DIR__ . '/../../config/ledger-direct.php';
        $config['chains']['xrpl'] = array_replace($config['chains']['xrpl'], ['destination_account' => self::ACCOUNT], $xrpl);

        return new LaravelConfigProvider(new Repository(['ledger-direct' => array_replace($config, $root)]));
    }
}
