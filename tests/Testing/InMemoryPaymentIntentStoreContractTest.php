<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Testing;

use Hardcastle\LedgerDirect\Laravel\Contracts\PaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Testing\PaymentIntentStoreContractTestCase;

/** The shipped in-memory store keeps the contract — which keeps the contract test honest. */
final class InMemoryPaymentIntentStoreContractTest extends PaymentIntentStoreContractTestCase
{
    protected function store(): PaymentIntentStore
    {
        return new InMemoryPaymentIntentStore();
    }
}
