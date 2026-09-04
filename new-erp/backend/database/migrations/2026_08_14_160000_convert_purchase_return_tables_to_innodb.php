<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'erp_purchase_returns',
        'erp_purchase_return_items',
        'erp_purchase_return_logs',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }
    }

    public function down(): void
    {
        // InnoDB is required for ERP transaction integrity and must not be downgraded.
    }
};
