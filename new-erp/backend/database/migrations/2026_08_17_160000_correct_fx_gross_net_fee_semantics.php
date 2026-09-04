<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_finance_account_transfers', function (Blueprint $table): void {
            $table->decimal('gross_target_amount', 18, 4)->nullable()->after('target_amount');
            $table->decimal('net_target_amount', 18, 4)->nullable()->after('gross_target_amount');
            $table->string('fee_bearer', 20)->default('source')->after('fee_currency'); // source / target / third_account
            $table->foreignId('fee_account_id')->nullable()->after('fee_bearer')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->decimal('reference_exchange_rate', 24, 10)->nullable()->after('actual_exchange_rate');
            $table->decimal('reference_difference_amount', 18, 4)->default(0)->after('reference_exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('erp_finance_account_transfers', function (Blueprint $table): void {
            $table->dropForeign(['fee_account_id']);
            $table->dropColumn(['gross_target_amount', 'net_target_amount', 'fee_bearer', 'fee_account_id', 'reference_exchange_rate', 'reference_difference_amount']);
        });
    }
};
