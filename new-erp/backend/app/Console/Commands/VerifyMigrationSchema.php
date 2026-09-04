<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class VerifyMigrationSchema extends Command
{
    protected $signature = 'erp:verify-migration-schema';

    protected $description = 'Verify that a database built by migrations contains every runtime ERP table and frozen purchase fact column';

    public function handle(): int
    {
        $runtimeTables = $this->runtimeTables();
        $existingTables = collect(Schema::getTableListing())
            ->map(fn (string $name) => str_contains($name, '.') ? last(explode('.', $name)) : $name);
        $missingTables = $runtimeTables->diff($existingTables)->values();

        $expectedColumns = [
            'erp_purchase_orders' => ['amount_excl_tax', 'amount_incl_tax', 'finance_fact_status'],
            'erp_purchase_order_items' => ['currency_snapshot', 'tax_mode_snapshot', 'contract_amount_snapshot', 'commercial_snapshot_at'],
            'erp_purchase_receipts' => ['has_stock_items', 'fulfillment_status', 'settlement_amount', 'inventory_cost_amount'],
            'erp_purchase_receipt_items' => ['is_stock_item_snapshot', 'original_qualified_base_qty', 'original_unqualified_base_qty', 'final_stockable_base_qty', 'replacement_received_base_qty', 'facts_frozen_at'],
            'erp_purchase_returns' => ['source_order_id', 'settlement_effect_type', 'amount_excl_tax', 'tax_amount', 'amount_incl_tax'],
            'erp_purchase_return_items' => ['original_purchase_line_id', 'original_inventory_transaction_id', 'return_amount_excl_tax', 'return_tax_amount', 'return_amount_incl_tax'],
            'erp_purchase_exchange_orders' => ['replacement_received_base_qty', 'replacement_payable_amount', 'finance_fact_status'],
            'erp_inventory_transaction_items' => ['purchase_amount_snapshot', 'cost_source_type'],
            'erp_inventory_reservations' => ['source_document_no', 'reserved_by'],
        ];
        $missingColumns = collect();
        foreach ($expectedColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns->push("{$table}.{$column}");
                }
            }
        }

        $migrationFiles = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME));
        $ran = collect(DB::table('migrations')->pluck('migration'));
        $pendingMigrations = $migrationFiles->diff($ran)->values();

        if ($missingTables->isNotEmpty() || $missingColumns->isNotEmpty() || $pendingMigrations->isNotEmpty()) {
            $this->error('Migration schema verification failed.');
            if ($missingTables->isNotEmpty()) {
                $this->line('Missing tables: '.$missingTables->join(', '));
            }
            if ($missingColumns->isNotEmpty()) {
                $this->line('Missing columns: '.$missingColumns->join(', '));
            }
            if ($pendingMigrations->isNotEmpty()) {
                $this->line('Pending migrations: '.$pendingMigrations->join(', '));
            }

            return self::FAILURE;
        }

        $this->info("Migration schema verified: {$runtimeTables->count()} runtime tables, {$migrationFiles->count()} migrations, no missing purchase fact columns.");

        return self::SUCCESS;
    }

    private function runtimeTables()
    {
        $tables = collect();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            preg_match_all('/protected\s+\$table\s*=\s*[\'\"](erp_[a-z0-9_]+)[\'\"]/', $contents, $models);
            preg_match_all('/(?:DB::table|->(?:join|leftJoin|rightJoin))\s*\(\s*[\'\"](erp_[a-z0-9_]+)(?:\s+as\s+[a-z0-9_]+)?[\'\"]/', $contents, $queries);
            $tables->push(...$models[1], ...$queries[1]);
        }

        return $tables->filter()->unique()->sort()->values();
    }
}
