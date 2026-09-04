<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        $database = DB::getDatabaseName();
        $tables = DB::table('information_schema.tables')
            ->selectRaw('TABLE_NAME AS erp_table_name')
            ->where('table_schema', $database)
            ->where('table_name', 'like', 'erp\_%')
            ->where('engine', '!=', 'InnoDB')
            ->get()
            ->pluck('erp_table_name');

        foreach ($tables as $table) {
            $safeTable = str_replace('`', '``', (string) $table);
            DB::statement("ALTER TABLE `{$safeTable}` ENGINE=InnoDB");
        }
    }

    public function down(): void
    {
        // Transactional storage is a permanent correctness requirement and is not reverted.
    }
};
