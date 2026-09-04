<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_skus', 'supports_electric')) {
                $table->boolean('supports_electric')->default(false)->after('is_customizable');
            }
            if (!Schema::hasColumn('erp_skus', 'electric_required')) {
                $table->boolean('electric_required')->default(false)->after('supports_electric');
            }
            if (!Schema::hasColumn('erp_skus', 'electric_options')) {
                $table->json('electric_options')->nullable()->after('electric_required');
            }
            if (!Schema::hasColumn('erp_skus', 'supports_need_pump')) {
                $table->boolean('supports_need_pump')->default(false)->after('electric_options');
            }
            if (!Schema::hasColumn('erp_skus', 'need_pump_required')) {
                $table->boolean('need_pump_required')->default(false)->after('supports_need_pump');
            }
        });

        // Null means "not selected". False remains the explicit "not needed" option.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('erp_sales_order_lines', function (Blueprint $table) {
                $table->boolean('need_pump')->nullable()->default(null)->change();
            });
        } else {
            DB::statement('ALTER TABLE erp_sales_order_lines MODIFY need_pump TINYINT(1) NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('erp_sales_order_lines', function (Blueprint $table) {
                $table->boolean('need_pump')->nullable(false)->default(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE erp_sales_order_lines MODIFY need_pump TINYINT(1) NOT NULL DEFAULT 0');
        }
        Schema::table('erp_skus', function (Blueprint $table) {
            $columns = ['need_pump_required', 'supports_need_pump', 'electric_options', 'electric_required', 'supports_electric'];
            $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('erp_skus', $column)));
            if ($existing) $table->dropColumn($existing);
        });
    }
};
