<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_finance_cash_documents', function (Blueprint $table) {
            $table->json('draft_allocation_items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('erp_finance_cash_documents', function (Blueprint $table) {
            $table->dropColumn('draft_allocation_items');
        });
    }
};
