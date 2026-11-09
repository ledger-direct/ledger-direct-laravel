<?php

declare(strict_types=1);

use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledger-direct.connection');
    }

    /**
     * The Xahau tables, from the core (Xrpl\Schema::tables('XAHAU')). Created
     * whether or not Xahau is enabled: empty tables cost nothing, and
     * enabling the chain later needs no further migration.
     */
    public function up(): void
    {
        LedgerDirectTables::createXahau(Schema::connection($this->getConnection()));
    }

    /**
     * Drops only ledger_direct_xahau_tx, which the next sync rebuilds from the
     * ledger. ledger_direct_xahau_destination_tag stays, deliberately: it is
     * the only record of which destination tags were already issued, and a
     * fresh counter would hand out tags that open orders are still waiting on.
     */
    public function down(): void
    {
        LedgerDirectTables::dropXahauReSyncable(Schema::connection($this->getConnection()));
    }
};
