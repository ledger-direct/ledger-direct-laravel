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
        self::assertSame(['XRPL', 'STELLAR'], $provider->configuredChains());
        self::assertSame(['XRPL'], $provider->enabledChains(), 'Stellar is opt-in');
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
        yield 'trailing newline' => [self::ACCOUNT . "\n"];
        yield 'not a string' => [12345];
    }

    #[DataProvider('malformedAccounts')]
    public function testAMalformedAccountIsRejected(mixed $account): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not a valid XRP Ledger receiving account');

        $this->provider(['destination_account' => $account])->getDestinationAccount('XRPL');
    }

    public function testTheNetworkIsMainnetOrTestnet(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be one of "mainnet", "testnet", got \'devnet\'');

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
        $this->expectExceptionMessage('does not know the chain "XAHAU"');

        $this->provider()->getNetwork('XAHAU');
    }

    public function testMapsCliChainNames(): void
    {
        self::assertSame('XRPL', LaravelConfigProvider::coreChainName('xrpl'));
        self::assertSame('XRPL', LaravelConfigProvider::coreChainName('XRPL'));
        self::assertSame('STELLAR', LaravelConfigProvider::coreChainName('stellar'));
        self::assertNull(LaravelConfigProvider::coreChainName('xahau'));
    }

    private const STELLAR_ACCOUNT = 'GAHK7EEG2WWHVKDNT4CEQFZGKF2LGDSW2IVM4S5DP42RBW3K6BTODB4A';

    public function testStellarIsOffUntilEnabled(): void
    {
        $provider = $this->provider();

        self::assertFalse($provider->isChainEnabled('STELLAR'));
        self::assertFalse($provider->isAssetEnabled('STELLAR', 'XLM'), 'a disabled chain accepts nothing, its native asset included');
    }

    public function testAnEnabledStellarBlockIsReadUnderItsOwnKeys(): void
    {
        $provider = $this->provider([], [], ['enabled' => true, 'network' => 'mainnet', 'destination_account' => self::STELLAR_ACCOUNT, 'assets' => ['XLM' => true, 'EURC' => true]]);

        self::assertSame(['XRPL', 'STELLAR'], $provider->enabledChains());
        self::assertSame('mainnet', $provider->getNetwork('STELLAR'));
        self::assertSame(self::STELLAR_ACCOUNT, $provider->getDestinationAccount('STELLAR'));
        self::assertTrue($provider->isAssetEnabled('STELLAR', 'XLM'));
        self::assertTrue($provider->isAssetEnabled('STELLAR', 'EURC'));
        self::assertFalse($provider->isAssetEnabled('STELLAR', 'USDC'), 'not listed means off');
        self::assertFalse($provider->isAssetEnabled('STELLAR', 'RLUSD'), 'an XRPL asset is not a Stellar one, whatever the config says');
    }

    public function testAnAccountOfTheOtherChainIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not a valid Stellar receiving account');

        $this->provider([], [], ['enabled' => true, 'destination_account' => self::ACCOUNT])->getDestinationAccount('STELLAR');
    }

    public function testAMissingStellarAccountNamesItsEnvVariable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('LEDGER_DIRECT_STELLAR_ACCOUNT');

        $this->provider([], [], ['enabled' => true, 'destination_account' => null])->getDestinationAccount('STELLAR');
    }

    /** A config file published by 0.1 has no `enabled` key and no Stellar block. */
    public function testAConfigFromVersionZeroOneKeepsXrplOnAndStellarOff(): void
    {
        $provider = new LaravelConfigProvider(new Repository(['ledger-direct' => [
            'chains' => ['xrpl' => ['network' => 'testnet', 'destination_account' => self::ACCOUNT, 'assets' => ['XRP' => true]]],
            'quote_expiry_seconds' => 300,
        ]]));

        self::assertSame(['XRPL'], $provider->enabledChains());
        self::assertTrue($provider->isAssetEnabled('XRPL', 'XRP'));
        self::assertFalse($provider->isChainEnabled('STELLAR'));
    }

    public function testXrplCanBeSwitchedOff(): void
    {
        $provider = $this->provider(['enabled' => false]);

        self::assertSame([], $provider->enabledChains());
        self::assertFalse($provider->isAssetEnabled('XRPL', 'XRP'));
    }

    /**
     * @param array<string, mixed> $xrpl
     * @param array<string, mixed> $root
     * @param array<string, mixed> $stellar
     */
    private function provider(array $xrpl = [], array $root = [], array $stellar = []): LaravelConfigProvider
    {
        $config = require __DIR__ . '/../../config/ledger-direct.php';
        $config['chains']['xrpl'] = array_replace($config['chains']['xrpl'], ['destination_account' => self::ACCOUNT], $xrpl);
        $config['chains']['stellar'] = array_replace($config['chains']['stellar'], $stellar);

        return new LaravelConfigProvider(new Repository(['ledger-direct' => array_replace($config, $root)]));
    }
}
