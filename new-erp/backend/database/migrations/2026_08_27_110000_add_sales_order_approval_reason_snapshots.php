<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addJsonColumn('erp_sales_order_change_candidates', 'approval_reasons', 'approval_requirements');
        $this->addJsonColumn('erp_sales_order_changes', 'approval_reasons', 'approval_requirements');
        $this->addJsonColumn('erp_sales_order_versions', 'approval_reasons', 'impact_summary');
    }

    public function down(): void
    {
        foreach (['erp_sales_order_versions', 'erp_sales_order_changes', 'erp_sales_order_change_candidates'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'approval_reasons')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('approval_reasons'));
            }
        }
    }

    private function addJsonColumn(string $table, string $column, string $after): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) return;
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json($column)->nullable()->after($after));
    }

};
