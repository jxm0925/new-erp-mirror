<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_code', 40)->unique();
            $table->string('unit_name', 80);
            $table->string('unit_type', 40)->default('quantity');
            $table->unsignedTinyInteger('decimal_places')->default(0);
            $table->boolean('is_base')->default(false);
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_code', 60)->unique();
            $table->string('category_name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('erp_item_categories')->nullOnDelete();
            $table->string('category_type', 30)->default('item');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 60)->unique();
            $table->string('supplier_name', 160);
            $table->string('short_name', 100)->nullable();
            $table->string('supplier_type', 40)->default('manufacturer');
            $table->string('contact_name', 80)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('default_tax_rate', 8, 4)->nullable();
            $table->string('settlement_method', 80)->nullable();
            $table->string('payment_method', 80)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account', 100)->nullable();
            $table->string('level', 20)->nullable();
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_code', 60)->unique();
            $table->string('warehouse_name', 120);
            $table->string('warehouse_type', 40)->default('general');
            $table->string('manager', 80)->nullable();
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_locations', function (Blueprint $table) {
            $table->id();
            $table->string('location_code', 80)->unique();
            $table->string('location_name', 120);
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('erp_locations')->nullOnDelete();
            $table->string('area', 60)->nullable();
            $table->string('aisle', 60)->nullable();
            $table->string('rack', 60)->nullable();
            $table->string('level', 60)->nullable();
            $table->decimal('standard_capacity', 14, 4)->nullable();
            $table->boolean('allow_mixed')->default(false);
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code', 60)->unique();
            $table->string('product_name', 160);
            $table->string('product_type', 40)->default('standard');
            $table->foreignId('category_id')->nullable()->constrained('erp_item_categories')->nullOnDelete();
            $table->string('model', 100)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('erp_products')->restrictOnDelete();
            $table->string('sku_code', 80)->unique();
            $table->string('sku_name', 160);
            $table->string('spec_text', 255)->nullable();
            $table->decimal('sale_price', 14, 2)->default(0);
            $table->decimal('reference_cost', 14, 2)->default(0);
            $table->string('product_structure_type', 40)->default('single');
            $table->string('production_policy', 40)->default('stock');
            $table->string('fulfillment_type', 40)->default('physical');
            $table->boolean('is_customizable')->default(false);
            $table->boolean('is_need_production')->default(false);
            $table->boolean('is_need_bom')->default(false);
            $table->boolean('is_sale_item')->default(true);
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 80)->unique();
            $table->string('item_name', 160);
            $table->string('item_type', 40)->default('raw_material');
            $table->foreignId('category_id')->nullable()->constrained('erp_item_categories')->nullOnDelete();
            $table->string('spec', 255)->nullable();
            $table->foreignId('unit_id')->constrained('erp_units')->restrictOnDelete();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->boolean('is_purchase_item')->default(false);
            $table->boolean('is_stock_item')->default(true);
            $table->boolean('is_production_item')->default(false);
            $table->boolean('is_batch_managed')->default(false);
            $table->boolean('is_serial_managed')->default(false);
            $table->string('cost_method', 40)->default('weighted_average');
            $table->decimal('standard_cost', 14, 4)->default(0);
            $table->decimal('last_purchase_price', 14, 4)->default(0);
            $table->foreignId('default_supplier_id')->nullable()->constrained('erp_suppliers')->nullOnDelete();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sku_item_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained('erp_skus')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('erp_items')->restrictOnDelete();
            $table->string('relation_type', 40)->default('finished_product');
            $table->decimal('qty', 14, 4)->default(1);
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->boolean('is_bundle_item')->default(false);
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['sku_id', 'item_id', 'relation_type'], 'erp_sku_item_relation_unique');
        });

        Schema::create('erp_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_unit_id')->constrained('erp_units')->cascadeOnDelete();
            $table->foreignId('target_unit_id')->constrained('erp_units')->cascadeOnDelete();
            $table->decimal('ratio', 18, 8);
            $table->string('formula', 160)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(4);
            $table->string('status', 20)->default('enabled');
            $table->timestamps();
            $table->unique(['source_unit_id', 'target_unit_id']);
        });

        Schema::create('erp_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_no', 80)->unique();
            $table->string('import_type', 60);
            $table->string('file_name', 255);
            $table->string('stored_path')->nullable();
            $table->string('status', 30)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('erp_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->json('raw_data');
            $table->json('normalized_data')->nullable();
            $table->string('validation_status', 30)->default('pending');
            $table->string('error_field', 100)->nullable();
            $table->string('error_type', 80)->nullable();
            $table->text('error_reason')->nullable();
            $table->text('suggestion')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'row_no']);
        });
    }

    public function down(): void
    {
        foreach ([
            'erp_import_rows', 'erp_import_batches', 'erp_unit_conversions',
            'erp_sku_item_relations', 'erp_items', 'erp_skus', 'erp_products', 'erp_locations',
            'erp_warehouses', 'erp_suppliers', 'erp_item_categories', 'erp_units',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
