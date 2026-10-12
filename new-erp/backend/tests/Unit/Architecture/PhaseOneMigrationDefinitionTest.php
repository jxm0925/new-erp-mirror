<?php

namespace Tests\Unit\Architecture;

use Database\Schema\InitialTableMigration;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../database/schema/InitialTableMigration.php';

/** 全库合并后仍保留第一阶段的正式字段/关联契约；不引导应用或连接数据库。 */
class PhaseOneMigrationDefinitionTest extends TestCase
{
    private const TABLES = [
        'erp_work_order_material_preparations' => 11,
        'erp_work_order_material_preparation_versions' => 8,
        'erp_work_order_preparation_materials' => 14,
        'erp_material_procurement_sources' => 14,
    ];

    public function test_each_phase_one_table_has_one_complete_initial_definition(): void
    {
        $d = InitialTableMigration::definitions();
        foreach (self::TABLES as $table => $count) {
            $sql = $d[$table]->createSql('8.0.12');
            $this->assertStringStartsWith('CREATE TABLE `'.$table.'`', $sql);
            preg_match_all('/^\s*`([a-z0-9_]+)`\s+/m', $sql, $columns);
            $this->assertCount($count, $columns[1]);
            $this->assertCount($count, array_unique($columns[1]));
        }
    }

    public function test_material_identities_and_procurement_foreign_keys_are_preserved(): void
    {
        $d = InitialTableMigration::definitions();
        $this->assertStringContainsString('erp_wompv_version_uq', $d['erp_work_order_material_preparation_versions']::CREATE_SQL);
        $this->assertStringContainsString('erp_wopm_identity_uq', $d['erp_work_order_preparation_materials']::CREATE_SQL);
        $this->assertStringContainsString('erp_wopm_formal_uq', $d['erp_work_order_preparation_materials']::CREATE_SQL);
        $sql = $d['erp_material_procurement_sources']::CREATE_SQL;
        $this->assertSame(7, substr_count($sql, 'FOREIGN KEY'));
        $this->assertStringContainsString('`preparation_material_requirement_id` bigint(20) unsigned DEFAULT NULL', $sql);
        $this->assertStringContainsString('`preparation_version` int(10) unsigned DEFAULT NULL', $sql);
        $this->assertStringContainsString('erp_procurement_prep_fk', $sql);
        $this->assertStringContainsString('ON DELETE SET NULL', $sql);
    }

    public function test_unchanged_existing_definitions_are_accepted_without_a_database(): void
    {
        $d = InitialTableMigration::definitions();
        foreach (self::TABLES as $table => $_) {
            $this->assertSame([], $d[$table]->schemaDifferences($d[$table]::CREATE_SQL, '8.0.12'));
        }
    }

    public function test_wrong_type_nullable_policy_and_missing_foreign_key_are_rejected(): void
    {
        $d = InitialTableMigration::definitions()['erp_material_procurement_sources'];
        $sql = $d::CREATE_SQL;
        $variants = [
            str_replace('`preparation_version` int(10) unsigned', '`preparation_version` int(10)', $sql),
            str_replace('`preparation_version` int(10) unsigned DEFAULT NULL', '`preparation_version` int(10) unsigned NOT NULL', $sql),
            str_replace('ON DELETE SET NULL', 'ON DELETE CASCADE', $sql),
            preg_replace('/,\n\s*CONSTRAINT `erp_procurement_prep_fk`[^\n]+/', '', $sql),
        ];
        foreach ($variants as $changed) {
            $this->assertNotSame($sql, $changed);
            $this->assertNotSame([], $d->schemaDifferences($changed, '8.0.12'));
        }
    }

    public function test_existing_business_tables_cannot_be_dropped_by_rollback(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Automatic rollback');
        InitialTableMigration::definitions()['erp_material_procurement_sources']->down();
    }
}
