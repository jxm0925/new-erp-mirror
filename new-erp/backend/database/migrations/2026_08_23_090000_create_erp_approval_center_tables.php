<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_approval_flow_templates')) {
            Schema::create('erp_approval_flow_templates', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('flow_code', 100)->unique();
                $table->string('flow_name', 160);
                $table->string('business_module', 80);
                $table->string('business_type', 100)->index();
                $table->string('business_scene', 160);
                $table->string('applicable_scope', 80)->default('all');
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedInteger('current_version')->default(0);
                $table->text('description')->nullable();
                $table->string('created_by', 100)->nullable();
                $table->string('updated_by', 100)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('erp_approval_flow_versions')) {
            Schema::create('erp_approval_flow_versions', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('flow_template_id')->constrained('erp_approval_flow_templates')->cascadeOnDelete();
                $table->unsignedInteger('version_no');
                $table->string('version_status', 30)->default('draft')->index();
                $table->json('definition_snapshot');
                $table->json('validation_snapshot')->nullable();
                $table->string('published_by', 100)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->string('updated_by', 100)->nullable();
                $table->timestamps();
                $table->unique(['flow_template_id', 'version_no'], 'erp_approval_flow_version_unique');
            });
        }

        if (!Schema::hasTable('erp_approval_tasks')) {
            Schema::create('erp_approval_tasks', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('task_no', 80)->nullable()->unique();
                $table->foreignId('flow_template_id')->nullable()->constrained('erp_approval_flow_templates')->nullOnDelete();
                $table->foreignId('flow_version_id')->nullable()->constrained('erp_approval_flow_versions')->nullOnDelete();
                $table->string('business_type', 100)->index();
                $table->unsignedBigInteger('business_id')->index();
                $table->string('business_no', 120)->nullable()->index();
                $table->string('subject', 240);
                $table->string('source_route', 240)->nullable();
                $table->string('risk_level', 30)->default('low')->index();
                $table->string('task_status', 30)->default('PENDING')->index();
                $table->string('active_business_key', 200)->nullable()->unique();
                $table->unsignedInteger('current_node_order')->nullable();
                $table->unsignedBigInteger('initiator_id')->nullable()->index();
                $table->string('initiator_name', 100)->nullable();
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('department_name', 120)->nullable();
                $table->json('business_snapshot');
                $table->json('diff_snapshot')->nullable();
                $table->json('flow_snapshot');
                $table->json('result_snapshot')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('submitted_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['task_status', 'current_node_order', 'submitted_at'], 'erp_approval_task_workbench_idx');
            });
        }

        if (!Schema::hasTable('erp_approval_task_nodes')) {
            Schema::create('erp_approval_task_nodes', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
                $table->string('node_key', 100);
                $table->unsignedInteger('node_order');
                $table->string('node_name', 120);
                $table->string('approval_type', 80)->nullable();
                $table->string('node_status', 30)->default('WAITING')->index();
                $table->string('processing_strategy', 30)->default('sequential');
                $table->string('permission_code', 160)->nullable();
                $table->json('approver_rule')->nullable();
                $table->json('condition_snapshot')->nullable();
                $table->unsignedInteger('sla_hours')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable()->index();
                $table->string('decided_by_name', 100)->nullable();
                $table->string('decision', 30)->nullable();
                $table->text('decision_comment')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->unique(['approval_task_id', 'node_key'], 'erp_approval_task_node_unique');
                $table->index(['approval_task_id', 'node_order'], 'erp_approval_task_node_order_idx');
            });
        }

        if (!Schema::hasTable('erp_approval_task_logs')) {
            Schema::create('erp_approval_task_logs', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
                $table->foreignId('approval_task_node_id')->nullable()->constrained('erp_approval_task_nodes')->nullOnDelete();
                $table->string('action', 80)->index();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->string('operator_name', 100)->nullable();
                $table->text('content');
                $table->json('payload')->nullable();
                $table->timestamp('operated_at')->index();
                $table->timestamps();
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_approval_task_logs');
        Schema::dropIfExists('erp_approval_task_nodes');
        Schema::dropIfExists('erp_approval_tasks');
        Schema::dropIfExists('erp_approval_flow_versions');
        Schema::dropIfExists('erp_approval_flow_templates');
    }
};

