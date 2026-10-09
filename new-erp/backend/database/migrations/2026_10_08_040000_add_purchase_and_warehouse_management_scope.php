<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $documents = [
        'erp_purchase_requests' => ['erp_purchase_request_items', 'request_id'],
        'erp_purchase_plans' => ['erp_purchase_plan_items', 'plan_id'],
        'erp_purchase_orders' => ['erp_purchase_order_items', 'order_id'],
        'erp_purchase_receipts' => ['erp_purchase_receipt_items', 'receipt_id'],
        'erp_purchase_returns' => ['erp_purchase_return_items', 'return_id'],
        'erp_purchase_exchange_orders' => null,
    ];

    public function up(): void
    {
        foreach (array_merge(array_keys($this->documents), ['erp_warehouses']) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->string('management_scope', 16)->nullable();
                $blueprint->index('management_scope', $table.'_management_scope_idx');
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_scope_ck CHECK (management_scope IS NULL OR management_scope IN ('factory','office'))");
        }
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            $table->string('management_scope_snapshot', 16)->nullable();
        });
        DB::statement("ALTER TABLE erp_purchase_receipt_items ADD CONSTRAINT erp_purchase_receipt_items_scope_ck CHECK (management_scope_snapshot IS NULL OR management_scope_snapshot IN ('factory','office'))");

        // This migration only adds ownership facts. It never splits, deletes or
        // rewrites historical lines. Missing materials, empty documents and mixed
        // documents remain explicitly unresolved instead of borrowing a default.
        foreach ($this->documents as $table => $lineConfig) {
            DB::table($table)->select('id')->orderBy('id')->chunkById(200, function ($documents) use ($table, $lineConfig) {
                foreach ($documents as $document) {
                    $rows = $lineConfig
                        ? DB::table($lineConfig[0].' as l')->leftJoin('erp_items as i', 'i.id', '=', 'l.item_id')
                            ->where('l.'.$lineConfig[1], $document->id)->get(['i.management_scope'])
                        : DB::table($table.' as d')->leftJoin('erp_items as i', 'i.id', '=', 'd.item_id')
                            ->where('d.id', $document->id)->get(['i.management_scope']);
                    $scope = $this->uniqueScope($rows->pluck('management_scope')->all());
                    if ($scope !== null) DB::table($table)->where('id', $document->id)->update(['management_scope' => $scope]);
                }
            });
        }
        // Only a fully resolved receipt can acquire a scope snapshot. An unresolved
        // historical receipt retains null snapshots and cannot be positively posted.
        DB::table('erp_purchase_receipts')->whereNotNull('management_scope')->orderBy('id')
            ->chunkById(200, function ($receipts) {
                foreach ($receipts as $receipt) DB::table('erp_purchase_receipt_items')->where('receipt_id', $receipt->id)
                    ->update(['management_scope_snapshot' => $receipt->management_scope]);
            });
        DB::table('erp_warehouses')->select('id')->orderBy('id')->chunkById(200, function ($warehouses) {
            foreach ($warehouses as $warehouse) {
                $scopes = [];
                foreach (['erp_inventory_balances', 'erp_inventory_location_balances'] as $table) {
                    if (!Schema::hasTable($table)) continue;
                    $scopes = array_merge($scopes, DB::table($table.' as b')->leftJoin('erp_items as i', 'i.id', '=', 'b.item_id')
                        ->where('b.warehouse_id', $warehouse->id)->where('b.quantity_on_hand', '>', 0)
                        ->pluck('i.management_scope')->all());
                }
                $scope = $this->uniqueScope($scopes);
                if ($scope !== null) DB::table('erp_warehouses')->where('id', $warehouse->id)->update(['management_scope' => $scope]);
            }
        });
    }

    private function uniqueScope(array $values): ?string
    {
        if (!$values || array_filter($values, fn ($scope) => !in_array($scope, ['factory', 'office'], true))) return null;
        $scopes = array_values(array_unique($values));
        return count($scopes) === 1 ? $scopes[0] : null;
    }

    public function down(): void
    {
        foreach (array_merge(array_keys($this->documents), ['erp_warehouses']) as $table) {
            if (DB::table($table)->where('management_scope', 'office')->exists()) {
                throw new RuntimeException('办公采购或办公仓库隔离已被使用，禁止自动移除范围约束。');
            }
        }
        DB::statement('ALTER TABLE erp_purchase_receipt_items DROP CHECK erp_purchase_receipt_items_scope_ck');
        Schema::table('erp_purchase_receipt_items', fn (Blueprint $table) => $table->dropColumn('management_scope_snapshot'));
        foreach (array_merge(array_keys($this->documents), ['erp_warehouses']) as $table) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$table}_scope_ck");
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_management_scope_idx');
                $blueprint->dropColumn('management_scope');
            });
        }
    }
};
