<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMN = 'enabled_primary_sku_id';
    private const INDEX = 'erp_sku_item_one_enabled_primary';

    public function up(): void
    {
        // MySQL has no partial unique index. A generated nullable column gives
        // enabled primary rows the SKU id and all other rows NULL (which may repeat).
        // This is the database-level last line of defence behind the controller lock.
        if (DB::getDriverName() !== 'mysql') return;

        $columns = Schema::getColumnListing('erp_sku_item_relations');
        if (!in_array(self::COLUMN, $columns, true)) {
            $this->withoutSelfReferencingForeignKey(function (): void {
                DB::statement("ALTER TABLE erp_sku_item_relations ADD COLUMN `" . self::COLUMN . "` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'enabled' AND `is_primary` = 1 THEN `sku_id` ELSE NULL END) VIRTUAL");
            });
        }

        $indexes = collect(DB::select('SHOW INDEX FROM erp_sku_item_relations'))->pluck('Key_name')->all();
        if (!in_array(self::INDEX, $indexes, true)) {
            DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON erp_sku_item_relations (`' . self::COLUMN . '`)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        $indexes = collect(DB::select('SHOW INDEX FROM erp_sku_item_relations'))->pluck('Key_name')->all();
        if (in_array(self::INDEX, $indexes, true)) {
            DB::statement('DROP INDEX ' . self::INDEX . ' ON erp_sku_item_relations');
        }
        if (in_array(self::COLUMN, Schema::getColumnListing('erp_sku_item_relations'), true)) {
            DB::statement('ALTER TABLE erp_sku_item_relations DROP COLUMN `' . self::COLUMN . '`');
        }
    }

    /**
     * MySQL 8.0.12 rebuilds the table while adding a stored generated column
     * and fails to revalidate a self-referencing foreign key (error 1215).
     * Keep the constraint, but remove it only for the duration of the rebuild.
     */
    private function withoutSelfReferencingForeignKey(callable $callback): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        $foreignKey = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS constraint_name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME = ?
             LIMIT 1',
            ['erp_sku_item_relations', 'base_relation_id', 'erp_sku_item_relations']
        );

        $constraintName = $foreignKey?->constraint_name;
        try {
            if ($constraintName !== null) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $constraintName)) {
                    throw new RuntimeException('Invalid self-referencing foreign key name.');
                }
                DB::statement("ALTER TABLE erp_sku_item_relations DROP FOREIGN KEY `{$constraintName}`");
            }

            try {
                $callback();
            } finally {
                if ($constraintName !== null) {
                    DB::statement(
                        "ALTER TABLE erp_sku_item_relations
                         ADD CONSTRAINT `{$constraintName}`
                         FOREIGN KEY (`base_relation_id`) REFERENCES `erp_sku_item_relations` (`id`)
                         ON DELETE SET NULL"
                    );
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
};
