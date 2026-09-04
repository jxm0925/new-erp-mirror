<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_sales_order_change_candidates', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('candidate_no', 80)->unique();
            $table->foreignId('sales_order_id')->constrained('erp_sales_orders')->cascadeOnDelete();
            $table->unsignedInteger('base_version');
            $table->unsignedInteger('candidate_version');
            $table->string('candidate_status', 30)->default('PENDING_APPROVAL');
            $table->string('submitted_by', 80)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('change_reason')->nullable();
            $table->json('candidate_order_snapshot');
            $table->json('structured_diffs');
            $table->json('impact_summary');
            $table->json('approval_requirements');
            $table->json('production_impact')->nullable();
            $table->string('activated_by', 80)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->text('conflict_reason')->nullable();
            $table->timestamps();
            $table->unique(['sales_order_id', 'candidate_version'], 'erp_so_candidate_version_unique');
            $table->index(['sales_order_id', 'candidate_status'], 'erp_so_candidate_status_idx');
        });

        Schema::create('erp_sales_order_change_candidate_approvals', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('candidate_id')->constrained('erp_sales_order_change_candidates')->cascadeOnDelete();
            $table->string('approval_type', 30);
            $table->string('approval_status', 30)->default('PENDING');
            $table->string('approver', 80)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['candidate_id', 'approval_type'], 'erp_so_candidate_approval_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_order_change_candidate_approvals');
        Schema::dropIfExists('erp_sales_order_change_candidates');
    }
};
