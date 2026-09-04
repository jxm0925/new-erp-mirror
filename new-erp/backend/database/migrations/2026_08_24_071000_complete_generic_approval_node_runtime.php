<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_approval_task_nodes', function (Blueprint $table) {
            $table->string('node_type', 30)->default('APPROVAL')->after('node_name')->index();
            $table->string('action_code', 140)->nullable()->after('approver_rule');
            $table->json('action_config')->nullable()->after('action_code');
            $table->string('completion_strategy', 30)->default('ANY')->after('processing_strategy');
            $table->unsignedInteger('required_approver_count')->nullable()->after('completion_strategy');
            $table->decimal('required_approver_ratio', 7, 4)->nullable()->after('required_approver_count');
            $table->boolean('reject_on_any')->default(true)->after('required_approver_ratio');
        });

        Schema::create('erp_approval_action_executions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
            $table->foreignId('approval_task_node_id')->nullable()->constrained('erp_approval_task_nodes')->nullOnDelete();
            $table->string('action_code', 140)->index();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('execution_status', 30)->index();
            $table->json('config_snapshot')->nullable();
            $table->json('result_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->string('operator_name', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['approval_task_id', 'execution_status'], 'erp_approval_action_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_approval_action_executions');
        Schema::table('erp_approval_task_nodes', function (Blueprint $table) {
            $table->dropColumn([
                'node_type', 'action_code', 'action_config', 'completion_strategy',
                'required_approver_count', 'required_approver_ratio', 'reject_on_any',
            ]);
        });
    }
};
