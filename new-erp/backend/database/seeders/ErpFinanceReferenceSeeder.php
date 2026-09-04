<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('erp_finance_currencies')) {
            return;
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
        $exists = DB::table($table)->where($key)->exists();
        $payload = $values + ['updated_at' => now()];
        if (!$exists) {
            $payload['created_at'] = now();
        }
        DB::table($table)->updateOrInsert($key, $payload);
    }
}

