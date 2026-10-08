<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_tasks', function (Blueprint $table): void {
            $table->boolean('auto_assignment_enabled_snapshot')->default(false);
        });
        Schema::create('erp_production_task_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('erp_production_tasks')->restrictOnDelete();
            // A nullable unique slot permits immutable historical decisions, but only one live offer per task.
            $table->unsignedBigInteger('active_task_id')->nullable()->unique('erp_pt_assignment_active_uq');
            $table->unsignedBigInteger('offered_to_legacy_id');
            $table->string('status', 20)->default('PENDING');
            $table->string('algorithm_version', 60);
            $table->json('score_snapshot');
            $table->unsignedBigInteger('offered_by_legacy_id')->nullable();
            $table->timestamp('offered_at');
            $table->unsignedBigInteger('decided_by_legacy_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
            $table->foreign('active_task_id', 'erp_pt_assignment_active_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
            $table->index(['offered_to_legacy_id', 'status', 'id'], 'erp_pt_assignment_recipient_idx');
            $table->index(['task_id', 'status', 'offered_to_legacy_id'], 'erp_pt_assignment_history_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_task_assignments')->exists()) {
            throw new RuntimeException('已有生产派单或拒绝事实，不允许删除派单记录。');
        }
        Schema::dropIfExists('erp_production_task_assignments');
        Schema::table('erp_production_tasks', fn (Blueprint $table) => $table->dropColumn('auto_assignment_enabled_snapshot'));
    }
};
