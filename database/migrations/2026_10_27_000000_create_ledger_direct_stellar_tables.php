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
     * The Stellar tables, from the core (Stellar\Schema::tables()). Created
     * whether or not Stellar is enabled: empty tables cost nothing, and
     * enabling the chain later needs no further migration.
     */
    public function up(): void
    {
        LedgerDirectTables::createStellar(Schema::connection($this->getConnection()));
    }

    /**
     * Drops only ledger_direct_stellar_payment, which the next sync rebuilds
     * from Horizon. ledger_direct_stellar_memo stays, deliberately: it is the
     * only record of which memo ids were already issued, and a fresh counter
     * would hand out ids that open orders are still waiting on.
     */
    public function down(): void
    {
        LedgerDirectTables::dropStellarReSyncable(Schema::connection($this->getConnection()));
    }
};
