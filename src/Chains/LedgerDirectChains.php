<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Chains;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri as XrplPaymentUri;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentUri as StellarPaymentUri;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Illuminate\Contracts\Container\Container;

/**
 * One composition root per chain — Core\LedgerDirect for the XRP Ledger,
 * Stellar\LedgerDirectStellar for Stellar — and the one place that picks
 * between them, by the chain an intent names. A status endpoint, a cron job
 * or a payment page asks this instead of branching on the chain itself.
 *
 * Both roots offer the same calls a status flow needs (syncService() with
 * sync() and findFulfillmentFor(), settlementPolicy(), syncThrottle(),
 * paymentStatus(), logger()), so callers can treat the result alike.
 *
 * The roots are container singletons, resolved on first use: an XRPL-only
 * application never builds the Stellar one.
 */
final class LedgerDirectChains
{
    public function __construct(
        private readonly Container $container,
        private readonly ConfigProviderInterface $config,
    ) {
    }

    public function forChain(string $chain): LedgerDirect|LedgerDirectStellar
    {
        return match ($chain) {
            ChainCatalog::XRPL => $this->container->make(LedgerDirect::class),
            ChainCatalog::STELLAR => $this->container->make(LedgerDirectStellar::class),
            default => throw new UnsupportedChainException(sprintf(
                'LedgerDirect: this version of the Laravel bridge has no composition root for the chain "%s".',
                $chain,
            )),
        };
    }

    public function forIntent(PaymentIntent $intent): LedgerDirect|LedgerDirectStellar
    {
        return $this->forChain($intent->chain);
    }

    /**
     * The chains that take payments, in catalog order. With a config provider
     * other than the bridge's, every chain whose native asset it accepts.
     *
     * @return list<string>
     */
    public function enabledChains(): array
    {
        if ($this->config instanceof LaravelConfigProvider) {
            return $this->config->enabledChains();
        }

        $chains = [];
        foreach (ChainCatalog::chains() as $descriptor) {
            if ($this->config->isAssetEnabled($descriptor->chain, $descriptor->nativeAsset)) {
                $chains[] = $descriptor->chain;
            }
        }

        return $chains;
    }

    /**
     * The payment request the QR code and wallet buttons carry: XRPL's
     * `https://xrplf.org//send?…` or Stellar's SEP-0007 `web+stellar:pay?…`.
     *
     * @param string $amountDue the amount still to send, as the page shows it
     */
    public function paymentUri(PaymentIntent $intent, string $amountDue): string
    {
        return match ($intent->chain) {
            ChainCatalog::XRPL => XrplPaymentUri::forIntent($intent, $amountDue),
            ChainCatalog::STELLAR => StellarPaymentUri::forIntent($intent, $amountDue),
            default => throw new UnsupportedChainException(sprintf(
                'LedgerDirect: no payment request format for the chain "%s".',
                $intent->chain,
            )),
        };
    }
}
