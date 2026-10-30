<?php

namespace Database\Seeders;

use App\Services\Erp\DocumentNumberService;
use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 可通过 php artisan db:seed --class=ErpTestWarehouseSeeder 仅补仓库；不分配负责人或创建库位、库存。 */
class ErpTestWarehouseSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        foreach (['原材料仓' => 'raw_material', '成品仓' => 'finished_goods'] as $name => $type) {
            $matches = DB::table('erp_warehouses')->where('warehouse_name', $name)->lockForUpdate()->get();
            if ($matches->count() > 1) {
                throw new RuntimeException('仓库名称存在歧义：'.$name.'，仓库补缺已回滚。');
            }
            if ($matches->isNotEmpty()) {
                $warehouse = $matches->first();
                // 综合仓允许既有人工配置；范围或专用用途冲突时不能按名称误认并覆盖。
                if ($warehouse->management_scope !== 'factory'
                    || !in_array($warehouse->warehouse_type, [$type, 'general'], true)) {
                    throw new RuntimeException('仓库 '.$name.' 的管理范围或类型需人工核对；保留现有记录，仓库补缺已回滚。');
                }
                continue;
            }

            DB::table('erp_warehouses')->insert([
                'warehouse_code' => app(DocumentNumberService::class)->next('warehouse'),
                'warehouse_name' => $name,
                'warehouse_type' => $type,
                'management_scope' => 'factory',
                'status' => 'enabled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
