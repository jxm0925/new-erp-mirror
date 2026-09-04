<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_skus', 'is_custom_sku')) $table->boolean('is_custom_sku')->default(false)->after('is_customizable');
            if (!Schema::hasColumn('erp_skus', 'base_sku_id')) $table->foreignId('base_sku_id')->nullable()->after('is_custom_sku')->constrained('erp_skus')->nullOnDelete();
            if (!Schema::hasColumn('erp_skus', 'custom_scope')) $table->string('custom_scope', 30)->default('none')->after('base_sku_id');
            if (!Schema::hasColumn('erp_skus', 'custom_source_type')) $table->string('custom_source_type', 30)->nullable()->after('custom_scope');
            if (!Schema::hasColumn('erp_skus', 'custom_source_id')) $table->unsignedBigInteger('custom_source_id')->nullable()->after('custom_source_type');
            if (!Schema::hasColumn('erp_skus', 'customer_id')) $table->unsignedBigInteger('customer_id')->nullable()->after('custom_source_id');
            if (!Schema::hasColumn('erp_skus', 'sales_order_id')) $table->unsignedBigInteger('sales_order_id')->nullable()->after('customer_id');
            if (!Schema::hasColumn('erp_skus', 'sales_order_line_id')) $table->unsignedBigInteger('sales_order_line_id')->nullable()->after('sales_order_id');
            if (!Schema::hasColumn('erp_skus', 'custom_status')) $table->string('custom_status', 30)->default('active')->after('sales_order_line_id');
            if (!Schema::hasColumn('erp_skus', 'custom_description')) $table->text('custom_description')->nullable()->after('custom_status');
        });

        Schema::table('erp_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_items', 'is_custom_item')) $table->boolean('is_custom_item')->default(false)->after('is_production_item');
            if (!Schema::hasColumn('erp_items', 'base_item_id')) $table->foreignId('base_item_id')->nullable()->after('is_custom_item')->constrained('erp_items')->nullOnDelete();
            if (!Schema::hasColumn('erp_items', 'custom_scope')) $table->string('custom_scope', 30)->default('none')->after('base_item_id');
            if (!Schema::hasColumn('erp_items', 'custom_source_type')) $table->string('custom_source_type', 30)->nullable()->after('custom_scope');
            if (!Schema::hasColumn('erp_items', 'custom_source_id')) $table->unsignedBigInteger('custom_source_id')->nullable()->after('custom_source_type');
            if (!Schema::hasColumn('erp_items', 'customer_id')) $table->unsignedBigInteger('customer_id')->nullable()->after('custom_source_id');
            if (!Schema::hasColumn('erp_items', 'sales_order_id')) $table->unsignedBigInteger('sales_order_id')->nullable()->after('customer_id');
            if (!Schema::hasColumn('erp_items', 'sales_order_line_id')) $table->unsignedBigInteger('sales_order_line_id')->nullable()->after('sales_order_id');
            if (!Schema::hasColumn('erp_items', 'design_version')) $table->string('design_version', 80)->nullable()->after('sales_order_line_id');
            if (!Schema::hasColumn('erp_items', 'custom_status')) $table->string('custom_status', 30)->default('active')->after('design_version');
            if (!Schema::hasColumn('erp_items', 'custom_description')) $table->text('custom_description')->nullable()->after('custom_status');
        });

        Schema::table('erp_sku_item_relations', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sku_item_relations', 'relation_context')) $table->string('relation_context', 30)->default('standard')->after('relation_type');
            if (!Schema::hasColumn('erp_sku_item_relations', 'base_relation_id')) $table->foreignId('base_relation_id')->nullable()->after('relation_context')->constrained('erp_sku_item_relations')->nullOnDelete();
            if (!Schema::hasColumn('erp_sku_item_relations', 'custom_source_type')) $table->string('custom_source_type', 30)->nullable()->after('base_relation_id');
            if (!Schema::hasColumn('erp_sku_item_relations', 'custom_source_id')) $table->unsignedBigInteger('custom_source_id')->nullable()->after('custom_source_type');
        });

        if (!Schema::hasTable('erp_customization_records')) {
            Schema::create('erp_customization_records', function (Blueprint $table) {
                $table->id();
                $table->string('customization_no', 80)->unique();
                $table->string('custom_type', 40)->default('light');
                $table->string('custom_scope', 30)->default('order');
                $table->foreignId('product_id')->nullable()->constrained('erp_products')->nullOnDelete();
                $table->foreignId('base_sku_id')->nullable()->constrained('erp_skus')->nullOnDelete();
                $table->foreignId('custom_sku_id')->nullable()->constrained('erp_skus')->nullOnDelete();
                $table->foreignId('base_item_id')->nullable()->constrained('erp_items')->nullOnDelete();
                $table->foreignId('custom_item_id')->nullable()->constrained('erp_items')->nullOnDelete();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('sales_order_id')->nullable();
                $table->unsignedBigInteger('sales_order_line_id')->nullable();
                $table->string('source_type', 40)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->json('requirements_json')->nullable();
                $table->json('drawing_files_json')->nullable();
                $table->text('process_notes')->nullable();
                $table->string('status', 30)->default('draft');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_customization_records');
    }
};
