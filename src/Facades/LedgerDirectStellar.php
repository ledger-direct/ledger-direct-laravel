<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Facades;

use Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar as Core;
use Illuminate\Support\Facades\Facade;

/**
 * The core's Stellar composition root, resolved from the container.
 *
 * @method static \Psr\Clock\ClockInterface clock()
 * @method static \Psr\Log\LoggerInterface logger()
 * @method static \Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface configProvider()
 * @method static \Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface paymentRepository()
 * @method static \Hardcastle\LedgerDirect\Core\Stellar\HorizonClient horizonClient()
 * @method static \Hardcastle\LedgerDirect\Core\Stellar\AccountInspector accountInspector()
 * @method static \Hardcastle\LedgerDirect\Core\Price\PriceService priceService()
 * @method static \Hardcastle\LedgerDirect\Core\Stellar\SyncService syncService()
 * @method static \Hardcastle\LedgerDirect\Core\Stellar\MemoIdService memoIdService()
 * @method static \Hardcastle\LedgerDirect\Core\Stellar\PaymentIntentService paymentIntentService()
 * @method static \Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy settlementPolicy()
 * @method static \Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle|null syncThrottle()
 * @method static \Hardcastle\LedgerDirect\Core\Payment\PaymentStatus paymentStatus(\Hardcastle\LedgerDirect\Core\Payment\PaymentIntent $intent)
 *
 * @see Core
 */
final class LedgerDirectStellar extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Core::class;
    }
}
