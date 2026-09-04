<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Server-side SKU search is used by the sales-order picker.  These indexes
     * keep the enabled/saleable subset and Product lookup on the MySQL side;
     * pagination is never replaced with a client-side full list.
     */
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table): void {
            $table->index(['status', 'is_sale_item', 'sku_code'], 'erp_skus_sales_search_code_idx');
            $table->index(['status', 'is_sale_item', 'product_id'], 'erp_skus_sales_search_product_idx');
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->index(['status', 'product_name'], 'erp_products_sales_search_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_skus', function (Blueprint $table): void {
            $table->dropIndex('erp_skus_sales_search_code_idx');
            $table->dropIndex('erp_skus_sales_search_product_idx');
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->dropIndex('erp_products_sales_search_name_idx');
        });
    }
};
