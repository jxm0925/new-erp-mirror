<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_workstation_stock_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('target_material_requirement_id');
            $table->unsignedBigInteger('kitting_confirmation_id')->nullable();
            $table->string('workstation_snapshot', 160);
            $table->unsignedBigInteger('component_item_id');
            $table->decimal('required_base_qty_snapshot', 18, 8);
            $table->decimal('onsite_available_base_qty_snapshot', 18, 8);
            $table->decimal('confirmed_base_qty', 18, 8);
            $table->unsignedBigInteger('confirmed_by_legacy_id');
            $table->timestamp('confirmed_at');
            $table->json('fact_snapshot')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique('target_material_requirement_id', 'erp_workstation_stock_requirement_uq');
            $table->index(['target_type', 'target_id', 'confirmed_at'], 'erp_workstation_stock_target_idx');
            $table->foreign('work_order_id', 'erp_workstation_stock_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('task_id', 'erp_workstation_stock_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
            $table->foreign('target_material_requirement_id', 'erp_workstation_stock_req_fk')->references('id')->on('erp_production_target_material_requirements')->restrictOnDelete();
            $table->foreign('kitting_confirmation_id', 'erp_workstation_stock_kitting_fk')->references('id')->on('erp_production_kitting_confirmations')->nullOnDelete();
            $table->foreign('component_item_id', 'erp_workstation_stock_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_workstation_stock_confirmations');
    }
};
