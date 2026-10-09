<?php

declare(strict_types=1);

// Worker for the MySQL and PostgreSQL repository tests: draws N sequences
// for one account on the configured server and prints one per line.

use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;

require __DIR__ . '/../../vendor/autoload.php';

[, $prefix, $account, $count] = $argv;

$repository = new DatabaseXrplTransactionRepository(Databases::server($prefix) ?? throw new RuntimeException('No database server configured.'));

for ($i = 0; $i < (int) $count; $i++) {
    echo $repository->nextDestinationTagSequence($account), "\n";
}
