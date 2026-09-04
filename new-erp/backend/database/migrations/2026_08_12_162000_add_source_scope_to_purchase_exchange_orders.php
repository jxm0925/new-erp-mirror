<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_exchange_orders', function (Blueprint $table): void {
            $table->string('source_scope', 40)->default('receipt_inspection')->after('current_step')->index();
        });

    }

    public function down(): void
    {
        Schema::table('erp_purchase_exchange_orders', function (Blueprint $table): void {
            $table->dropIndex(['source_scope']);
            $table->dropColumn('source_scope');
        });
    }
};

