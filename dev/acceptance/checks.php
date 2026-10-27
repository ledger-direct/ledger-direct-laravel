<?php

declare(strict_types=1);

// Runs inside the freshly created Laravel app (copied there by
// dev/acceptance.sh): boots it and checks one acceptance step. Prints
// "ok: …" lines; any failure ends with a non-zero exit code.

use Hardcastle\LedgerDirect\Core\Stellar\MemoIdService;
use Hardcastle\LedgerDirect\Core\Stellar\Schema as StellarSchema;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema as CoreSchema;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Hardcastle\LedgerDirect\Laravel\Checkout\PaymentOptions;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirectStellar;
use Hardcastle\LedgerDirect\Laravel\Status\StatusResponder;
use Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function check(bool $condition, string $what): void
{
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$what}\n");
        exit(1);
    }

    echo "ok: {$what}\n";
}

$account = (string) config('ledger-direct.chains.xrpl.destination_account');

switch ($argv[1] ?? '') {
    case 'tables':
        check(Schema::hasTable(CoreSchema::TABLE_TX), 'ledger_direct_xrpl_tx exists');
        check(Schema::hasTable(CoreSchema::TABLE_DESTINATION_TAG), 'ledger_direct_xrpl_destination_tag exists');
        check(Schema::hasColumn(CoreSchema::TABLE_TX, 'tx_ctid') && !Schema::hasColumn(CoreSchema::TABLE_TX, 'ctid'), 'the CTID column is tx_ctid');
        check(Schema::hasTable(StellarSchema::TABLE_PAYMENT), 'ledger_direct_stellar_payment exists');
        check(Schema::hasTable(StellarSchema::TABLE_MEMO), 'ledger_direct_stellar_memo exists');
        break;

    case 'stellar':
        $stellarAccount = (string) config('ledger-direct.chains.stellar.destination_account');
        $types = array_map(static fn ($o): string => $o->type(), app(PaymentOptions::class)->available());
        check(in_array('stellar-xlm-payment', $types, true), 'XLM on Stellar is offered (' . implode(', ', $types) . ')');

        $first = LedgerDirectStellar::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XLM');
        $second = LedgerDirectStellar::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XLM');
        $sequence = (int) DB::table(StellarSchema::TABLE_MEMO)->where('destination_account', $stellarAccount)->value('sequence');

        $reference = new InMemoryStellarPaymentRepository();
        $reference->scriptSequences($stellarAccount, [$sequence - 1, $sequence]);
        $memoIds = new MemoIdService($reference);

        check($first->chain === 'STELLAR' && $first->destinationAccount === $stellarAccount, 'the quote names Stellar and the configured account');
        check(is_float($first->amountRequested) && $first->amountRequested > 0, "10.00 EUR quoted as {$first->amountRequested} XLM");
        check(
            $memoIds->generateMemoId($stellarAccount) === $first->destinationTag
                && $memoIds->generateMemoId($stellarAccount) === $second->destinationTag,
            "two quotes, two memo ids ({$first->destinationTag}, {$second->destinationTag}) from consecutive sequences",
        );

        $uri = app(LedgerDirectChains::class)->paymentUri($first, (string) $first->amountRequested);
        check(str_starts_with($uri, 'web+stellar:pay?destination=' . $stellarAccount) && str_contains($uri, 'memo=' . $first->destinationTag), 'the payment request is SEP-0007 with the memo id');

        $store = new InMemoryPaymentIntentStore();
        $store->save('order-stellar', $first);
        $status = app(StatusResponder::class)->respondFor('order-stellar', $store)?->toArray() ?? [];
        check(($status['state'] ?? null) === 'waiting', 'a fresh Stellar quote is waiting');

        file_put_contents(__DIR__ . '/acceptance-memo-sequence.txt', (string) $sequence);
        break;

    case 'synced':
        $hashes = DB::table(CoreSchema::TABLE_TX)->where('destination', $account)->pluck('hash');
        check($hashes->count() > 0, "sync stored {$hashes->count()} transaction(s) for the faucet account");
        check($hashes->count() === $hashes->unique()->count(), 'no transaction stored twice');
        break;

    case 'quotes':
        $first = LedgerDirect::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XRP');
        $second = LedgerDirect::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XRP');
        $sequence = (int) DB::table(CoreSchema::TABLE_DESTINATION_TAG)->where('destination_account', $account)->value('sequence');

        // The tags are the core's permutation of two consecutive counter values.
        $reference = new InMemoryXrplTransactionRepository();
        $reference->scriptSequences($account, [$sequence - 1, $sequence]);
        $tags = new DestinationTagService($reference);

        check($first->destinationAccount === $account, 'the quote names the configured account');
        check(is_float($first->amountRequested) && $first->amountRequested > 0, "10.00 EUR quoted as {$first->amountRequested} XRP");
        check($first->destinationTag !== $second->destinationTag, "two quotes, two tags ({$first->destinationTag}, {$second->destinationTag})");
        check(
            $tags->generateDestinationTag($account) === $first->destinationTag
                && $tags->generateDestinationTag($account) === $second->destinationTag,
            "the tags come from consecutive sequences (" . ($sequence - 1) . ", {$sequence})",
        );

        $store = new InMemoryPaymentIntentStore();
        $store->save('order-1', $first);
        $status = app(StatusResponder::class)->respondFor('order-1', $store)?->toArray() ?? [];
        check(($status['state'] ?? null) === 'waiting' && ($status['seconds_left'] ?? 0) > 0, 'a fresh quote is waiting with a countdown');

        file_put_contents(__DIR__ . '/acceptance-sequence.txt', (string) $sequence);
        break;

    case 'after-rollback':
        $sequence = (int) file_get_contents(__DIR__ . '/acceptance-sequence.txt');
        check(!Schema::hasTable(CoreSchema::TABLE_TX), 'rollback dropped the transaction table');
        check(Schema::hasTable(CoreSchema::TABLE_DESTINATION_TAG), 'rollback kept the counter table');
        check((int) DB::table(CoreSchema::TABLE_DESTINATION_TAG)->where('destination_account', $account)->value('sequence') === $sequence, 'the counter kept its value');
        $memoSequence = (int) file_get_contents(__DIR__ . '/acceptance-memo-sequence.txt');
        check(!Schema::hasTable(StellarSchema::TABLE_PAYMENT), 'rollback dropped the Stellar payment table');
        check((int) DB::table(StellarSchema::TABLE_MEMO)->value('sequence') === $memoSequence, 'the memo counter kept its value');
        break;

    case 'after-remigrate':
        $sequence = (int) file_get_contents(__DIR__ . '/acceptance-sequence.txt');
        check(Schema::hasTable(CoreSchema::TABLE_TX), 'migrate rebuilt the transaction table');
        check(Schema::hasTable(StellarSchema::TABLE_PAYMENT), 'migrate rebuilt the Stellar payment table');
        $next = LedgerDirect::paymentIntentService()->quoteForOrder(10.0, 'EUR', 'XRP');
        check((int) DB::table(CoreSchema::TABLE_DESTINATION_TAG)->where('destination_account', $account)->value('sequence') === $sequence + 1, "the next quote continues the counter (tag {$next->destinationTag})");
        break;

    default:
        fwrite(STDERR, "Unknown step '" . ($argv[1] ?? '') . "'.\n");
        exit(2);
}
