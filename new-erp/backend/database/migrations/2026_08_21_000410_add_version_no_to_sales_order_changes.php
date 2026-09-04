<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_order_changes', function (Blueprint $table): void {
            $table->unsignedInteger('version_no')->nullable()->after('sales_order_id');
            $table->unique(['sales_order_id', 'version_no'], 'erp_soc_order_version_uq');
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_order_changes', function (Blueprint $table): void {
            $table->dropUnique('erp_soc_order_version_uq');
            $table->dropColumn('version_no');
        });
    }
};
