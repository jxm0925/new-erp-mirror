<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ErpBusinessLineSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        foreach ([
            ['BL001', '农改水', 10],
            ['BL002', '供水设备', 20],
            ['BL003', '净水设备', 30],
            ['BL004', '家用净水', 40],
        ] as [$code, $name, $sort]) {
            $identity = fn () => DB::table('erp_business_lines')->where('code', $code)->orWhere('name', $name);
            // 编码或名称任一被人工维护过都保留，不以另一项缺失为由重建默认档案。
            if ($identity()->exists()) continue;
            try {
                DB::table('erp_business_lines')->insert([
                    'code' => $code, 'name' => $name, 'sort_order' => $sort, 'status' => 'enabled',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $error) {
                // 并发补缺只接纳已真实存在的同编码/同名称；其他写入错误仍使整次补缺回滚。
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062 || ! $identity()->lockForUpdate()->exists()) throw $error;
            }
        }
    }
}
