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

    public function test_purchase_foundation_schema_is_defined_by_formal_initial_migrations(): void
    {
        $base = __DIR__.'/../../../database';
        $manifest = json_decode(file_get_contents($base.'/schema/baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            'erp_purchase_receipt_items' => ['original_qualified_base_qty', 'is_stock_item_snapshot'],
            'erp_purchase_exchange_orders' => ['replacement_received_base_qty'],
            'erp_purchase_return_items' => ['return_amount_excl_tax'],
            'erp_inventory_transaction_items' => ['purchase_amount_snapshot'],
            'erp_inventory_reservations' => ['source_document_no'],
        ] as $table => $columns) {
            $migration = $base.'/migrations/'.$manifest['tables'][$table].'.php';
            $this->assertFileExists($migration);
            $contents = file_get_contents($migration);
            foreach ($columns as $column) {
                $this->assertStringContainsString(chr(96).$column.chr(96), $contents);
            }
        }
    }

}
