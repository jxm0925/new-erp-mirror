<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['erp_products', 'erp_skus', 'erp_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'legacy_payload')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('legacy_payload'));
            }
        }
        Schema::table('erp_skus', function (Blueprint $table) {
            $columns = array_values(array_filter(['legacy_sku_code', 'legacy_sku_id'], fn (string $column) => Schema::hasColumn('erp_skus', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: retired legacy source fields stay removed.
    }
};
