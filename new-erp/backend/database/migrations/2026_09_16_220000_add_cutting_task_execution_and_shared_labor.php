<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_tasks', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->after('assignee_user_legacy_id');
            $table->timestamp('started_at')->nullable()->after('claimed_at');
            $table->timestamp('paused_at')->nullable()->after('started_at');
            $table->timestamp('completed_at')->nullable()->after('paused_at');
            $table->decimal('actual_labor_minutes', 14, 2)->default(0)->after('completed_at');
            $table->string('work_mode_snapshot', 20)->default('manual')->after('actual_labor_minutes');
            $table->index(['status', 'assignee_user_legacy_id'], 'cut_task_status_assignee_idx');
        });
        DB::table('erp_cutting_tasks')->where('status', 'READY')->whereNull('assignee_user_legacy_id')
            ->update(['status' => 'WAIT_CLAIM']);
        DB::statement("ALTER TABLE erp_cutting_tasks ALTER COLUMN status SET DEFAULT 'WAIT_CLAIM'");

        Schema::create('erp_cutting_task_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cutting_task_id');
            $table->unsignedBigInteger('employee_legacy_id');
            $table->string('role', 20)->default('collaborator');
            $table->decimal('responsibility_weight', 8, 4)->default(0);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
            $table->index(['cutting_task_id', 'employee_legacy_id', 'left_at'], 'cut_task_participant_history_idx');
        });
        Schema::table('erp_cutting_task_participants', function (Blueprint $table): void {
            $table->foreign('cutting_task_id', 'cut_task_participant_task_fk')->references('id')->on('erp_cutting_tasks')->cascadeOnDelete();
        });
        Schema::table('erp_cutting_task_participants', function (Blueprint $table): void {
            // MySQL 8.0.12 cannot add a generated column to this FK-backed table.
            // The service writes task:employee while active and NULL on leave; the
            // unique key remains the database concurrency backstop.
            $table->string('active_participant_key', 100)->nullable();
            $table->unique('active_participant_key', 'cut_task_one_active_participant_uq');
        });

        Schema::table('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->dropForeign('erp_prod_labor_task_fk');
        });
        Schema::table('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->string('execution_task_type', 24)->default('PRODUCTION_TASK')->after('id');
            $table->foreignId('cutting_task_id')->nullable()->after('task_id')->constrained('erp_cutting_tasks')->restrictOnDelete();
            $table->index(['cutting_task_id', 'employee_legacy_id', 'status'], 'cut_labor_task_employee_status_idx');
            $table->foreign('task_id', 'erp_prod_labor_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
        });

    }

    public function down(): void
    {
        $hasCuttingLabor = Schema::hasColumn('erp_production_labor_sessions', 'execution_task_type')
            && DB::table('erp_production_labor_sessions')->where('execution_task_type', 'CUTTING_TASK')->exists();
        $hasParticipants = Schema::hasTable('erp_cutting_task_participants')
            && DB::table('erp_cutting_task_participants')->exists();
        if ($hasCuttingLabor || $hasParticipants) {
            throw new RuntimeException('已有正式下料参与人或劳动事实，不允许通过结构回退删除业务历史。');
        }
        Schema::table('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->dropForeign(['cutting_task_id']);
            $table->dropForeign('erp_prod_labor_task_fk');
            $table->dropIndex('cut_labor_task_employee_status_idx');
            $table->dropColumn(['execution_task_type', 'cutting_task_id']);
        });
        Schema::table('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->foreign('task_id', 'erp_prod_labor_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
        });

        Schema::dropIfExists('erp_cutting_task_participants');
        DB::table('erp_cutting_tasks')->where('status', 'WAIT_CLAIM')->whereNull('assignee_user_legacy_id')
            ->update(['status' => 'READY']);
        DB::statement("ALTER TABLE erp_cutting_tasks ALTER COLUMN status SET DEFAULT 'READY'");
        Schema::table('erp_cutting_tasks', function (Blueprint $table): void {
            $table->dropIndex('cut_task_status_assignee_idx');
            $table->dropColumn(['claimed_at', 'started_at', 'paused_at', 'completed_at', 'actual_labor_minutes', 'work_mode_snapshot']);
        });
    }
};
