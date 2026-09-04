<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_products', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_products', 'brand')) $table->string('brand', 100)->nullable()->after('unit_id');
            if (!Schema::hasColumn('erp_products', 'origin')) $table->string('origin', 120)->nullable()->after('brand');
        });
    }

    public function down(): void
    {
        Schema::table('erp_products', function (Blueprint $table) {
            $columns = array_values(array_filter(['brand', 'origin'], fn (string $column) => Schema::hasColumn('erp_products', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};
