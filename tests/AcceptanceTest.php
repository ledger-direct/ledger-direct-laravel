<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Stellar\MemoIdService;
use Hardcastle\LedgerDirect\Core\Stellar\Schema as StellarSchema;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirectStellar;
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

    public function testTwoStellarQuotesGetTwoMemoIdsFromConsecutiveSequences(): void
    {
        $this->createTables();
        $this->enableStellar();
        DB::table(StellarSchema::TABLE_MEMO)->insert(['destination_account' => self::STELLAR_ACCOUNT, 'sequence' => 2_000_000]);

        $http = $this->fakeHttp();
        foreach ([1, 2] as $ignored) {
            $http->queueResponse('api.coingecko.com', new Response(200, [], '{"stellar":{"eur":0.25}}'));
            $http->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.25"}'));
            $http->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXLMZEUR":{"c":["0.25","100"]}}}'));
        }

        $first = LedgerDirectStellar::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XLM');
        $second = LedgerDirectStellar::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XLM');

        self::assertSame('STELLAR', $first->chain);
        self::assertSame('stellar-xlm-payment', $first->type);
        self::assertSame(40.0, $first->amountRequested);
        self::assertSame(self::STELLAR_ACCOUNT, $first->destinationAccount);

        // Memo ids come from the same permutation as destination tags.
        $reference = new InMemoryStellarPaymentRepository();
        $reference->scriptSequences(self::STELLAR_ACCOUNT, [2_000_001, 2_000_002]);
        $memoIds = new MemoIdService($reference);

        self::assertSame($memoIds->generateMemoId(self::STELLAR_ACCOUNT), $first->destinationTag);
        self::assertSame($memoIds->generateMemoId(self::STELLAR_ACCOUNT), $second->destinationTag);
        self::assertSame(2_000_002, (int) DB::table(StellarSchema::TABLE_MEMO)->value('sequence'));
    }
}
