<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_orders', 'cancel_reason')) {
                $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('erp_sales_orders', 'cancel_reason')) {
                $table->dropColumn('cancel_reason');
            }
        });
    }
};
