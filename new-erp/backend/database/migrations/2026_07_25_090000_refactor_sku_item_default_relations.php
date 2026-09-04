<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRIMARY_COLUMN = 'enabled_primary_sku_id';
    private const PRIMARY_INDEX = 'erp_sku_item_one_enabled_primary';

    public function up(): void
    {
        Schema::table('erp_sku_item_relations', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sku_item_relations', 'change_reason')) $table->string('change_reason', 80)->nullable()->after('operator_name');
        });

        if (!Schema::hasTable('erp_sku_item_relation_logs')) {
            Schema::create('erp_sku_item_relation_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sku_id')->index();
                $table->unsignedBigInteger('relation_id')->nullable()->index();
                $table->unsignedBigInteger('old_item_id')->nullable();
                $table->unsignedBigInteger('new_item_id')->nullable();
                $table->string('action', 40);
                $table->string('change_reason', 80)->nullable();
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->string('operator_name', 80)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['sku_id', 'created_at']);
            });
        }

        if (DB::getDriverName() === 'mysql') {
            $this->withoutSelfReferencingForeignKey(function (): void {
                $indexes = collect(DB::select('SHOW INDEX FROM erp_sku_item_relations'))->pluck('Key_name')->all();
                if (in_array(self::PRIMARY_INDEX, $indexes, true)) DB::statement('DROP INDEX ' . self::PRIMARY_INDEX . ' ON erp_sku_item_relations');
                if (in_array(self::PRIMARY_COLUMN, Schema::getColumnListing('erp_sku_item_relations'), true)) DB::statement('ALTER TABLE erp_sku_item_relations DROP COLUMN `' . self::PRIMARY_COLUMN . '`');
                DB::statement("ALTER TABLE erp_sku_item_relations ADD COLUMN `" . self::PRIMARY_COLUMN . "` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `is_primary` = 1 THEN `sku_id` ELSE NULL END) VIRTUAL");
                DB::statement('CREATE UNIQUE INDEX ' . self::PRIMARY_INDEX . ' ON erp_sku_item_relations (`' . self::PRIMARY_COLUMN . '`)');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('erp_sku_item_relations', self::PRIMARY_COLUMN)) {
            $indexes = collect(DB::select('SHOW INDEX FROM erp_sku_item_relations'))->pluck('Key_name')->all();
            if (in_array(self::PRIMARY_INDEX, $indexes, true)) DB::statement('DROP INDEX ' . self::PRIMARY_INDEX . ' ON erp_sku_item_relations');
            DB::statement('ALTER TABLE erp_sku_item_relations DROP COLUMN `' . self::PRIMARY_COLUMN . '`');
        }
        if (DB::getDriverName() === 'mysql' && !in_array(self::PRIMARY_COLUMN, Schema::getColumnListing('erp_sku_item_relations'), true)) {
            DB::statement("ALTER TABLE erp_sku_item_relations ADD COLUMN `" . self::PRIMARY_COLUMN . "` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'enabled' AND `is_primary` = 1 THEN `sku_id` ELSE NULL END) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX ' . self::PRIMARY_INDEX . ' ON erp_sku_item_relations (`' . self::PRIMARY_COLUMN . '`)');
        }
        Schema::dropIfExists('erp_sku_item_relation_logs');
        Schema::table('erp_sku_item_relations', function (Blueprint $table) {
            if (Schema::hasColumn('erp_sku_item_relations', 'change_reason')) $table->dropColumn('change_reason');
        });
    }

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

