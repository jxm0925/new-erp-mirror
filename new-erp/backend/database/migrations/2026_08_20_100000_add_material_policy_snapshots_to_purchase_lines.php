<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'erp_purchase_request_items',
            'erp_purchase_plan_items',
            'erp_purchase_order_items',
            'erp_purchase_receipt_items',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'material_policy_id_snapshot')) {
                    $table->unsignedBigInteger('material_policy_id_snapshot')->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'material_policy_version_snapshot')) {
                    $table->unsignedInteger('material_policy_version_snapshot')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'material_policy_snapshot')) {
                    $table->json('material_policy_snapshot')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'erp_purchase_receipt_items',
            'erp_purchase_order_items',
            'erp_purchase_plan_items',
            'erp_purchase_request_items',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = array_values(array_filter([
                    Schema::hasColumn($tableName, 'material_policy_id_snapshot') ? 'material_policy_id_snapshot' : null,
                    Schema::hasColumn($tableName, 'material_policy_version_snapshot') ? 'material_policy_version_snapshot' : null,
                    Schema::hasColumn($tableName, 'material_policy_snapshot') ? 'material_policy_snapshot' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
