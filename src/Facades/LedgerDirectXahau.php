<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Facades;

use Hardcastle\LedgerDirect\Laravel\LedgerDirectServiceProvider;
use Illuminate\Support\Facades\Facade;

/**
 * The core's composition root for Xahau, resolved from the container: a
 * second Core\LedgerDirect, created for the chain XAHAU, with its own tables.
 *
 * @method static \Psr\Clock\ClockInterface clock()
 * @method static \Psr\Log\LoggerInterface logger()
 * @method static \Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface configProvider()
 * @method static \Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface transactionRepository()
 * @method static \Hardcastle\LedgerDirect\Core\Xrpl\XrplClient xrplClient()
 * @method static \Hardcastle\LedgerDirect\Core\Price\PriceService priceService()
 * @method static \Hardcastle\LedgerDirect\Core\Xrpl\SyncService syncService()
 * @method static \Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService destinationTagService()
 * @method static \Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService paymentIntentService()
 * @method static \Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy settlementPolicy()
 * @method static \Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle|null syncThrottle()
 * @method static \Hardcastle\LedgerDirect\Core\Payment\PaymentStatus paymentStatus(\Hardcastle\LedgerDirect\Core\Payment\PaymentIntent $intent)
 *
 * @see \Hardcastle\LedgerDirect\Core\LedgerDirect
 */
final class LedgerDirectXahau extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerDirectServiceProvider::XAHAU_ROOT;
    }
}
