<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect;
use Illuminate\Support\Facades\DB;

/**
 * The handover's acceptance line, through the facade and the real
 * repository: a quote gets a destination tag, and two quotes draw two
 * consecutive counter values.
 */
final class AcceptanceTest extends TestCase
{
    public function testTwoQuotesGetTwoTagsFromConsecutiveSequences(): void
    {
        $this->createTables();
        DB::table(Schema::TABLE_DESTINATION_TAG)->insert(['destination_account' => self::ACCOUNT, 'sequence' => 1_000_000]);

        $http = $this->fakeHttp();
        foreach ([1, 2] as $ignored) {
            $http->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"eur":0.5}}'));
            $http->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.5"}'));
            $http->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZEUR":{"c":["0.5","100"]}}}'));
        }

        $first = LedgerDirect::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XRP');
        $second = LedgerDirect::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XRP');

        self::assertSame(20.0, $first->amountRequested);
        self::assertSame(self::ACCOUNT, $first->destinationAccount);
        self::assertNotSame($first->destinationTag, $second->destinationTag);

        // The tags are the core's permutation of 1_000_001 and 1_000_002.
        $reference = new InMemoryXrplTransactionRepository();
        $reference->scriptSequences(self::ACCOUNT, [1_000_001, 1_000_002]);
        $tags = new DestinationTagService($reference);

        self::assertSame($tags->generateDestinationTag(self::ACCOUNT), $first->destinationTag);
        self::assertSame($tags->generateDestinationTag(self::ACCOUNT), $second->destinationTag);
        self::assertSame(1_000_002, (int) DB::table(Schema::TABLE_DESTINATION_TAG)->value('sequence'));
    }
}
