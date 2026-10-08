<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('erp_production_operations', 'is_public')) {
            Schema::table('erp_production_operations', function (Blueprint $table): void {
                $table->boolean('is_public')->default(false);
            });
        }
        foreach (['erp_production_unit_operations', 'erp_production_quantity_operations', 'erp_production_tasks', 'erp_shipment_packing_operations'] as $name) {
            if (! Schema::hasColumn($name, 'is_public_snapshot')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->boolean('is_public_snapshot')->default(false);
                });
            }
        }
        if (! Schema::hasIndex('erp_production_operations', 'prod_operation_public_status_idx')) {
            Schema::table('erp_production_operations', fn (Blueprint $table) => $table->index(['is_public', 'status'], 'prod_operation_public_status_idx'));
        }
        if (! Schema::hasIndex('erp_production_tasks', 'prod_task_public_status_idx')) {
            Schema::table('erp_production_tasks', fn (Blueprint $table) => $table->index(['is_public_snapshot', 'status'], 'prod_task_public_status_idx'));
        }

        // 只调整表结构。历史记录保留数据库默认非公共，不更新任何已有业务数据或路线快照。
    }

    public function down(): void
    {
        if (Schema::hasIndex('erp_production_tasks', 'prod_task_public_status_idx')) {
            Schema::table('erp_production_tasks', fn (Blueprint $table) => $table->dropIndex('prod_task_public_status_idx'));
        }
        if (Schema::hasIndex('erp_production_operations', 'prod_operation_public_status_idx')) {
            Schema::table('erp_production_operations', fn (Blueprint $table) => $table->dropIndex('prod_operation_public_status_idx'));
        }
        foreach (['erp_production_unit_operations', 'erp_production_quantity_operations', 'erp_production_tasks', 'erp_shipment_packing_operations'] as $name) {
            if (Schema::hasColumn($name, 'is_public_snapshot')) Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_public_snapshot'));
        }
        if (Schema::hasColumn('erp_production_operations', 'is_public')) Schema::table('erp_production_operations', fn (Blueprint $table) => $table->dropColumn('is_public'));
    }
};
