<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Console;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Diagnosis: asks the core for the current rate and what 1.00 of the quote
 * currency costs in the asset — the answer a checkout would get right now.
 * Whether the asset or its chain is enabled does not matter here; this
 * checks the oracles, not the merchant's settings.
 *
 * The chain is the one the catalog lists the asset under; for an asset on
 * several chains (USDC) the first, unless --chain names another. It only
 * decides which network's configuration is read — the rate is the same.
 */
final class PriceCommand extends Command
{
    /** @var string */
    protected $signature = 'ledger-direct:price
        {asset : The asset to price, e.g. XRP, XLM, RLUSD, USDC or EURC}
        {currency : The quote currency, e.g. EUR or USD}
        {--chain= : The chain to read the network from (xrpl, stellar); default: the first that lists the asset}';

    /** @var string */
    protected $description = 'Show the current exchange rate the core would quote for an asset';

    public function handle(LedgerDirectChains $chains): int
    {
        $asset = strtoupper((string) $this->argument('asset'));
        $currency = strtoupper((string) $this->argument('currency'));
        $chain = $this->chainFor($asset);

        if ($chain === null) {
            $this->components->error(sprintf('Cannot price %s in %s: no chain lists %s%s.', $asset, $currency, $asset, $this->chainHint()));

            return self::INVALID;
        }

        try {
            $root = $chains->forChain($chain);
            $network = $root->configProvider()->getNetwork($chain);
            $quote = $root->priceService()->quoteAmount(1.0, $currency, $asset, $network);
        } catch (LedgerDirectException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->components->error(sprintf('Cannot price %s in %s: %s', $asset, $currency, $exception->getMessage()));

            return self::INVALID;
        }

        $this->components->twoColumnDetail('Pair', $quote->pairing);
        $this->components->twoColumnDetail('Chain', ChainCatalog::chain($chain)->label);
        $this->components->twoColumnDetail('Network', $network);
        $this->components->twoColumnDetail('Rate', sprintf('1 %s = %s %s', $quote->baseAsset, self::plain((string) $quote->exchangeRate), $quote->quoteCurrency));
        $this->components->twoColumnDetail('Amount for 1.00 ' . $quote->quoteCurrency, sprintf('%s %s', self::plain($quote->amount), $quote->baseAsset));

        return self::SUCCESS;
    }

    private function chainFor(string $asset): ?string
    {
        $option = $this->option('chain');

        if (is_string($option) && $option !== '') {
            $chain = LaravelConfigProvider::coreChainName($option);

            return $chain !== null && ChainCatalog::chain($chain)->hasAsset($asset) ? $chain : null;
        }

        foreach (ChainCatalog::chains() as $descriptor) {
            if ($descriptor->hasAsset($asset)) {
                return $descriptor->chain;
            }
        }

        return null;
    }

    private function chainHint(): string
    {
        $option = $this->option('chain');

        return is_string($option) && $option !== '' ? " on the chain \"{$option}\"" : '';
    }

    /**
     * A decimal without exponent or trailing zeros: "0.84757", "2", "1.17984333".
     * BigDecimal reads exponent form ("4.0E-13", how PHP prints a small float)
     * exactly, so no fixed number of places can round a rate to zero.
     */
    private static function plain(string $decimal): string
    {
        return PaymentIntent::plainDecimal(BigDecimal::of($decimal));
    }
}
