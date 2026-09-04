<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendProductionDemands();
        $this->createWorkOrders();
        $this->createStatusLogs();
        $this->createCommandLedgers();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_work_order_command_ledgers');
        Schema::dropIfExists('erp_work_order_status_logs');
        Schema::dropIfExists('erp_work_orders');

        if (! Schema::hasTable('erp_sales_order_production_requirements')) {
            return;
        }

        $this->dropForeignIfExists(
            'erp_sales_order_production_requirements',
            'erp_prod_req_superseded_by_fk'
        );
        $this->dropIndexIfExists(
            'erp_sales_order_production_requirements',
            'erp_prod_req_line_active_status_idx'
        );
        $this->dropIndexIfExists(
            'erp_sales_order_production_requirements',
            'erp_prod_req_superseded_by_idx'
        );

        $columns = [
            'requirement_version',
            'is_active',
            'consumed_qty',
            'allocated_qty',
            'remaining_qty',
            'closed_qty',
            'superseded_by_id',
            'superseded_reason',
            'business_version',
        ];
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('erp_sales_order_production_requirements', $column)
        ));

        if ($existing !== []) {
            Schema::table('erp_sales_order_production_requirements', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }

    private function extendProductionDemands(): void
    {
        if (! Schema::hasTable('erp_sales_order_production_requirements')) {
            throw new RuntimeException('生产需求基础表不存在，无法创建工单领域结构。');
        }

        $columns = [
            'requirement_version' => fn (Blueprint $table) => $table->unsignedInteger('requirement_version')->default(1),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'consumed_qty' => fn (Blueprint $table) => $table->decimal('consumed_qty', 14, 4)->default(0),
            'allocated_qty' => fn (Blueprint $table) => $table->decimal('allocated_qty', 14, 4)->default(0),
            'remaining_qty' => fn (Blueprint $table) => $table->decimal('remaining_qty', 14, 4)->default(0),
            'closed_qty' => fn (Blueprint $table) => $table->decimal('closed_qty', 14, 4)->default(0),
            'superseded_by_id' => fn (Blueprint $table) => $table->unsignedBigInteger('superseded_by_id')->nullable(),
            'superseded_reason' => fn (Blueprint $table) => $table->text('superseded_reason')->nullable(),
            'business_version' => fn (Blueprint $table) => $table->unsignedInteger('business_version')->default(1),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('erp_sales_order_production_requirements', $name)) {
                Schema::table('erp_sales_order_production_requirements', $definition);
            }
        }

        $this->ensureIndex(
            'erp_sales_order_production_requirements',
            'erp_prod_req_line_active_status_idx',
            ['sales_order_line_id', 'is_active', 'requirement_status']
        );
        $this->ensureIndex(
            'erp_sales_order_production_requirements',
            'erp_prod_req_superseded_by_idx',
            ['superseded_by_id']
        );
        $this->ensureForeign(
            'erp_sales_order_production_requirements',
            'erp_prod_req_superseded_by_fk',
            'superseded_by_id',
            'erp_sales_order_production_requirements',
            true
        );
    }

    private function createWorkOrders(): void
    {
        if (! Schema::hasTable('erp_work_orders')) {
            Schema::create('erp_work_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('work_order_no', 80);
                $table->unsignedBigInteger('production_demand_id');
                $table->string('origin_command_id', 120)->nullable();
                $table->string('last_command_id', 120)->nullable();
                $table->unsignedBigInteger('target_unit_id')->nullable();
                $table->string('target_unit_name_snapshot', 80)->nullable();
                $table->decimal('target_qty', 14, 4);
                $table->decimal('target_base_qty', 18, 8)->default(0);
                $table->unsignedBigInteger('base_unit_id')->nullable();
                $table->string('base_unit_name_snapshot', 80)->nullable();
                $table->date('planned_date')->nullable();
                $table->string('production_batch', 120)->nullable();
                $table->unsignedBigInteger('responsible_user_legacy_id')->nullable();
                $table->string('production_location_name', 160)->nullable();
                $table->unsignedBigInteger('bom_id')->nullable();
                $table->unsignedBigInteger('bom_version_id')->nullable();
                $table->string('bom_version', 80)->nullable();
                $table->string('status', 30)->default('DRAFT');
                $table->unsignedInteger('business_version')->default(1);
                $table->string('organization_code', 80)->nullable();
                $table->unsignedBigInteger('created_by_legacy_id')->nullable();
                $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
                $table->unsignedBigInteger('cancelled_by_legacy_id')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->unique('work_order_no', 'erp_work_orders_work_order_no_unique');
                $table->index(['production_demand_id', 'status'], 'erp_work_order_demand_status_idx');
                $table->index(['responsible_user_legacy_id', 'status'], 'erp_work_order_responsible_status_idx');
                $table->index('origin_command_id', 'erp_work_order_origin_command_idx');
                $table->index('last_command_id', 'erp_work_order_last_command_idx');
            });
        }

        $this->ensureForeign(
            'erp_work_orders',
            'erp_work_order_demand_fk',
            'production_demand_id',
            'erp_sales_order_production_requirements'
        );
        $this->ensureForeign('erp_work_orders', 'erp_work_order_target_unit_fk', 'target_unit_id', 'erp_units', true);
        $this->ensureForeign('erp_work_orders', 'erp_work_order_base_unit_fk', 'base_unit_id', 'erp_units', true);
        $this->ensureForeign('erp_work_orders', 'erp_work_order_bom_fk', 'bom_id', 'erp_boms', true);
    }

    private function createStatusLogs(): void
    {
        if (! Schema::hasTable('erp_work_order_status_logs')) {
            Schema::create('erp_work_order_status_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('work_order_id');
                $table->string('before_status', 30)->nullable();
                $table->string('after_status', 30);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('operator_legacy_id')->nullable();
                $table->string('operator_name', 120)->nullable();
                $table->string('organization_code', 80)->nullable();
                $table->unsignedInteger('before_version')->default(0);
                $table->unsignedInteger('after_version');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['work_order_id', 'occurred_at'], 'erp_work_order_status_log_lookup');
            });
        }

        $this->ensureForeign(
            'erp_work_order_status_logs',
            'erp_work_order_status_log_fk',
            'work_order_id',
            'erp_work_orders'
        );
    }

    private function createCommandLedgers(): void
    {
        if (! Schema::hasTable('erp_work_order_command_ledgers')) {
            Schema::create('erp_work_order_command_ledgers', function (Blueprint $table): void {
                $table->id();
                $table->string('client_command_id', 120);
                $table->string('command_type', 50);
                $table->string('aggregate_type', 50)->default('work_order');
                $table->unsignedBigInteger('aggregate_id')->nullable();
                $table->string('request_hash', 128);
                $table->string('status', 20)->default('processing');
                $table->string('result_type', 50)->nullable();
                $table->unsignedBigInteger('result_id')->nullable();
                $table->json('response_snapshot')->nullable();
                $table->string('error_code', 60)->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('initiated_by_legacy_id')->nullable();
                $table->string('organization_code', 80)->nullable();
                $table->timestamp('processing_started_at')->nullable();
                $table->timestamp('processing_finished_at')->nullable();
                $table->timestamps();

                $table->unique('client_command_id', 'erp_work_order_command_client_unique');
                $table->index(
                    ['aggregate_type', 'aggregate_id', 'command_type'],
                    'erp_work_order_command_aggregate_idx'
                );
                $table->index(['status', 'processing_started_at'], 'erp_work_order_command_recovery_idx');
            });
        }

        $this->ensureForeign(
            'erp_work_order_command_ledgers',
            'erp_work_order_command_result_fk',
            'result_id',
            'erp_work_orders',
            true
        );
    }

    private function ensureIndex(string $table, string $name, array $columns): void
    {
        if (collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name
        )) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function ensureForeign(
        string $table,
        string $name,
        string $column,
        string $referencedTable,
        bool $nullOnDelete = false
    ): void {
        if (! Schema::hasTable($table)
            || ! Schema::hasTable($referencedTable)
            || ! Schema::hasColumn($table, $column)
            || collect(Schema::getForeignKeys($table))->contains(
                fn (array $foreign): bool => ($foreign['name'] ?? null) === $name
            )) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $name,
            $column,
            $referencedTable,
            $nullOnDelete
        ): void {
            $foreign = $blueprint->foreign($column, $name)->references('id')->on($referencedTable);
            $nullOnDelete ? $foreign->nullOnDelete() : $foreign->restrictOnDelete();
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name
        )) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }

    private function dropForeignIfExists(string $table, string $name): void
    {
        if (collect(Schema::getForeignKeys($table))->contains(
            fn (array $foreign): bool => ($foreign['name'] ?? null) === $name
        )) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }
};
