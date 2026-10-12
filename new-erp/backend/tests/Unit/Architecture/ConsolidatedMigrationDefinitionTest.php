<?php

namespace Tests\Unit\Architecture;

use Database\Schema\InitialTableMigration;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../database/schema/InitialTableMigration.php';

class ConsolidatedMigrationDefinitionTest extends TestCase
{
    public function test_one_complete_definition_per_table_and_no_active_historical_alter_files(): void
    {
        $definitions = InitialTableMigration::definitions();
        $this->assertCount(285, $definitions);
        $this->assertArrayNotHasKey('migrations', $definitions);
        $this->assertCount(285, glob(__DIR__.'/../../../database/migrations/*.php'));
        $columns = $indexes = $foreign = $checks = 0;
        foreach ($definitions as $table => $definition) {
            $sql = $definition::CREATE_SQL;
            $this->assertSame(1, substr_count($sql, 'CREATE TABLE'));
            $this->assertStringStartsWith('CREATE TABLE `'.$table.'` (', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(?:ALTER TABLE|DROP TABLE|INSERT INTO|UPDATE erp_|DELETE FROM|AUTO_INCREMENT=)/i', $sql);
            preg_match_all('/^\s*`([^`]+)`\s+/m', $sql, $names);
            $this->assertCount(count($names[1]), array_unique($names[1]));
            $columns += count($names[1]);
            preg_match_all('/^\s*(?:PRIMARY KEY|UNIQUE KEY|KEY|FULLTEXT KEY|SPATIAL KEY)\b/m', $sql, $keys);
            $indexes += count($keys[0]);
            $foreign += substr_count($sql, 'FOREIGN KEY');
            $checks += count($definition::DECLARED_CHECKS);
            $this->assertSame([], $definition->schemaDifferences($sql, '8.0.12'));
        }
        $this->assertSame(4968, $columns);
        $this->assertSame(1565, $indexes);
        $this->assertSame(750, $foreign);
        $this->assertSame(13, $checks);
    }

    public function test_every_foreign_reference_has_columns_and_supporting_indexes(): void
    {
        $definitions = InitialTableMigration::definitions();
        $constraintNames = [];
        foreach ($definitions as $table => $definition) {
            preg_match_all('/CONSTRAINT `([^`]+)` FOREIGN KEY \(([^)]+)\) REFERENCES `([^`]+)` \(([^)]+)\)([^\n]*)/', $definition::CREATE_SQL, $foreign, PREG_SET_ORDER);
            foreach ($foreign as [, $name, $local, $target, $remote, $actions]) {
                $this->assertArrayNotHasKey($name, $constraintNames, 'FK names must be unique in the schema.');
                $constraintNames[$name] = true;
                $this->assertArrayHasKey($target, $definitions, $table.'.'.$name);
                $localColumns = $this->identifiers($local);
                $targetColumns = $this->identifiers($remote);
                $this->assertCount(count($localColumns), $targetColumns);
                foreach ($localColumns as $column) {
                    $this->assertMatchesRegularExpression('/^\s*'.preg_quote('`'.$column.'`', '/').'\s+/m', $definition::CREATE_SQL);
                    if (str_contains($actions, 'SET NULL')) {
                        $this->assertDoesNotMatchRegularExpression('/^\s*'.preg_quote('`'.$column.'`', '/').'[^\n]*NOT NULL/m', $definition::CREATE_SQL);
                    }
                }
                foreach ($targetColumns as $column) {
                    $this->assertMatchesRegularExpression('/^\s*'.preg_quote('`'.$column.'`', '/').'\s+/m', $definitions[$target]::CREATE_SQL);
                }
                $this->assertTrue($this->hasIndexPrefix($definition::CREATE_SQL, $localColumns), $table.'.'.$name.' local index');
                $this->assertTrue($this->hasIndexPrefix($definitions[$target]::CREATE_SQL, $targetColumns), $table.'.'.$name.' target index');
            }
        }
        $this->assertCount(750, $constraintNames);
    }

    public function test_missing_live_unique_constraint_and_declared_checks_are_not_silently_dropped(): void
    {
        $d = InitialTableMigration::definitions();
        $this->assertStringContainsString('UNIQUE KEY `prod_material_consumption_source_uq` (`output_record_id`,`source_holding_id`)', $d['erp_production_material_consumptions']::CREATE_SQL);
        $checks = $d['erp_material_holdings']::DECLARED_CHECKS;
        $this->assertArrayHasKey('cut_holding_authority_ck', $checks);
        $this->assertStringContainsString('quantity >= 0 AND total_cost >= 0', $checks['cut_holding_authority_ck']);
        $this->assertStringNotContainsString('CHECK', $d['erp_material_holdings']->createSql('8.0.12'));
        $this->assertStringContainsString('cut_holding_authority_ck', $d['erp_material_holdings']->createSql('8.0.16'));
    }

    private function identifiers(string $list): array
    {
        preg_match_all('/`([^`]+)`/', $list, $matches);
        return $matches[1];
    }

    private function hasIndexPrefix(string $sql, array $columns): bool
    {
        preg_match_all('/^\s*(?:PRIMARY KEY|(?:UNIQUE |FULLTEXT |SPATIAL )?KEY `[^`]+`)\s*\(([^\n]+)\)/m', $sql, $matches);
        foreach ($matches[1] as $list) {
            if (array_slice($this->identifiers($list), 0, count($columns)) === $columns) return true;
        }
        return false;
    }
}
