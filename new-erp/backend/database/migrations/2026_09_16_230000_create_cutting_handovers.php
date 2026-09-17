<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_result_routes', function (Blueprint $table): void {
            $table->decimal('handed_over_qty', 18, 8)->default(0)->after('quantity');
            $table->decimal('handed_over_cost', 18, 4)->default(0)->after('total_cost');
        });

        Schema::create('erp_cutting_handovers', function (Blueprint $table): void {
            $table->id();
            $table->string('handover_no', 80)->unique();
            $table->foreignId('route_id')->constrained('erp_cutting_result_routes')->restrictOnDelete();
            $table->foreignId('cutting_order_id')->constrained('erp_cutting_orders')->restrictOnDelete();
            $table->foreignId('result_id')->constrained('erp_cutting_results')->restrictOnDelete();
            $table->unsignedBigInteger('target_material_requirement_id');
            $table->unsignedBigInteger('target_task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('source_holding_id')->constrained('erp_material_holdings')->restrictOnDelete();
            $table->foreignId('transit_holding_id')->constrained('erp_material_holdings')->restrictOnDelete();
            $table->decimal('dispatched_qty', 18, 8);
            $table->decimal('dispatched_cost', 18, 4);
            $table->decimal('accepted_qty', 18, 8)->default(0);
            $table->decimal('accepted_cost', 18, 4)->default(0);
            $table->decimal('rejected_qty', 18, 8)->default(0);
            $table->decimal('rejected_cost', 18, 4)->default(0);
            $table->string('status', 24)->default('IN_TRANSIT');
            $table->unsignedBigInteger('expected_receiver_legacy_id');
            $table->unsignedBigInteger('dispatched_by_legacy_id');
            $table->timestamp('dispatched_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
            $table->index(['target_task_id', 'status'], 'cut_handover_target_task_status_idx');
            $table->index(['route_id', 'status'], 'cut_handover_route_status_idx');
            $table->foreign('target_material_requirement_id', 'cut_handover_target_requirement_fk')
                ->references('id')->on('erp_production_target_material_requirements')->restrictOnDelete();
            $table->foreign('target_task_id', 'cut_handover_target_task_fk')
                ->references('id')->on('erp_production_tasks')->restrictOnDelete();
        });

        Schema::create('erp_cutting_handover_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('handover_id')->constrained('erp_cutting_handovers')->restrictOnDelete();
            $table->string('action', 16);
            $table->decimal('quantity', 18, 8);
            $table->decimal('total_cost', 18, 4);
            $table->foreignId('target_holding_id')->nullable()->constrained('erp_material_holdings')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('operator_legacy_id');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['handover_id', 'action', 'occurred_at'], 'cut_handover_decision_history_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('erp_cutting_handovers')->exists() || DB::table('erp_cutting_handover_decisions')->exists()) {
            throw new RuntimeException('已有正式下料交接事实，不允许通过结构回退删除业务历史。');
        }
        Schema::dropIfExists('erp_cutting_handover_decisions');
        Schema::dropIfExists('erp_cutting_handovers');
        Schema::table('erp_cutting_result_routes', function (Blueprint $table): void {
            $table->dropColumn(['handed_over_qty', 'handed_over_cost']);
        });
    }
};
