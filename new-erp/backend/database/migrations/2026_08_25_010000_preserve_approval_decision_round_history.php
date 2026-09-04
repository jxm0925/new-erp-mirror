<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('erp_approval_task_nodes', 'current_round')) Schema::table('erp_approval_task_nodes', function (Blueprint $table) {
            $table->unsignedInteger('current_round')->default(1)->after('node_status');
        });
        if (!Schema::hasColumn('erp_approval_node_decisions', 'round_no')) Schema::table('erp_approval_node_decisions', function (Blueprint $table) {
            $table->unsignedInteger('round_no')->default(1)->after('approval_task_node_id');
        });
        // MySQL may reuse the old unique index for the node foreign key. Give
        // that FK a dedicated supporting index before replacing the unique key.
        Schema::table('erp_approval_node_decisions', function (Blueprint $table) {
            $table->index('approval_task_node_id', 'erp_approval_decisions_node_fk_index');
        });
        Schema::table('erp_approval_node_decisions', function (Blueprint $table) {
            $table->dropUnique('erp_approval_node_decisions_node_user_unique');
            $table->unique(['approval_task_node_id', 'approver_id', 'round_no'], 'erp_approval_decisions_node_user_round_unique');
            $table->index(['approval_task_node_id', 'round_no'], 'erp_approval_decisions_node_round_index');
        });
    }

    public function down(): void
    {
        Schema::table('erp_approval_node_decisions', function (Blueprint $table) {
            $table->dropUnique('erp_approval_decisions_node_user_round_unique');
            $table->dropIndex('erp_approval_decisions_node_round_index');
            $table->dropColumn('round_no');
            $table->unique(['approval_task_node_id', 'approver_id'], 'erp_approval_node_decisions_node_user_unique');
            $table->dropIndex('erp_approval_decisions_node_fk_index');
        });
        Schema::table('erp_approval_task_nodes', function (Blueprint $table) {
            $table->dropColumn('current_round');
        });
    }
};
