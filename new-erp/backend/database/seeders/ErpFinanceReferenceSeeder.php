<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceReferenceSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        if (!Schema::hasTable('erp_finance_currencies')) {
            return;
        }

        if (!DB::table('erp_finance_currencies')->where('currency_code', 'CNY')->exists()
            && DB::table('erp_finance_currencies')->where('is_base', true)->exists()) {
            throw new \RuntimeException('已有人工作为本位币的币种，不能自动补入第二个本位币；请通过币种维护明确配置。');
        }

        foreach ([
            ['CNY', '人民币', '¥', 2, true, 1],
            ['USD', '美元', '$', 2, false, 2],
            ['EUR', '欧元', '€', 2, false, 3],
            ['HKD', '港币', 'HK$', 2, false, 4],
            ['GBP', '英镑', '£', 2, false, 5],
        ] as [$code, $name, $symbol, $decimalPlaces, $isBase, $sort]) {
            $this->upsert('erp_finance_currencies', ['currency_code' => $code], [
                'currency_name' => $name,
                'symbol' => $symbol,
                'decimal_places' => $decimalPlaces,
                'is_base' => $isBase,
                'status' => 'enabled',
                'sort' => $sort,
            ]);
        }
    }

    private function upsert(string $table, array $key, array $values): void
    {
        SeedSafety::insertMissing($table, $key, $values);
    }
}

