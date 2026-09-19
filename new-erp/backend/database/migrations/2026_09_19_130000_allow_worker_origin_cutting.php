<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE erp_cutting_orders MODIFY published_at TIMESTAMP NULL');
    }

    public function down(): void
    {
        if (DB::table('erp_cutting_orders')->whereNull('published_at')->exists())
            throw new RuntimeException('自主下料事实存在，不能回退为必须发布的旧结构。');
        DB::statement('ALTER TABLE erp_cutting_orders MODIFY published_at TIMESTAMP NOT NULL');
    }
};
