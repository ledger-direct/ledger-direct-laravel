<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Checkout;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Psr\Log\LoggerInterface;

/**
 * The payment options a checkout can offer now, one per (chain, asset), in
 * catalog order: enabled chains, enabled assets, and — for an issued asset
 * on Stellar — only if the receiving account can take it.
 *
 * A Stellar payment to an account without a trust line for the asset fails
 * with op_no_trust and never arrives (INVARIANTS.md, "Stellar",
 * Preconditions); offering it would take an order that cannot be paid. The
 * check goes through the core's AccountInspector, cached over the bridge's
 * cache store. If Horizon cannot answer, the asset is offered and the
 * failure logged: a node hiccup must not make a payment method vanish.
 *
 * XRPL issued assets need a trust line too; the core has no inspector for
 * it yet, so they are offered whenever enabled, as in 0.1.
 *
 * A chain that is enabled but misconfigured (no account, an invalid one)
 * offers nothing and logs why, instead of failing the checkout page.
 *
 * Whether an option is priceable for a given order is still the quote's
 * question: PaymentIntentService::quoteForOrder() throws when no rate is
 * available.
 */
final class PaymentOptions
{
    public function __construct(
        private readonly LedgerDirectChains $chains,
        private readonly ConfigProviderInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<PaymentOption>
     */
    public function available(): array
    {
        $options = [];

        foreach ($this->chains->enabledChains() as $chain) {
            $descriptor = ChainCatalog::chain($chain);

            try {
                $network = $this->config->getNetwork($chain);
                $account = $this->config->getDestinationAccount($chain);
            } catch (LedgerDirectException $exception) {
                $this->logger->warning('LedgerDirect: chain enabled but not usable, no payment options offered for it', [
                    'chain' => $chain,
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            foreach ($descriptor->assets as $asset) {
                if (!$this->config->isAssetEnabled($chain, $asset->code)) {
                    continue;
                }

                $option = new PaymentOption($descriptor, $asset, $network, $account);

                if ($this->canReceive($option)) {
                    $options[] = $option;
                }
            }
        }

        return $options;
    }

    /**
     * The option for an intent `type`, if it is available now.
     */
    public function forType(string $type): ?PaymentOption
    {
        foreach ($this->available() as $option) {
            if ($option->type() === $type) {
                return $option;
            }
        }

        return null;
    }

    private function canReceive(PaymentOption $option): bool
    {
        $issuer = $option->asset->issuer($option->network);

        if ($option->chain->chain !== ChainCatalog::STELLAR || !$option->asset->requiresTrustline() || $issuer === null) {
            return true;
        }

        $root = $this->chains->forChain(ChainCatalog::STELLAR);
        assert($root instanceof LedgerDirectStellar);

        try {
            $canReceive = $root->accountInspector()->canReceive($option->account, $option->asset->code, $issuer, $option->network);
        } catch (LedgerDirectException $exception) {
            $this->logger->warning('LedgerDirect: could not check the Stellar trust line, offering the asset anyway', [
                'asset' => $option->asset->code,
                'account' => $option->account,
                'network' => $option->network,
                'exception' => $exception->getMessage(),
            ]);

            return true;
        }

        if (!$canReceive) {
            $this->logger->info('LedgerDirect: Stellar asset enabled but the receiving account has no usable trust line for it, not offered', [
                'asset' => $option->asset->code,
                'issuer' => $issuer,
                'account' => $option->account,
                'network' => $option->network,
            ]);
        }

        return $canReceive;
    }
}
