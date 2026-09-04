<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_sales_shipments')) Schema::create('erp_sales_shipments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('shipment_no', 80)->unique();
            $table->foreignId('sales_order_id')->constrained('erp_sales_orders')->restrictOnDelete();
            $table->string('shipment_status', 30)->default('draft')->index();
            $table->string('carrier_name_snapshot', 120)->nullable();
            $table->string('tracking_no', 120)->nullable()->index();
            $table->json('receiver_snapshot')->nullable();
            $table->decimal('actual_freight_amount', 18, 4)->default(0);
            $table->decimal('actual_cost_amount', 18, 4)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('outbound_posted_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->text('remark')->nullable();
            $table->string('created_by', 80)->nullable();
            $table->string('confirmed_by', 80)->nullable();
            $table->string('outbound_posted_by', 80)->nullable();
            $table->timestamps();
            $table->index(['sales_order_id', 'shipment_status'], 'erp_sales_shipment_order_status_idx');
        });

        if (!Schema::hasTable('erp_sales_shipment_lines')) Schema::create('erp_sales_shipment_lines', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('shipment_id')->constrained('erp_sales_shipments')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('erp_sales_order_lines')->restrictOnDelete();
            $table->foreignId('sales_order_fulfillment_id')->nullable()->constrained('erp_sales_order_fulfillments')->nullOnDelete();
            $table->foreignId('inventory_reservation_id')->nullable()->constrained('erp_inventory_reservations')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('sales_qty', 18, 8);
            $table->decimal('base_qty', 18, 8);
            $table->decimal('unit_cost_snapshot', 18, 8)->default(0);
            $table->decimal('cost_amount_snapshot', 18, 4)->default(0);
            $table->string('line_status', 30)->default('draft')->index();
            $table->json('serial_snapshot')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'sales_order_line_id'], 'erp_sales_shipment_line_order_idx');
        });

        if (!Schema::hasTable('erp_sales_shipment_packages')) Schema::create('erp_sales_shipment_packages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('shipment_id')->constrained('erp_sales_shipments')->cascadeOnDelete();
            $table->string('package_no', 80);
            $table->decimal('weight', 18, 4)->nullable();
            $table->decimal('volume', 18, 6)->nullable();
            $table->string('carrier_name', 120)->nullable();
            $table->string('tracking_no', 120)->nullable()->index();
            $table->decimal('freight_amount', 18, 4)->default(0);
            $table->string('package_status', 30)->default('draft')->index();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['shipment_id', 'package_no'], 'erp_sales_shipment_package_unique');
        });

        if (!Schema::hasTable('erp_sales_shipment_logs')) Schema::create('erp_sales_shipment_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('shipment_id')->constrained('erp_sales_shipments')->cascadeOnDelete();
            $table->string('action', 60)->index();
            $table->string('before_status', 30)->nullable();
            $table->string('after_status', 30)->nullable();
            $table->json('payload')->nullable();
            $table->string('operator', 80)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_shipment_logs');
        Schema::dropIfExists('erp_sales_shipment_packages');
        Schema::dropIfExists('erp_sales_shipment_lines');
        Schema::dropIfExists('erp_sales_shipments');
    }
};
