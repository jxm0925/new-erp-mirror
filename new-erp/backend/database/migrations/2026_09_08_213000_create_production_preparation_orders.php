<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_production_preparation_orders')) {
            Schema::create('erp_production_preparation_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('preparation_order_no', 80)->unique();
                $table->unsignedBigInteger('production_master_order_id');
                $table->unsignedBigInteger('active_production_master_order_id')->nullable();
                $table->string('status', 30)->default('WAIT_PREPARE');
                $table->unsignedInteger('business_version')->default(1);
                $table->string('organization_code', 80)->nullable();
                $table->unsignedBigInteger('created_by_legacy_id')->nullable();
                $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
                $table->timestamps();

                $table->index(['status', 'updated_at'], 'erp_pb_status_time_idx');
                $table->unique('active_production_master_order_id', 'erp_pb_active_master_uq');
                $table->foreign('production_master_order_id', 'erp_pb_master_fk')->references('id')->on('erp_production_master_orders')->restrictOnDelete();
                $table->foreign('active_production_master_order_id', 'erp_pb_active_master_fk')->references('id')->on('erp_production_master_orders')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('erp_production_preparation_order_lines')) {
            Schema::create('erp_production_preparation_order_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('preparation_order_id');
                $table->unsignedBigInteger('work_order_id');
                $table->unsignedBigInteger('material_requirement_id');
                $table->unsignedBigInteger('component_item_id');
                $table->decimal('required_base_qty', 18, 8);
                $table->decimal('prepared_base_qty', 18, 8)->default(0);
                $table->decimal('delivered_base_qty', 18, 8)->default(0);
                $table->decimal('received_base_qty', 18, 8)->default(0);
                $table->string('status', 30)->default('WAIT_PREPARE');
                $table->unsignedInteger('business_version')->default(1);
                $table->timestamps();

                $table->index(['preparation_order_id', 'status'], 'erp_pb_line_order_status_idx');
                $table->index(['work_order_id', 'status'], 'erp_pb_line_wo_status_idx');
                $table->unique('material_requirement_id', 'erp_pb_line_req_uq');
                $table->foreign('preparation_order_id', 'erp_pb_line_order_fk')->references('id')->on('erp_production_preparation_orders')->restrictOnDelete();
                $table->foreign('work_order_id', 'erp_pb_line_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
                $table->foreign('material_requirement_id', 'erp_pb_line_req_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
                $table->foreign('component_item_id', 'erp_pb_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_preparation_order_lines');
        Schema::dropIfExists('erp_production_preparation_orders');
    }
};
