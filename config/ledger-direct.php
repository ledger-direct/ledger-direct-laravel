<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Chains
    |--------------------------------------------------------------------------
    |
    | One block per chain. Today that is the XRP Ledger; further chains are
    | added as further blocks next to it, without touching this one.
    |
    | There are no issuer addresses here, on purpose: which issuer an RLUSD or
    | USDC payment has to come from is fixed in the core's StablecoinRegistry
    | and never configurable. A wrong issuer means lost funds.
    |
    */

    'chains' => [
        'xrpl' => [
            // mainnet | testnet
            'network' => env('LEDGER_DIRECT_XRPL_NETWORK', 'testnet'),

            // Your receiving account, a classic address starting with "r".
            'destination_account' => env('LEDGER_DIRECT_XRPL_ACCOUNT'),

            // Which assets you accept. Stablecoins are opt-in.
            'assets' => [
                'XRP' => true,
                'RLUSD' => false,
                'USDC' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quote expiry
    |--------------------------------------------------------------------------
    |
    | How long a price quote stays valid, in seconds (60 to 3600).
    |
    */

    'quote_expiry_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Settlement tolerance for the native asset
    |--------------------------------------------------------------------------
    |
    | How far an XRP payment may fall short of the quote and still settle, as
    | a decimal string in [0, 1), e.g. '0.0015' for 0.15 %. null uses the
    | core's default. Stablecoins never have a tolerance.
    |
    */

    'native_asset_tolerance' => null,

    /*
    |--------------------------------------------------------------------------
    | Exchange rate cache
    |--------------------------------------------------------------------------
    |
    | Seconds an exchange rate is served from the cache before the oracles are
    | asked again. null uses the core's default.
    |
    */

    'rate_fresh_ttl_seconds' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | The store for the rate cache and the sync throttle. It has to outlive a
    | request: with the "array" store nothing is cached and the throttle never
    | engages. null uses your default store.
    |
    */

    'cache_store' => null,

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | null uses your default channel.
    |
    */

    'log_channel' => null,

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection holding the ledger_direct_* tables. null uses your default
    | connection. Your table prefix applies as usual.
    |
    */

    'connection' => null,

];
