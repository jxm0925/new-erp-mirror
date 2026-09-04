<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_orders', 'created_by_legacy_id')) {
                $table->unsignedBigInteger('created_by_legacy_id')->nullable()->after('created_by')->index();
            }
            if (!Schema::hasColumn('erp_sales_orders', 'sales_user_legacy_id')) {
                $table->unsignedBigInteger('sales_user_legacy_id')->nullable()->after('created_by_legacy_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            foreach (['created_by_legacy_id', 'sales_user_legacy_id'] as $column) {
                if (Schema::hasColumn('erp_sales_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
