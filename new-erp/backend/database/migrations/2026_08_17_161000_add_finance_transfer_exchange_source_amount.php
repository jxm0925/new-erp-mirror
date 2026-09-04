<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_finance_account_transfers', function (Blueprint $table): void {
            // source_amount is the actual debit of the source account.  When
            // a source-borne fee applies, only this amount participates in FX.
            $table->decimal('exchange_source_amount', 18, 4)->nullable()->after('source_amount');
        });
    }

    public function down(): void
    {
        Schema::table('erp_finance_account_transfers', function (Blueprint $table): void {
            $table->dropColumn('exchange_source_amount');
        });
    }
};
