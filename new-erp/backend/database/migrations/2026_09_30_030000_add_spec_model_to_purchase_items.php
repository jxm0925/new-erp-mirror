<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('erp_purchase_request_items') && !Schema::hasColumn('erp_purchase_request_items', 'spec_model')) {
            Schema::table('erp_purchase_request_items', function (Blueprint $table) {
                $table->string('spec_model', 255)->nullable()->after('item_name');
            });
        }

        if (Schema::hasTable('erp_purchase_plan_items') && !Schema::hasColumn('erp_purchase_plan_items', 'spec_model')) {
            Schema::table('erp_purchase_plan_items', function (Blueprint $table) {
                $table->string('spec_model', 255)->nullable()->after('item_id');
            });
        }

        if (Schema::hasTable('erp_purchase_order_items') && !Schema::hasColumn('erp_purchase_order_items', 'spec_model')) {
            Schema::table('erp_purchase_order_items', function (Blueprint $table) {
                $table->string('spec_model', 255)->nullable()->after('item_id');
            });
        }

        // Backfill spec_model for existing rows from erp_items
        foreach (['erp_purchase_request_items', 'erp_purchase_plan_items', 'erp_purchase_order_items'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            $rows = DB::table($tableName)->whereNull('spec_model')->get();
            foreach ($rows as $row) {
                $item = DB::table('erp_items')->where('id', $row->item_id)->first();
                if ($item) {
                    $spec = !empty($item->spec) ? $item->spec : (!empty($item->model) ? $item->model : null);
                    if ($spec !== null) {
                        DB::table($tableName)->where('id', $row->id)->update(['spec_model' => $spec]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('erp_purchase_request_items') && Schema::hasColumn('erp_purchase_request_items', 'spec_model')) {
            Schema::table('erp_purchase_request_items', function (Blueprint $table) {
                $table->dropColumn('spec_model');
            });
        }

        if (Schema::hasTable('erp_purchase_plan_items') && Schema::hasColumn('erp_purchase_plan_items', 'spec_model')) {
            Schema::table('erp_purchase_plan_items', function (Blueprint $table) {
                $table->dropColumn('spec_model');
            });
        }

        if (Schema::hasTable('erp_purchase_order_items') && Schema::hasColumn('erp_purchase_order_items', 'spec_model')) {
            Schema::table('erp_purchase_order_items', function (Blueprint $table) {
                $table->dropColumn('spec_model');
            });
        }
    }
};
