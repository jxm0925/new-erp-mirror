<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrationDisciplineTest extends TestCase
{
    public function test_runtime_code_does_not_create_or_alter_business_schema(): void
    {
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../app'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $contents = file_get_contents($file->getPathname());
            if (preg_match('/Schema::(?:create|table|drop|dropIfExists)\s*\(|DB::(?:statement|unprepared)\s*\(\s*[\'\"](?:ALTER|CREATE|DROP)\s+/i', $contents)) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertSame([], $violations, '业务运行时代码禁止建表或修改表结构，必须使用 database/migrations。');
    }

    public function test_purchase_foundation_schema_is_defined_by_a_formal_migration(): void
    {
        $migration = __DIR__.'/../../../database/migrations/2026_08_14_180000_freeze_purchase_finance_and_quality_facts.php';
        $contents = file_get_contents($migration);

        $this->assertFileExists($migration);
        foreach ([
            'original_qualified_base_qty',
            'is_stock_item_snapshot',
            'replacement_received_base_qty',
            'return_amount_excl_tax',
            'purchase_amount_snapshot',
            'source_document_no',
        ] as $column) {
            $this->assertStringContainsString($column, $contents);
        }
    }
}
