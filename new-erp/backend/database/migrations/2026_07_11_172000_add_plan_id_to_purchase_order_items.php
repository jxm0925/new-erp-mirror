<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_order_items', 'plan_id')) {
                $table->foreignId('plan_id')->nullable()->after('order_id')->constrained('erp_purchase_plans')->nullOnDelete();
            }
        });

    }

    public function down(): void
    {
        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('erp_purchase_order_items', 'plan_id')) {
                $table->dropConstrainedForeignId('plan_id');
            }
        });
    }
};

