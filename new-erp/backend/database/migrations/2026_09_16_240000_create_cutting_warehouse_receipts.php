<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_result_routes', function (Blueprint $table): void {
            $table->decimal('warehoused_qty', 18, 8)->default(0)->after('received_qty');
            $table->decimal('warehoused_cost', 18, 4)->default(0)->after('received_cost');
        });

        Schema::create('erp_cutting_warehouse_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no', 80)->unique();
            $table->foreignId('route_id')->constrained('erp_cutting_result_routes')->restrictOnDelete();
            $table->foreignId('cutting_order_id')->constrained('erp_cutting_orders')->restrictOnDelete();
            $table->foreignId('result_id')->constrained('erp_cutting_results')->restrictOnDelete();
            $table->foreignId('source_holding_id')->constrained('erp_material_holdings')->restrictOnDelete();
            $table->foreignId('warehouse_holding_id')->nullable()->constrained('erp_material_holdings')->restrictOnDelete();
            $table->foreignId('inventory_balance_id')->nullable()->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->decimal('posted_qty', 18, 8);
            $table->decimal('posted_cost', 18, 4);
            $table->string('status', 24)->default('POSTING');
            $table->foreignId('inventory_transaction_id')->nullable()->unique()->constrained('erp_inventory_transactions')->restrictOnDelete();
            $table->unsignedBigInteger('posted_by_legacy_id');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['route_id', 'status'], 'cut_wh_receipt_route_status_idx');
        });

        Schema::create('erp_cutting_warehouse_receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('output_allocation_id');
            $table->decimal('quantity', 18, 8);
            $table->decimal('total_cost', 18, 4);
            $table->timestamps();
            $table->index(['output_allocation_id', 'receipt_id'], 'cut_wh_receipt_output_allocation_idx');
            $table->foreign('receipt_id', 'cut_wh_receipt_allocation_receipt_fk')
                ->references('id')->on('erp_cutting_warehouse_receipts')->restrictOnDelete();
            $table->foreign('output_allocation_id', 'cut_wh_receipt_output_allocation_fk')
                ->references('id')->on('erp_cutting_output_allocations')->restrictOnDelete();
        });

        Schema::create('erp_cutting_inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reservation_no', 80)->unique();
            $table->unsignedBigInteger('receipt_allocation_id')->unique('cut_inv_res_receipt_allocation_uq');
            $table->foreignId('plan_id')->nullable()->constrained('erp_cutting_plan_allocations')->restrictOnDelete();
            $table->foreignId('configuration_id')->nullable()->constrained('erp_custom_configurations')->restrictOnDelete();
            $table->unsignedBigInteger('target_material_requirement_id')->nullable();
            $table->foreignId('inventory_balance_id')->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->string('reservation_scope', 32);
            $table->decimal('reserved_qty', 18, 8);
            $table->decimal('issued_qty', 18, 8)->default(0);
            $table->string('status', 24)->default('ACTIVE');
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamp('reserved_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['target_material_requirement_id', 'status'], 'cut_inv_res_target_status_idx');
            $table->index(['configuration_id', 'status'], 'cut_inv_res_config_status_idx');
            $table->foreign('target_material_requirement_id', 'cut_inv_res_target_requirement_fk')
                ->references('id')->on('erp_production_target_material_requirements')->restrictOnDelete();
            $table->foreign('receipt_allocation_id', 'cut_inv_res_receipt_allocation_fk')
                ->references('id')->on('erp_cutting_warehouse_receipt_allocations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_cutting_warehouse_receipts')->exists()
            || DB::table('erp_cutting_warehouse_receipt_allocations')->exists()
            || DB::table('erp_cutting_inventory_reservations')->exists()) {
            throw new RuntimeException('已有正式下料入库事实，不允许通过结构回退删除业务历史。');
        }
        Schema::dropIfExists('erp_cutting_inventory_reservations');
        Schema::dropIfExists('erp_cutting_warehouse_receipt_allocations');
        Schema::dropIfExists('erp_cutting_warehouse_receipts');
        Schema::table('erp_cutting_result_routes', function (Blueprint $table): void {
            $table->dropColumn(['warehoused_qty', 'warehoused_cost']);
        });
    }
};
