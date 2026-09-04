<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_order_items', 'allow_actual_conversion_snapshot')) {
                $table->boolean('allow_actual_conversion_snapshot')->default(false)->after('conversion_factor_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('erp_purchase_order_items', 'allow_actual_conversion_snapshot')) $table->dropColumn('allow_actual_conversion_snapshot');
        });
    }
};
