<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_input_holdings', function (Blueprint $table): void {
            $table->unsignedBigInteger('inventory_transaction_item_id')->nullable()->after('source_output_record_id');
            $table->unsignedBigInteger('material_receipt_line_id')->nullable()->after('internal_issue_line_id');
            $table->foreign('inventory_transaction_item_id', 'prod_input_inventory_item_fk')
                ->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
            $table->foreign('material_receipt_line_id', 'prod_input_receipt_line_fk')
                ->references('id')->on('erp_material_receipt_lines')->restrictOnDelete();
            $table->unique('material_receipt_line_id', 'prod_input_receipt_line_uq');
        });
        Schema::table('erp_production_input_holdings', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_output_record_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_input_holdings')->whereNotNull('material_receipt_line_id')->exists()) {
            throw new RuntimeException('已有采购件生产投入成本事实，禁止移除正式出库与收料追溯。');
        }
        Schema::table('erp_production_input_holdings', function (Blueprint $table): void {
            $table->dropUnique('prod_input_receipt_line_uq');
            $table->dropForeign('prod_input_inventory_item_fk');
            $table->dropForeign('prod_input_receipt_line_fk');
            $table->dropColumn(['inventory_transaction_item_id', 'material_receipt_line_id']);
        });
        Schema::table('erp_production_input_holdings', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_output_record_id')->nullable(false)->change();
        });
    }
};
