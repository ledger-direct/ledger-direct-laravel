<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Checkout;

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;

/**
 * One way to pay a shop can offer right now: an asset on a chain, with the
 * network and receiving account the quote will use. `type` is the value
 * the intent will carry — the key a shop maps its payment method to.
 */
final readonly class PaymentOption
{
    public function __construct(
        public ChainDescriptor $chain,
        public AssetDescriptor $asset,
        public string $network,
        public string $account,
    ) {
    }

    /** The intent's `type`, e.g. 'xrp-payment' or 'stellar-usdc-payment'. */
    public function type(): string
    {
        return $this->asset->type;
    }

    /** What a checkout shows, e.g. "XLM (Stellar)" or "USDC (XRP Ledger)". */
    public function label(): string
    {
        return $this->asset->code . ' (' . $this->chain->label . ')';
    }
}
