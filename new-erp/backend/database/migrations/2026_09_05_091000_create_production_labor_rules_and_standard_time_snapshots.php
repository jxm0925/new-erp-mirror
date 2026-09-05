<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_labor_allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_no', 80);
            $table->string('rule_name', 160);
            $table->unsignedInteger('version_no');
            $table->decimal('owner_ratio', 8, 4);
            $table->decimal('collaborator_total_ratio', 8, 4);
            $table->string('collaborator_allocation_method', 40)->default('actual_labor_ratio');
            $table->string('status', 20)->default('draft');
            $table->string('active_scope_key', 80)->nullable()->unique();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['rule_no', 'version_no'], 'erp_prod_labor_rule_version_uq');
            $table->index(['status', 'effective_at'], 'erp_prod_labor_rule_status_idx');
        });

        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->decimal('setup_standard_minutes', 12, 2)->default(0)->after('standard_minutes');
            $table->decimal('unit_standard_minutes', 12, 2)->nullable()->after('setup_standard_minutes');
        });
        foreach (['erp_production_unit_operations', 'erp_production_quantity_operations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('setup_standard_minutes_snapshot', 12, 2)->default(0)->after('standard_minutes_snapshot');
                $table->decimal('unit_standard_minutes_snapshot', 12, 2)->nullable()->after('setup_standard_minutes_snapshot');
                $table->decimal('standard_quantity_snapshot', 18, 8)->default(1)->after('unit_standard_minutes_snapshot');
                $table->string('standard_time_formula_snapshot', 40)->default('unit_standard')->after('standard_quantity_snapshot');
            });
        }
        Schema::table('erp_production_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('labor_allocation_rule_id')->nullable()->after('assignment_score_snapshot');
            $table->unsignedInteger('labor_allocation_rule_version')->nullable()->after('labor_allocation_rule_id');
            $table->json('labor_allocation_rule_snapshot')->nullable()->after('labor_allocation_rule_version');
            $table->foreign('labor_allocation_rule_id', 'erp_prod_task_labor_rule_fk')
                ->references('id')->on('erp_production_labor_allocation_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_tasks', function (Blueprint $table): void {
            $table->dropForeign('erp_prod_task_labor_rule_fk');
            $table->dropColumn(['labor_allocation_rule_snapshot', 'labor_allocation_rule_version', 'labor_allocation_rule_id']);
        });
        foreach (['erp_production_quantity_operations', 'erp_production_unit_operations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn([
                    'standard_time_formula_snapshot', 'standard_quantity_snapshot',
                    'unit_standard_minutes_snapshot', 'setup_standard_minutes_snapshot',
                ]);
            });
        }
        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->dropColumn(['unit_standard_minutes', 'setup_standard_minutes']);
        });
        Schema::dropIfExists('erp_production_labor_allocation_rules');
    }
};
