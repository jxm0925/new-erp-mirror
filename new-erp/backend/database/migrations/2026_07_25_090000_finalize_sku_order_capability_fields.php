<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_skus', 'allow_customized')) $table->boolean('allow_customized')->default(false)->after('is_customizable');
            if (!Schema::hasColumn('erp_skus', 'allow_special_customized')) $table->boolean('allow_special_customized')->default(false)->after('allow_customized');
            if (!Schema::hasColumn('erp_skus', 'special_custom_drawing_required')) $table->boolean('special_custom_drawing_required')->default(false)->after('allow_special_customized');
            if (!Schema::hasColumn('erp_skus', 'special_custom_agreement_required')) $table->boolean('special_custom_agreement_required')->default(false)->after('special_custom_drawing_required');
            if (!Schema::hasColumn('erp_skus', 'special_custom_description_required')) $table->boolean('special_custom_description_required')->default(false)->after('special_custom_agreement_required');
            if (!Schema::hasColumn('erp_skus', 'delivery_inspection_required')) $table->boolean('delivery_inspection_required')->default(false)->after('special_custom_description_required');
        });

    }

    public function down(): void
    {
        Schema::table('erp_skus', function (Blueprint $table) {
            $columns = array_values(array_filter([
                'delivery_inspection_required', 'special_custom_description_required',
                'special_custom_agreement_required', 'special_custom_drawing_required',
                'allow_special_customized', 'allow_customized',
            ], fn (string $column) => Schema::hasColumn('erp_skus', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};

