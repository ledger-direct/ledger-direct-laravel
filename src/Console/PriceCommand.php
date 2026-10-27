<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Console;

use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Diagnosis: asks the core for the current rate and what 1.00 of the quote
 * currency costs in the asset — the answer a checkout would get right now.
 * Whether the asset is enabled does not matter here; this checks the
 * oracles, not the merchant's settings.
 */
final class PriceCommand extends Command
{
    /** @var string */
    protected $signature = 'ledger-direct:price
        {asset : The asset to price, e.g. XRP, RLUSD or USDC}
        {currency : The quote currency, e.g. EUR or USD}';

    /** @var string */
    protected $description = 'Show the current exchange rate the core would quote for an asset';

    public function handle(LedgerDirect $ledgerDirect): int
    {
        $asset = strtoupper((string) $this->argument('asset'));
        $currency = strtoupper((string) $this->argument('currency'));

        try {
            $network = $ledgerDirect->configProvider()->getNetwork('XRPL');
            $quote = $ledgerDirect->priceService()->getCryptoPriceForOrder(1.0, $currency, $asset, $network);
        } catch (LedgerDirectException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->components->error(sprintf('Cannot price %s in %s: %s', $asset, $currency, $exception->getMessage()));

            return self::INVALID;
        }

        $amount = is_array($quote->amountRequested) ? $quote->amountRequested['value'] : $quote->amountRequested;

        $this->components->twoColumnDetail('Pair', $quote->pairing);
        $this->components->twoColumnDetail('Network', $network);
        $this->components->twoColumnDetail('Rate', sprintf('1 %s = %s %s', $quote->baseAsset, self::plain($quote->exchangeRate), $quote->quoteCurrency));
        $this->components->twoColumnDetail('Amount for 1.00 ' . $quote->quoteCurrency, sprintf('%s %s', is_float($amount) ? self::plain($amount) : $amount, $quote->baseAsset));

        return self::SUCCESS;
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
    }
}
