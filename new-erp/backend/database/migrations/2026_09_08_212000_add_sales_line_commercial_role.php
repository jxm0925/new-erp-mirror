<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('erp_sales_order_lines', 'commercial_role')) {
            Schema::table('erp_sales_order_lines', function (Blueprint $table): void {
                $table->string('commercial_role', 20)->default('sale')->after('line_type')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_sales_order_lines', 'commercial_role')) {
            Schema::table('erp_sales_order_lines', function (Blueprint $table): void {
                $table->dropIndex(['commercial_role']);
                $table->dropColumn('commercial_role');
            });
        }
    }
};
