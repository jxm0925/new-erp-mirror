<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_material_return_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('return_line_id');
            $table->unsignedBigInteger('source_input_holding_id');
            $table->unsignedBigInteger('inventory_transaction_item_id')->nullable();
            $table->decimal('quantity', 18, 8);
            $table->decimal('total_cost', 18, 4);
            $table->timestamps();

            $table->unique(['return_line_id', 'source_input_holding_id'], 'prod_return_cost_line_holding_uq');
            $table->foreign('return_line_id', 'prod_return_cost_line_fk')
                ->references('id')->on('erp_production_material_return_lines')->restrictOnDelete();
            $table->foreign('source_input_holding_id', 'prod_return_cost_holding_fk')
                ->references('id')->on('erp_material_holdings')->restrictOnDelete();
            $table->foreign('inventory_transaction_item_id', 'prod_return_cost_tx_item_fk')
                ->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_material_return_cost_allocations')->exists()) {
            throw new RuntimeException('已有生产退料成本分配事实，禁止删除真实成本追溯。');
        }
        Schema::dropIfExists('erp_production_material_return_cost_allocations');
    }
};
