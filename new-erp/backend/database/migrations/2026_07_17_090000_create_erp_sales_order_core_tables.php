<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('sales_order_no', 80)->unique();
            $table->string('origin_order_no', 120)->nullable();
            $table->string('legacy_order_no', 120)->nullable()->index();
            $table->unsignedBigInteger('legacy_order_id')->nullable()->index();
            $table->string('trade_type', 40)->nullable();
            $table->string('order_source', 60)->default('manual');
            $table->string('platform', 80)->nullable();
            $table->string('platform2', 80)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 160)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('contact_name', 80)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('country_id', 40)->nullable();
            $table->string('province_id', 40)->nullable();
            $table->string('city_id', 40)->nullable();
            $table->string('area_id', 40)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('full_address')->nullable();
            $table->dateTime('order_time')->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->boolean('is_customized')->default(false);
            $table->boolean('is_special_customized')->default(false);
            $table->boolean('is_delay')->default(false);
            $table->date('delay_date')->nullable();
            $table->boolean('need_pump')->default(false);
            $table->string('electric', 80)->nullable();
            $table->json('order_flags')->nullable();
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cost_amount', 14, 2)->default(0);
            $table->decimal('freight_amount', 14, 2)->default(0);
            $table->string('currency', 20)->default('CNY');
            $table->string('order_status', 30)->default('draft');
            $table->string('confirm_status', 30)->default('unconfirmed');
            $table->string('fulfillment_status', 30)->default('unmatched');
            $table->string('production_confirm_status', 30)->default('not_required');
            $table->string('shipment_status', 30)->default('not_shipped');
            $table->string('change_status', 30)->default('none');
            $table->boolean('is_printable')->default(false);
            $table->boolean('is_invoice')->default(false);
            $table->unsignedBigInteger('legacy_clue_id')->nullable()->index();
            $table->string('legacy_clue_cop_id', 80)->nullable();
            $table->string('legacy_clue_source', 120)->nullable();
            $table->json('crm_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('shipping_snapshot')->nullable();
            $table->json('logistics_snapshot')->nullable();
            $table->json('legacy_payload')->nullable();
            $table->text('contract_attachments')->nullable();
            $table->text('remark')->nullable();
            $table->string('created_by', 80)->nullable();
            $table->string('confirmed_by', 80)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('cancelled_by', 80)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('erp_sales_orders')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->unsignedBigInteger('legacy_order_product_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_goods_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_sku_id')->nullable()->index();
            $table->foreignId('product_id')->nullable()->constrained('erp_products')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('erp_skus')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('erp_items')->nullOnDelete();
            $table->string('product_name', 160)->nullable();
            $table->string('sku_name', 160)->nullable();
            $table->string('item_name', 160)->nullable();
            $table->string('legacy_goods_type', 30)->nullable();
            $table->string('line_type', 40)->default('physical');
            $table->decimal('order_qty', 14, 4);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('inventory_fulfilled_qty', 14, 4)->default(0);
            $table->decimal('production_required_qty', 14, 4)->default(0);
            $table->decimal('service_fulfilled_qty', 14, 4)->default(0);
            $table->decimal('no_delivery_qty', 14, 4)->default(0);
            $table->decimal('shipped_qty', 14, 4)->default(0);
            $table->decimal('cancelled_qty', 14, 4)->default(0);
            $table->string('fulfillment_type', 40)->default('pending');
            $table->string('line_status', 30)->default('open');
            $table->boolean('need_pump')->default(false);
            $table->string('electric', 80)->nullable();
            $table->boolean('is_customized')->default(false);
            $table->boolean('is_special_customized')->default(false);
            $table->json('configuration_snapshot')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->json('sku_snapshot')->nullable();
            $table->json('item_snapshot')->nullable();
            $table->json('bom_snapshot')->nullable();
            $table->json('routing_snapshot')->nullable();
            $table->json('drawing_snapshot')->nullable();
            $table->json('technical_attachment_snapshot')->nullable();
            $table->json('inspection_snapshot')->nullable();
            $table->text('image')->nullable();
            $table->text('design')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['sales_order_id', 'line_status']);
        });

        Schema::create('erp_sales_order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->string('fulfillment_type', 40);
            $table->decimal('fulfillment_qty', 14, 4);
            $table->foreignId('item_id')->nullable()->constrained('erp_items')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('erp_locations')->nullOnDelete();
            $table->string('batch_no', 80)->nullable();
            $table->unsignedBigInteger('inventory_balance_id')->nullable();
            $table->string('reservation_status', 30)->default('pending');
            $table->string('production_requirement_status', 30)->default('not_required');
            $table->json('match_snapshot')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->foreign('sales_order_id', 'erp_so_fulfill_order_fk')->references('id')->on('erp_sales_orders')->cascadeOnDelete();
            $table->foreign('sales_order_line_id', 'erp_so_fulfill_line_fk')->references('id')->on('erp_sales_order_lines')->cascadeOnDelete();
            $table->index(['sales_order_id', 'fulfillment_type'], 'erp_so_fulfill_order_type_idx');
        });

        Schema::create('erp_sales_order_production_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('requirement_no', 80)->unique();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->foreignId('product_id')->nullable()->constrained('erp_products')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('erp_skus')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('erp_items')->nullOnDelete();
            $table->decimal('production_qty', 14, 4);
            $table->string('requirement_status', 30)->default('confirmed');
            $table->json('configuration_snapshot')->nullable();
            $table->json('bom_snapshot')->nullable();
            $table->json('routing_snapshot')->nullable();
            $table->json('drawing_snapshot')->nullable();
            $table->json('technical_attachment_snapshot')->nullable();
            $table->json('inspection_snapshot')->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->boolean('is_delay')->default(false);
            $table->date('delay_date')->nullable();
            $table->boolean('is_ready_for_work_order')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by', 80)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->foreign('sales_order_id', 'erp_so_prod_req_order_fk')->references('id')->on('erp_sales_orders')->cascadeOnDelete();
            $table->foreign('sales_order_line_id', 'erp_so_prod_req_line_fk')->references('id')->on('erp_sales_order_lines')->cascadeOnDelete();
            $table->index(['sales_order_id', 'requirement_status'], 'erp_so_prod_req_order_status_idx');
        });

        Schema::create('erp_sales_order_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedInteger('version_no');
            $table->string('change_type', 60);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->string('operator', 80)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->foreign('sales_order_id', 'erp_so_version_order_fk')->references('id')->on('erp_sales_orders')->cascadeOnDelete();
            $table->unique(['sales_order_id', 'version_no']);
        });

        Schema::create('erp_sales_order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->string('action', 80);
            $table->string('before_status', 60)->nullable();
            $table->string('after_status', 60)->nullable();
            $table->json('payload')->nullable();
            $table->string('operator', 80)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
            $table->foreign('sales_order_id', 'erp_so_log_order_fk')->references('id')->on('erp_sales_orders')->nullOnDelete();
            $table->foreign('sales_order_line_id', 'erp_so_log_line_fk')->references('id')->on('erp_sales_order_lines')->nullOnDelete();
            $table->index(['sales_order_id', 'action'], 'erp_so_log_order_action_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'erp_sales_order_logs',
            'erp_sales_order_versions',
            'erp_sales_order_production_requirements',
            'erp_sales_order_fulfillments',
            'erp_sales_order_lines',
            'erp_sales_orders',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
