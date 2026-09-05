<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_inventory_transactions', function (Blueprint $table): void {
            $table->string('transaction_type', 80)->change();
        });
        Schema::table('erp_inventory_posting_logs', function (Blueprint $table): void {
            $table->string('transaction_type', 80)->change();
        });
    }

    public function down(): void
    {
        Schema::table('erp_inventory_posting_logs', function (Blueprint $table): void {
            $table->string('transaction_type', 40)->change();
        });
        Schema::table('erp_inventory_transactions', function (Blueprint $table): void {
            $table->string('transaction_type', 40)->change();
        });
    }
};
