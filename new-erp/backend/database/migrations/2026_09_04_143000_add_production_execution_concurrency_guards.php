<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_output_records', function (Blueprint $table): void {
            $table->unique(['source_target_type', 'source_target_id'], 'erp_prod_output_source_uq');
        });
        Schema::table('erp_production_operation_handovers', function (Blueprint $table): void {
            $table->unique('output_record_id', 'erp_prod_handover_output_uq');
        });
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->index('issue_task_id', 'erp_prod_issue_task_guard_idx');
            $table->unique(['issue_task_id', 'output_record_id'], 'erp_prod_issue_output_uq');
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            if (! $this->indexExists('erp_production_internal_issue_lines', 'erp_prod_issue_task_guard_idx')) {
                $table->index('issue_task_id', 'erp_prod_issue_task_guard_idx');
            }
            $table->dropUnique('erp_prod_issue_output_uq');
        });
        Schema::table('erp_production_operation_handovers', fn (Blueprint $table) => $table->dropUnique('erp_prod_handover_output_uq'));
        Schema::table('erp_production_output_records', fn (Blueprint $table) => $table->dropUnique('erp_prod_output_source_uq'));
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $row): bool => ($row['name'] ?? null) === $index);
    }
};
