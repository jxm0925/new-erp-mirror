<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_boms', function (Blueprint $table) {
            $table->index(['product_id', 'sku_id', 'output_item_id', 'is_default'], 'erp_bom_prod_scope_default_idx');
            $table->index(['output_item_id', 'status', 'audit_status', 'is_default'], 'erp_bom_output_active_default_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_boms', function (Blueprint $table) {
            $table->dropIndex('erp_bom_prod_scope_default_idx');
            $table->dropIndex('erp_bom_output_active_default_idx');
        });
    }
};
