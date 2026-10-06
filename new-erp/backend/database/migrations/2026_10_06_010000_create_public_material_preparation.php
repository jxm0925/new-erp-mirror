<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_public_material_preparation_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_no', 80)->unique();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->string('status', 30)->default('WAIT_PICK');
            $table->unsignedBigInteger('assigned_picker_legacy_id')->nullable();
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->unsignedInteger('business_version')->default(1);
            $table->text('remark')->nullable();
            $table->timestamps();
        });
        Schema::table('erp_material_picking_tasks', function (Blueprint $table): void {
            $table->foreignId('public_preparation_task_id')->nullable()->constrained('erp_public_material_preparation_tasks', 'id', 'erp_mpt_public_task_fk')->restrictOnDelete();
        });
        Schema::table('erp_material_picking_task_lines', function (Blueprint $table): void {
            $table->string('fulfillment_mode_snapshot', 30)->default('delivery');
        });
        Schema::table('erp_material_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('delivery_id')->nullable()->change();
            $table->string('collection_type', 30)->default('delivery');
            $table->foreignId('picking_task_id')->nullable()->constrained('erp_material_picking_tasks', 'id', 'erp_receipt_pick_fk')->restrictOnDelete();
        });
        Schema::table('erp_material_receipt_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('delivery_line_id')->nullable()->change();
            $table->foreignId('picking_task_line_id')->nullable()->constrained('erp_material_picking_task_lines', 'id', 'erp_receipt_pick_line_fk')->restrictOnDelete();
        });
        Schema::create('erp_material_procurement_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('erp_purchase_requests')->restrictOnDelete();
            $table->foreignId('request_item_id')->constrained('erp_purchase_request_items')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('erp_sales_orders')->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('target_material_requirement_id')->nullable()->constrained('erp_production_target_material_requirements', 'id', 'erp_procurement_demand_fk')->restrictOnDelete();
            $table->decimal('requested_base_qty', 18, 8);
            $table->json('source_snapshot')->nullable();
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Retain nullable receipt references: existing onsite receipts must never be discarded.
        Schema::dropIfExists('erp_material_procurement_sources');
        Schema::table('erp_material_receipt_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('picking_task_line_id'));
        Schema::table('erp_material_receipts', function (Blueprint $t): void { $t->dropConstrainedForeignId('picking_task_id'); $t->dropColumn('collection_type'); });
        Schema::table('erp_material_picking_task_lines', fn (Blueprint $t) => $t->dropColumn('fulfillment_mode_snapshot'));
        Schema::table('erp_material_picking_tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('public_preparation_task_id'));
        Schema::dropIfExists('erp_public_material_preparation_tasks');
    }
};
