<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendWorkOrders();
        $this->createReleaseGateChecks();
        $this->createMaterialRequirements();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_work_order_material_requirements');
        Schema::dropIfExists('erp_work_order_release_gate_checks');

        if (! Schema::hasTable('erp_work_orders')) {
            return;
        }

        $columns = [
            'bom_snapshot',
            'release_gate_status',
            'release_gate_checked_at',
            'released_by_legacy_id',
            'released_at',
            'release_reason',
        ];
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('erp_work_orders', $column)
        ));

        if ($existing !== []) {
            Schema::table('erp_work_orders', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }

    private function extendWorkOrders(): void
    {
        if (! Schema::hasTable('erp_work_orders')) {
            throw new RuntimeException('工单基础表不存在，无法创建 Release Gate 与用料结构。');
        }

        $columns = [
            'bom_snapshot' => fn (Blueprint $table) => $table->json('bom_snapshot')->nullable(),
            'release_gate_status' => fn (Blueprint $table) => $table->string('release_gate_status', 30)->nullable(),
            'release_gate_checked_at' => fn (Blueprint $table) => $table->timestamp('release_gate_checked_at')->nullable(),
            'released_by_legacy_id' => fn (Blueprint $table) => $table->unsignedBigInteger('released_by_legacy_id')->nullable(),
            'released_at' => fn (Blueprint $table) => $table->timestamp('released_at')->nullable(),
            'release_reason' => fn (Blueprint $table) => $table->string('release_reason', 500)->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('erp_work_orders', $name)) {
                Schema::table('erp_work_orders', $definition);
            }
        }
    }

    private function createReleaseGateChecks(): void
    {
        if (Schema::hasTable('erp_work_order_release_gate_checks')) {
            return;
        }

        Schema::create('erp_work_order_release_gate_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedInteger('work_order_version');
            $table->string('check_key', 80);
            $table->string('status', 20);
            $table->string('reason_code', 80)->nullable();
            $table->string('message', 500);
            $table->json('evidence')->nullable();
            $table->unsignedBigInteger('evaluated_by_legacy_id')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(
                ['work_order_id', 'work_order_version', 'check_key'],
                'erp_wo_gate_version_check_uq'
            );
            $table->index(['work_order_id', 'evaluated_at'], 'erp_wo_gate_work_order_time_idx');
            $table->foreign('work_order_id', 'erp_wo_gate_work_order_fk')
                ->references('id')
                ->on('erp_work_orders')
                ->restrictOnDelete();
        });
    }

    private function createMaterialRequirements(): void
    {
        if (Schema::hasTable('erp_work_order_material_requirements')) {
            return;
        }

        Schema::create('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('bom_id');
            $table->unsignedBigInteger('bom_item_id');
            $table->unsignedBigInteger('component_item_id');
            $table->string('component_item_code_snapshot', 80);
            $table->string('component_item_name_snapshot', 160);
            $table->string('component_spec_snapshot', 160)->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_name_snapshot', 80)->nullable();
            $table->decimal('per_output_qty', 18, 8);
            $table->decimal('loss_rate', 8, 4)->default(0);
            $table->decimal('fixed_qty', 18, 8)->default(0);
            $table->decimal('required_qty', 18, 8);
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->string('base_unit_name_snapshot', 80)->nullable();
            $table->decimal('base_required_qty', 18, 8);
            $table->decimal('issued_qty', 18, 8)->default(0);
            $table->decimal('returned_qty', 18, 8)->default(0);
            $table->decimal('remaining_qty', 18, 8);
            $table->string('status', 30)->default('OPEN');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['work_order_id', 'line_no'], 'erp_wo_material_line_uq');
            $table->index(['work_order_id', 'status'], 'erp_wo_material_status_idx');
            $table->index(['component_item_id', 'status'], 'erp_wo_material_item_status_idx');
            $table->foreign('work_order_id', 'erp_wo_material_work_order_fk')
                ->references('id')
                ->on('erp_work_orders')
                ->restrictOnDelete();
            $table->foreign('bom_id', 'erp_wo_material_bom_fk')
                ->references('id')
                ->on('erp_boms')
                ->restrictOnDelete();
            $table->foreign('bom_item_id', 'erp_wo_material_bom_item_fk')
                ->references('id')
                ->on('erp_bom_items')
                ->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_wo_material_item_fk')
                ->references('id')
                ->on('erp_items')
                ->restrictOnDelete();
            $table->foreign('unit_id', 'erp_wo_material_unit_fk')
                ->references('id')
                ->on('erp_units')
                ->nullOnDelete();
            $table->foreign('base_unit_id', 'erp_wo_material_base_unit_fk')
                ->references('id')
                ->on('erp_units')
                ->nullOnDelete();
        });
    }
};
