<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_production_job_bundles', function (Blueprint $t): void {
            $t->id();
            $t->string('bundle_no', 80)->unique();
            $t->string('title', 160)->nullable();
            $t->string('status', 30)->default('WAIT_CLAIM');
            $t->unsignedBigInteger('operation_id_snapshot');
            $t->string('operation_code_snapshot', 80);
            $t->string('operation_name_snapshot', 160);
            $t->string('compatibility_key', 64);
            $t->json('compatibility_snapshot');
            $t->unsignedBigInteger('assignee_user_legacy_id')->nullable();
            $t->unsignedBigInteger('created_by_legacy_id');
            $t->string('organization_code', 80)->nullable();
            $t->decimal('actual_labor_minutes', 14, 2)->default(0);
            $t->unsignedInteger('business_version')->default(1);
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('paused_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['assignee_user_legacy_id', 'status'], 'prod_bundle_owner_status_idx');
        });
        Schema::table('erp_production_tasks', function (Blueprint $t): void {
            $t->foreignId('active_job_bundle_id')->nullable()->constrained('erp_production_job_bundles')->restrictOnDelete();
        });
        Schema::create('erp_production_job_bundle_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('job_bundle_id')->constrained('erp_production_job_bundles')->restrictOnDelete();
            $t->foreignId('task_id')->constrained('erp_production_tasks')->restrictOnDelete();
            // Nullable active slot keeps history while the unique index prevents concurrent membership.
            $t->unsignedBigInteger('active_task_id')->nullable()->unique('prod_bundle_one_active_task_uq');
            $t->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $t->string('target_type', 30);
            $t->unsignedBigInteger('target_id');
            $t->string('status', 30)->default('PENDING');
            $t->decimal('standard_weight_minutes_snapshot', 18, 8);
            $t->decimal('allocated_labor_minutes', 14, 2)->default(0);
            $t->json('source_snapshot');
            $t->json('material_snapshot');
            $t->json('started_material_snapshot')->nullable();
            $t->timestamp('execution_completed_at')->nullable();
            $t->timestamps();
            $t->unique(['job_bundle_id', 'task_id'], 'prod_bundle_task_uq');
            $t->index(['target_type', 'target_id'], 'prod_bundle_target_idx');
        });
        Schema::table('erp_production_labor_sessions', function (Blueprint $t): void {
            $t->foreignId('job_bundle_id')->nullable()->constrained('erp_production_job_bundles')->restrictOnDelete();
            $t->decimal('bundle_checkpoint_minutes', 14, 2)->default(0);
        });
        Schema::create('erp_production_job_bundle_labor_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('job_bundle_line_id')->constrained('erp_production_job_bundle_lines', 'id', 'prod_bundle_alloc_line_fk')->restrictOnDelete();
            $t->foreignId('labor_session_id')->constrained('erp_production_labor_sessions', 'id', 'prod_bundle_alloc_session_fk')->restrictOnDelete();
            $t->unsignedBigInteger('employee_legacy_id');
            $t->decimal('standard_weight_minutes_snapshot', 18, 8);
            $t->decimal('allocated_labor_minutes', 14, 2)->default(0);
            $t->decimal('credited_labor_minutes', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['job_bundle_line_id', 'labor_session_id'], 'prod_bundle_labor_line_session_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_job_bundles')->exists()) {
            throw new RuntimeException('已有正式共同加工事实，不允许回退删除业务历史。');
        }
        Schema::dropIfExists('erp_production_job_bundle_labor_allocations');
        Schema::table('erp_production_labor_sessions', function (Blueprint $t): void {
            $t->dropForeign(['job_bundle_id']);
            $t->dropColumn(['job_bundle_id', 'bundle_checkpoint_minutes']);
        });
        Schema::dropIfExists('erp_production_job_bundle_lines');
        Schema::table('erp_production_tasks', function (Blueprint $t): void {
            $t->dropForeign(['active_job_bundle_id']);
            $t->dropColumn('active_job_bundle_id');
        });
        Schema::dropIfExists('erp_production_job_bundles');
    }
};
