<?php

declare(strict_types=1);

// Worker for the multi-process counter tests: draws N sequences for one
// account and prints one per line. The first argument is a table prefix for
// the configured MySQL/PostgreSQL server, or "sqlite:<journal mode>:<path>"
// for a SQLite file. A failed draw prints "ERROR <message>" and carries on.

use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;

require __DIR__ . '/../../vendor/autoload.php';

[, $prefix, $account, $count] = $argv;

$connection = str_starts_with($prefix, 'sqlite:')
    ? Databases::sqliteFile(explode(':', $prefix, 3)[2], explode(':', $prefix, 3)[1] ?: null)
    : (Databases::server($prefix) ?? throw new RuntimeException('No database server configured.'));
$repository = new DatabaseXrplTransactionRepository($connection);

for ($i = 0; $i < (int) $count; $i++) {
    try {
        echo $repository->nextDestinationTagSequence($account), "\n";
    } catch (Throwable $exception) {
        echo 'ERROR ', str_replace("\n", ' ', $exception->getMessage()), "\n";
    }
}
