<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->string('fulfillment_status', 30)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->string('fulfillment_status', 64)->default('pending')->change();
        });
    }
};

