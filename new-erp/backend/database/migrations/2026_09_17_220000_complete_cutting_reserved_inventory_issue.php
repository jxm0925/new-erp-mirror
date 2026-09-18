<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_inventory_reservations', function (Blueprint $table): void {
            $table->decimal('issued_total_cost', 18, 4)->default(0);
            $table->decimal('released_qty', 18, 8)->default(0);
            $table->decimal('released_total_cost', 18, 4)->default(0);
            // Restricted output can lose its plan assignment without becoming public stock.
            $table->decimal('released_target_qty', 18, 8)->default(0);
            $table->unsignedInteger('business_version')->default(1);
        });
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('cutting_inventory_reservation_id')->nullable();
            $table->decimal('issue_total_cost', 18, 4)->nullable();
            $table->unsignedBigInteger('material_holding_id')->nullable();
            $table->foreign('cutting_inventory_reservation_id', 'prod_issue_cut_inventory_res_fk')
                ->references('id')->on('erp_cutting_inventory_reservations')->restrictOnDelete();
            $table->foreign('material_holding_id', 'prod_issue_material_holding_fk')
                ->references('id')->on('erp_material_holdings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_internal_issue_lines')->whereNotNull('cutting_inventory_reservation_id')->exists()
            || DB::table('erp_cutting_inventory_reservations')->where('released_qty', '>', 0)->exists()
            || DB::table('erp_cutting_inventory_reservations')->where('released_target_qty', '>', 0)->exists()) {
            throw new RuntimeException('已有正式专用库存领用/释放事实，不允许回退删除追溯结构。');
        }
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->dropForeign('prod_issue_cut_inventory_res_fk');
            $table->dropForeign('prod_issue_material_holding_fk');
            $table->dropColumn(['cutting_inventory_reservation_id', 'issue_total_cost', 'material_holding_id']);
        });
        Schema::table('erp_cutting_inventory_reservations', fn (Blueprint $table) => $table->dropColumn([
            'issued_total_cost', 'released_qty', 'released_total_cost', 'released_target_qty', 'business_version',
        ]));
    }
};
