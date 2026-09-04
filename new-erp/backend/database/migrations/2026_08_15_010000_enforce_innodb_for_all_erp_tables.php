<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $tables = DB::select(<<<'SQL'
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME LIKE 'erp\_%'
              AND ENGINE <> 'InnoDB'
            ORDER BY TABLE_NAME
        SQL);

        foreach ($tables as $table) {
            $name = (string) $table->TABLE_NAME;
            if (! preg_match('/\Aerp_[A-Za-z0-9_]+\z/', $name)) {
                throw new RuntimeException("Unsafe ERP table name [{$name}] while enforcing InnoDB.");
            }

            DB::statement("ALTER TABLE `{$name}` ENGINE=InnoDB");
        }
    }

    public function down(): void
    {
        // InnoDB is a permanent transactional invariant. Never downgrade it.
    }
};
