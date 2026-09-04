<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_approval_business_objects')) Schema::create('erp_approval_business_objects', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('object_code', 100)->unique();
            $table->string('object_name', 160);
            $table->string('business_module', 80)->index();
            $table->string('source_type', 30)->default('database');
            $table->string('source_table', 120)->nullable();
            $table->string('primary_key', 80)->default('id');
            $table->json('display_fields')->nullable();
            $table->json('search_fields')->nullable();
            $table->string('route_pattern', 240)->nullable();
            $table->string('provider_class', 240);
            $table->string('context_provider_class', 240)->nullable();
            $table->string('view_permission_code', 160)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        if (!Schema::hasTable('erp_approval_business_object_fields')) Schema::create('erp_approval_business_object_fields', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('business_object_id')->constrained('erp_approval_business_objects')->cascadeOnDelete();
            $table->string('field_code', 120);
            $table->string('field_name', 160);
            $table->string('field_type', 40);
            $table->string('source_path', 200);
            $table->json('options')->nullable();
            $table->boolean('condition_enabled')->default(false);
            $table->boolean('display_enabled')->default(true);
            $table->boolean('reference_enabled')->default(false);
            $table->boolean('approval_writable')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['business_object_id', 'field_code'], 'erp_approval_object_field_unique');
        });

        if (!Schema::hasTable('erp_approval_business_events')) Schema::create('erp_approval_business_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('business_object_id')->constrained('erp_approval_business_objects')->cascadeOnDelete();
            $table->string('event_code', 100);
            $table->string('event_name', 160);
            $table->boolean('manual_start_allowed')->default(false);
            $table->boolean('event_trigger_allowed')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['business_object_id', 'event_code'], 'erp_approval_object_event_unique');
        });

        if (!Schema::hasTable('erp_approval_business_actions')) Schema::create('erp_approval_business_actions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('business_object_id')->nullable()->constrained('erp_approval_business_objects')->cascadeOnDelete();
            $table->string('action_code', 140)->unique();
            $table->string('action_name', 180);
            $table->string('result_event', 30)->index();
            $table->string('handler_class', 240);
            $table->json('config_schema')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('erp_approval_flow_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_approval_flow_templates', 'business_object_code')) $table->string('business_object_code', 100)->nullable()->after('business_type')->index();
            if (!Schema::hasColumn('erp_approval_flow_templates', 'event_code')) $table->string('event_code', 100)->nullable()->after('business_object_code')->index();
            if (!Schema::hasColumn('erp_approval_flow_templates', 'trigger_mode')) $table->string('trigger_mode', 30)->default('MANUAL_START')->after('event_code')->index();
            if (!Schema::hasColumn('erp_approval_flow_templates', 'execution_mode')) $table->string('execution_mode', 30)->default('BEFORE_ACTION')->after('trigger_mode');
            if (!Schema::hasColumn('erp_approval_flow_templates', 'priority')) $table->unsignedInteger('priority')->default(100)->after('execution_mode')->index();
            if (!Schema::hasColumn('erp_approval_flow_templates', 'match_strategy')) $table->string('match_strategy', 30)->default('FIRST_MATCH')->after('priority');
        });

        Schema::table('erp_approval_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_approval_tasks', 'business_object_code')) $table->string('business_object_code', 100)->nullable()->after('business_type')->index();
            if (!Schema::hasColumn('erp_approval_tasks', 'event_code')) $table->string('event_code', 100)->nullable()->after('business_object_code')->index();
            if (!Schema::hasColumn('erp_approval_tasks', 'idempotency_key')) $table->string('idempotency_key', 200)->nullable()->after('active_business_key')->unique();
            if (!Schema::hasColumn('erp_approval_tasks', 'launch_result')) $table->string('launch_result', 30)->default('STARTED')->after('idempotency_key');
            if (!Schema::hasColumn('erp_approval_tasks', 'action_status')) $table->string('action_status', 30)->default('PENDING')->after('launch_result');
            if (!Schema::hasColumn('erp_approval_tasks', 'action_error')) $table->text('action_error')->nullable()->after('action_status');
        });

        if (!Schema::hasTable('erp_approval_node_assignees')) Schema::create('erp_approval_node_assignees', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
            $table->foreignId('approval_task_node_id')->constrained('erp_approval_task_nodes')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('user_name', 120);
            $table->string('source_type', 80);
            $table->string('source_value', 200)->nullable();
            $table->string('status', 30)->default('PENDING')->index();
            $table->unsignedBigInteger('transferred_from')->nullable()->index();
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['approval_task_node_id', 'user_id'], 'erp_approval_node_assignee_unique');
        });

        if (!Schema::hasTable('erp_approval_notifications')) Schema::create('erp_approval_notifications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
            $table->foreignId('approval_task_node_id')->nullable()->constrained('erp_approval_task_nodes')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('notification_type', 40)->index();
            $table->string('title', 200);
            $table->text('content');
            $table->string('status', 30)->default('UNREAD')->index();
            $table->string('dedup_key', 200)->unique();
            $table->timestamp('notified_at')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_approval_notifications');
        Schema::dropIfExists('erp_approval_node_assignees');
        Schema::table('erp_approval_tasks', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['business_object_code', 'event_code', 'idempotency_key', 'launch_result', 'action_status', 'action_error']);
        });
        Schema::table('erp_approval_flow_templates', function (Blueprint $table) {
            $table->dropColumn(['business_object_code', 'event_code', 'trigger_mode', 'execution_mode', 'priority', 'match_strategy']);
        });
        Schema::dropIfExists('erp_approval_business_actions');
        Schema::dropIfExists('erp_approval_business_events');
        Schema::dropIfExists('erp_approval_business_object_fields');
        Schema::dropIfExists('erp_approval_business_objects');
    }
};

