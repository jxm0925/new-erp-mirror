<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reservation_no', 80)->unique();
            $table->unsignedBigInteger('source_work_order_id');
            $table->unsignedBigInteger('source_output_record_id');
            $table->unsignedBigInteger('finished_goods_receipt_id');
            $table->unsignedBigInteger('inventory_balance_id');
            $table->unsignedBigInteger('target_work_order_id');
            $table->unsignedBigInteger('target_production_unit_id')->nullable();
            $table->unsignedBigInteger('target_routing_operation_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('target_material_requirement_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('reserved_base_qty', 18, 8);
            $table->decimal('issued_base_qty', 18, 8)->default(0);
            $table->string('status', 30)->default('ACTIVE');
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamp('reserved_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('finished_goods_receipt_id', 'erp_prod_inv_res_receipt_uq');
            $table->index(['target_work_order_id', 'target_type', 'target_id', 'status'], 'erp_prod_inv_res_target_idx');
            $table->foreign('source_work_order_id', 'erp_prod_inv_res_source_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('source_output_record_id', 'erp_prod_inv_res_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $table->foreign('finished_goods_receipt_id', 'erp_prod_inv_res_receipt_fk')->references('id')->on('erp_work_order_finished_goods_receipts')->restrictOnDelete();
            $table->foreign('inventory_balance_id', 'erp_prod_inv_res_balance_fk')->references('id')->on('erp_inventory_balances')->restrictOnDelete();
            $table->foreign('target_work_order_id', 'erp_prod_inv_res_target_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('target_production_unit_id', 'erp_prod_inv_res_target_unit_fk')->references('id')->on('erp_production_units')->restrictOnDelete();
            $table->foreign('target_routing_operation_id', 'erp_prod_inv_res_target_route_fk')->references('id')->on('erp_production_routing_operations')->restrictOnDelete();
            $table->foreign('target_material_requirement_id', 'erp_prod_inv_res_target_req_fk')->references('id')->on('erp_production_target_material_requirements')->restrictOnDelete();
            $table->foreign('item_id', 'erp_prod_inv_res_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
        });

        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_inventory_reservation_id')->nullable()->after('output_record_id');
            $table->foreign('production_inventory_reservation_id', 'erp_prod_issue_line_reservation_fk')
                ->references('id')->on('erp_production_inventory_reservations')->restrictOnDelete();
        });

        Schema::create('erp_production_output_lineage_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_output_record_id');
            $table->unsignedBigInteger('child_output_record_id');
            $table->string('relation_type', 30);
            $table->unsignedBigInteger('parent_inventory_serial_id')->nullable();
            $table->unsignedBigInteger('child_inventory_serial_id')->nullable();
            $table->timestamps();

            $table->unique(['parent_output_record_id', 'child_output_record_id'], 'erp_prod_output_lineage_uq');
            $table->index(['child_output_record_id', 'relation_type'], 'erp_prod_output_lineage_child_idx');
            $table->foreign('parent_output_record_id', 'erp_prod_lineage_parent_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $table->foreign('child_output_record_id', 'erp_prod_lineage_child_output_fk')->references('id')->on('erp_production_output_records')->cascadeOnDelete();
            $table->foreign('parent_inventory_serial_id', 'erp_prod_lineage_parent_serial_fk')->references('id')->on('erp_inventory_serials')->nullOnDelete();
            $table->foreign('child_inventory_serial_id', 'erp_prod_lineage_child_serial_fk')->references('id')->on('erp_inventory_serials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_output_lineage_links');
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->dropForeign('erp_prod_issue_line_reservation_fk');
            $table->dropColumn('production_inventory_reservation_id');
        });
        Schema::dropIfExists('erp_production_inventory_reservations');
    }
};
