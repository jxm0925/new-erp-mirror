<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_cutting_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('production_task_id')->constrained('erp_production_tasks')->restrictOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('cutting_order_id')->constrained('erp_cutting_orders')->restrictOnDelete();
            $table->foreignId('cutting_task_id')->constrained('erp_cutting_tasks')->restrictOnDelete();
            $table->json('technical_snapshot');
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamps();
            $table->unique(['target_type', 'target_id'], 'production_cutting_target_unique');
            $table->unique('cutting_order_id', 'production_cutting_order_unique');
            $table->unique('cutting_task_id', 'production_cutting_task_unique');
        });
        Schema::create('erp_production_cutting_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('erp_production_cutting_operations')->restrictOnDelete();
            $table->foreignId('settlement_batch_id')->constrained('erp_cutting_settlement_batches')->restrictOnDelete();
            $table->unsignedBigInteger('production_input_holding_id');
            $table->foreign('production_input_holding_id', 'production_cutting_input_holding_fk')->references('id')->on('erp_production_input_holdings')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->decimal('total_cost', 20, 4);
            $table->json('requirement_snapshot');
            $table->timestamps();
            $table->unique('settlement_batch_id', 'production_cutting_batch_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_cutting_operations')->exists()) {
            throw new RuntimeException('已有工序下料事实，不允许删除成本和执行关联。');
        }
        Schema::dropIfExists('erp_production_cutting_inputs');
        Schema::dropIfExists('erp_production_cutting_operations');
    }
};
