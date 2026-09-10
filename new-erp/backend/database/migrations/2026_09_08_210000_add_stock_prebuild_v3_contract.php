<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->string('stocking_purpose', 40)->nullable()->after('source_title_snapshot');
            $table->unsignedBigInteger('reserved_for_work_order_id')->nullable()->after('stocking_purpose');
            $table->unsignedBigInteger('reserved_for_production_unit_id')->nullable()->after('reserved_for_work_order_id');
            $table->unsignedBigInteger('reserved_for_target_operation_id')->nullable()->after('reserved_for_production_unit_id');
            $table->string('configured_output_mode_snapshot', 30)->nullable()->after('reserved_for_target_operation_id');
            $table->string('effective_output_mode_snapshot', 30)->nullable()->after('configured_output_mode_snapshot');
            $table->unsignedBigInteger('effective_output_item_id_snapshot')->nullable()->after('effective_output_mode_snapshot');
            $table->index(['stocking_purpose', 'status'], 'erp_wo_stocking_purpose_status_idx');
            $table->index(['reserved_for_work_order_id', 'reserved_for_target_operation_id'], 'erp_wo_reserved_target_idx');
            $table->foreign('reserved_for_work_order_id', 'erp_wo_reserved_work_order_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('reserved_for_production_unit_id', 'erp_wo_reserved_unit_fk')->references('id')->on('erp_production_units')->restrictOnDelete();
            $table->foreign('reserved_for_target_operation_id', 'erp_wo_reserved_operation_fk')->references('id')->on('erp_production_routing_operations')->restrictOnDelete();
            $table->foreign('effective_output_item_id_snapshot', 'erp_wo_effective_output_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->dropForeign('erp_wo_reserved_work_order_fk');
            $table->dropForeign('erp_wo_reserved_unit_fk');
            $table->dropForeign('erp_wo_reserved_operation_fk');
            $table->dropForeign('erp_wo_effective_output_item_fk');
            $table->dropIndex('erp_wo_stocking_purpose_status_idx');
            $table->dropIndex('erp_wo_reserved_target_idx');
            $table->dropColumn(['stocking_purpose', 'reserved_for_work_order_id', 'reserved_for_production_unit_id',
                'reserved_for_target_operation_id', 'configured_output_mode_snapshot', 'effective_output_mode_snapshot',
                'effective_output_item_id_snapshot']);
        });
    }
};
