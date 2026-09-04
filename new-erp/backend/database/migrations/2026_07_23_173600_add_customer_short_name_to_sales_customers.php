<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('erp_sales_customers') || Schema::hasColumn('erp_sales_customers', 'customer_short_name')) {
            return;
        }

        Schema::table('erp_sales_customers', function (Blueprint $table) {
            $table->string('customer_short_name', 160)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('erp_sales_customers') && Schema::hasColumn('erp_sales_customers', 'customer_short_name')) {
            Schema::table('erp_sales_customers', function (Blueprint $table) {
                $table->dropColumn('customer_short_name');
            });
        }
    }
};
