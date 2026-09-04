<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('erp_sales_orders', 'customer_kind')) {
            Schema::table('erp_sales_orders', function (Blueprint $table) {
                $table->string('customer_kind', 20)->nullable()->after('customer_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_sales_orders', 'customer_kind')) {
            Schema::table('erp_sales_orders', function (Blueprint $table) {
                $table->dropColumn('customer_kind');
            });
        }
    }
};
