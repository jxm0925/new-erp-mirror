<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A material delivery is the PD business document and receipt source of truth.
     * It must not also be the person-facing DT: one PD line may be split between
     * several people and a completed DT is not evidence that production received it.
     */
    public function up(): void
    {
        Schema::create('erp_material_delivery_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_no', 80)->unique();
            $table->unsignedBigInteger('delivery_wave_id')->nullable();
            $table->string('assignment_mode', 30);
            $table->string('zone_pool_code', 80)->nullable();
            $table->string('warehouse_code', 80)->nullable();
            $table->string('production_zone_code', 80)->nullable();
            $table->string('work_center_code', 80)->nullable();
            $table->string('location_code', 80)->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('required_finish_at')->nullable();
            $table->string('status', 30)->default('WAIT_CLAIM');
            $table->unsignedInteger('business_version')->default(1);
            $table->string('organization_code', 80)->nullable();
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();
            $table->index(['assignment_mode', 'zone_pool_code', 'status'], 'erp_dt_pool_status_idx_v2');
            $table->index(['organization_code', 'production_zone_code', 'status'], 'erp_dt_scope_status_idx');
            $table->foreign('delivery_wave_id', 'erp_dt_wave_fk_v2')->references('id')->on('erp_material_delivery_waves')->restrictOnDelete();
        });

        Schema::create('erp_material_delivery_task_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delivery_task_id');
            $table->unsignedBigInteger('material_delivery_id');
            $table->unsignedBigInteger('material_delivery_line_id');
            $table->decimal('allocated_qty', 18, 8);
            $table->json('serial_snapshot')->nullable();
            $table->unsignedBigInteger('handling_unit_id')->nullable();
            $table->string('status', 30)->default('WAIT_EXECUTION');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
            $table->unique(['delivery_task_id', 'material_delivery_line_id'], 'erp_dt_line_task_pdline_uq');
            $table->index(['material_delivery_line_id', 'status'], 'erp_dt_line_pd_status_idx');
            $table->foreign('delivery_task_id', 'erp_dt_line_task_fk')->references('id')->on('erp_material_delivery_tasks')->cascadeOnDelete();
            $table->foreign('material_delivery_id', 'erp_dt_line_pd_fk')->references('id')->on('erp_material_deliveries')->restrictOnDelete();
            $table->foreign('material_delivery_line_id', 'erp_dt_line_pdline_fk')->references('id')->on('erp_material_delivery_lines')->restrictOnDelete();
        });

        Schema::create('erp_material_delivery_task_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delivery_task_id');
            $table->unsignedBigInteger('assignee_legacy_id');
            $table->string('assignment_type', 30);
            $table->unsignedBigInteger('assigned_by_legacy_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['assignee_legacy_id', 'released_at'], 'erp_dt_assignment_user_active_idx');
            $table->foreign('delivery_task_id', 'erp_dt_assignment_task_fk')->references('id')->on('erp_material_delivery_tasks')->cascadeOnDelete();
        });

        Schema::create('erp_material_delivery_task_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delivery_task_id');
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->unsignedInteger('before_version');
            $table->unsignedInteger('after_version');
            $table->json('snapshot')->nullable();
            $table->unsignedBigInteger('operator_legacy_id')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['delivery_task_id', 'id'], 'erp_dt_event_task_idx');
            $table->foreign('delivery_task_id', 'erp_dt_event_task_fk')->references('id')->on('erp_material_delivery_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_material_delivery_task_events');
        Schema::dropIfExists('erp_material_delivery_task_assignments');
        Schema::dropIfExists('erp_material_delivery_task_lines');
        Schema::dropIfExists('erp_material_delivery_tasks');
    }
};
