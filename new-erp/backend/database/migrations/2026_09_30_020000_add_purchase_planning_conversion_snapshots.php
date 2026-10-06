<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['erp_purchase_request_items', 'erp_purchase_plan_items', 'erp_purchase_plan_supplier_splits', 'erp_purchase_order_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // NULL marks a historical line. Never reconstruct historical facts from today's master data.
                $table->json('purchase_conversion_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['erp_purchase_request_items', 'erp_purchase_plan_items', 'erp_purchase_plan_supplier_splits', 'erp_purchase_order_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('purchase_conversion_snapshot'));
        }
    }
};
