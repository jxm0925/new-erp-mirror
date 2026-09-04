<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_customers', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_customers', 'customer_kind')) $table->string('customer_kind', 20)->default('individual')->after('customer_type')->index();
            if (!Schema::hasColumn('erp_sales_customers', 'source_platform')) $table->string('source_platform', 160)->nullable()->after('customer_kind');
            if (!Schema::hasColumn('erp_sales_customers', 'platform_buyer_id')) $table->string('platform_buyer_id', 160)->nullable()->after('source_platform');
            if (!Schema::hasColumn('erp_sales_customers', 'platform_identity_key')) $table->string('platform_identity_key', 64)->nullable()->after('platform_buyer_id')->unique();
            if (!Schema::hasColumn('erp_sales_customers', 'dedupe_name_key')) $table->string('dedupe_name_key', 191)->nullable()->after('platform_identity_key')->index();
            if (!Schema::hasColumn('erp_sales_customers', 'dedupe_address_key')) $table->string('dedupe_address_key', 191)->nullable()->after('dedupe_name_key')->index();
        });
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_orders', 'platform_buyer_id')) $table->string('platform_buyer_id', 160)->nullable()->after('platform2')->index();
        });

    }

    public function down(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('erp_sales_orders', 'platform_buyer_id')) $table->dropColumn('platform_buyer_id');
        });
        Schema::table('erp_sales_customers', function (Blueprint $table) {
            foreach (['customer_kind', 'source_platform', 'platform_buyer_id', 'platform_identity_key', 'dedupe_name_key', 'dedupe_address_key'] as $column) {
                if (Schema::hasColumn('erp_sales_customers', $column)) $table->dropColumn($column);
            }
        });
    }

};

