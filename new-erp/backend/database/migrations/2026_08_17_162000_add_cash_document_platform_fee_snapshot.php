<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            // The cash document retains its gross business amount. Platform
            // charges are independent facts and reduce the account via a
            // separate immutable movement at confirmation.
            $table->decimal('platform_fee_amount', 18, 4)->default(0)->after('amount');
            $table->string('platform_fee_currency', 10)->nullable()->after('platform_fee_amount');
            $table->foreignId('platform_fee_account_id')->nullable()->after('platform_fee_currency')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->decimal('platform_fee_base_amount', 18, 4)->default(0)->after('platform_fee_account_id');
            $table->string('platform_fee_type', 30)->nullable()->after('platform_fee_base_amount');
        });

    }

    public function down(): void
    {
        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            $table->dropForeign(['platform_fee_account_id']);
            $table->dropColumn(['platform_fee_amount', 'platform_fee_currency', 'platform_fee_account_id', 'platform_fee_base_amount', 'platform_fee_type']);
        });
    }
};

