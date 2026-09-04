<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpStandardUnitSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('erp_units')) {
            return;
        }

        $units = [
            'PCS' => ['件', '件', 'quantity', false, 0, true, 10],
            'KG' => ['千克', 'kg', 'weight', true, 3, true, 20],
            'M' => ['米', 'm', 'length', true, 3, true, 30],
            'BAG' => ['包', '包', 'quantity', false, 0, false, 40],
            'ROLL' => ['卷', '卷', 'quantity', false, 0, false, 50],
            'JIN' => ['斤', '斤', 'weight', true, 3, false, 60],
            'EA' => ['个', '个', 'quantity', false, 0, false, 70],
            'ROOT' => ['根', '根', 'quantity', false, 0, false, 80],
        ];

        foreach ($units as $code => [$name, $symbol, $type, $allowDecimal, $decimalPlaces, $isBase, $sort]) {
            $this->upsert('erp_units', ['unit_code' => $code], [
                'unit_name' => $name,
                'symbol' => $symbol,
                'unit_type' => $type,
                'allow_decimal' => $allowDecimal,
                'decimal_places' => $decimalPlaces,
                'is_base' => $isBase,
                'is_legacy' => false,
                'standard_unit_id' => null,
                'sort_order' => $sort,
                'status' => 'enabled',
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

