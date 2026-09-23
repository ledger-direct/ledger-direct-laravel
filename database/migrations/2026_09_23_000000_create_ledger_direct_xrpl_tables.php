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
     * The columns and indexes come from the core (Xrpl\Schema::tables()),
     * so this file does not have to be edited to follow them.
     */
    public function up(): void
    {
        LedgerDirectTables::create(Schema::connection($this->getConnection()));
    }

    /**
     * Drops only ledger_direct_xrpl_tx, which the next sync rebuilds from the
     * ledger. ledger_direct_xrpl_destination_tag stays, deliberately: it is
     * the only record of which destination tags were already issued, and a
     * fresh counter would hand out tags that open orders are still waiting
     * on — a payment for one order would then settle another. Drop it by
     * hand only if no order is waiting for a payment, ever again.
     */
    public function down(): void
    {
        LedgerDirectTables::dropReSyncable(Schema::connection($this->getConnection()));
    }
};
