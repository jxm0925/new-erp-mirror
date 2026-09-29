<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_production_routings', function (Blueprint $table): void {
            $table->string('default_scope_key', 64)->nullable()->change();
        });
        // 不改动默认标志和任何已冻结路线，只把既有唯一约束迁移到真实适用范围。
        DB::statement("UPDATE erp_production_routings SET default_scope_key = CASE WHEN is_default = 1 THEN CONCAT(output_item_id, ':', COALESCE(product_id, 0), ':', COALESCE(sku_id, 0)) ELSE NULL END");
    }

    public function down(): void
    {
        if (DB::table('erp_production_routings')->where('is_default', true)->groupBy('output_item_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('已有同物料不同 SKU 的默认路线，不能退回单一物料默认约束。');
        }
        DB::statement('UPDATE erp_production_routings SET default_scope_key = CASE WHEN is_default = 1 THEN output_item_id ELSE NULL END');
        Schema::table('erp_production_routings', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_scope_key')->nullable()->change();
        });
    }
};
