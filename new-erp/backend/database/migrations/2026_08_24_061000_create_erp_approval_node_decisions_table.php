<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_approval_node_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
            $table->foreignId('approval_task_node_id')->constrained('erp_approval_task_nodes')->cascadeOnDelete();
            $table->unsignedBigInteger('approver_id');
            $table->string('approver_name', 120);
            $table->string('decision', 30);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['approval_task_node_id', 'approver_id'], 'erp_approval_node_decisions_node_user_unique');
            $table->index(['approval_task_id', 'decided_at'], 'erp_approval_node_decisions_task_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_approval_node_decisions');
    }
};
