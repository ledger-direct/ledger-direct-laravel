<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Chains
    |--------------------------------------------------------------------------
    |
    | One block per chain the core knows (Core\ChainCatalog). A chain takes
    | payments only while it is enabled; the XRP Ledger is enabled by default,
    | so an installation from 0.1 keeps working, Stellar is opt-in.
    |
    | There are no issuer addresses here, on purpose: which issuer a USDC,
    | RLUSD or EURC payment has to come from is fixed in the core's registries
    | and never configurable. A wrong issuer means lost funds.
    |
    */

    'chains' => [
        'xrpl' => [
            'enabled' => (bool) env('LEDGER_DIRECT_XRPL_ENABLED', true),

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

        'stellar' => [
            'enabled' => (bool) env('LEDGER_DIRECT_STELLAR_ENABLED', false),

            // mainnet | testnet
            'network' => env('LEDGER_DIRECT_STELLAR_NETWORK', 'testnet'),

            // Your receiving account, a public key starting with "G".
            'destination_account' => env('LEDGER_DIRECT_STELLAR_ACCOUNT'),

            // Which assets you accept. Stablecoins are opt-in, and only offered
            // while the account has a trust line for them (Checkout\PaymentOptions).
            'assets' => [
                'XLM' => true,
                'USDC' => false,
                'EURC' => false,
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
