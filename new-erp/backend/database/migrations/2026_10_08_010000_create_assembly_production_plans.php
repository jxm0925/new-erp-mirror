<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            // Existing procurement flags do not establish a manufacturing decision.
            $table->string('manufacturing_strategy', 20)->default('unspecified')->index();
        });
        Schema::create('erp_assembly_production_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('root_work_order_id')->unique()->constrained('erp_work_orders')->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('plan_version')->default(1);
            $table->unsignedInteger('work_order_version');
            $table->string('input_hash', 64)->nullable();
            $table->string('command_id', 120)->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->json('plan_snapshot');
            $table->unsignedBigInteger('prepared_by_legacy_id')->nullable();
            $table->string('organization_code', 80)->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('erp_assembly_component_demands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assembly_plan_id')->constrained('erp_assembly_production_plans')->restrictOnDelete();
            $table->foreignId('root_work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('parent_work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('child_work_order_id')->nullable()->unique()->constrained('erp_work_orders')->restrictOnDelete();
            $table->unsignedBigInteger('bom_item_id');
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('erp_units')->restrictOnDelete();
            $table->string('path_key', 64);
            $table->decimal('required_base_qty', 28, 8);
            $table->decimal('inventory_reserved_base_qty', 28, 8)->default(0);
            $table->decimal('production_base_qty', 28, 8)->default(0);
            $table->string('status', 24)->default('PREPARED');
            $table->json('demand_snapshot');
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['assembly_plan_id', 'path_key'], 'assembly_demand_plan_path_unique');
        });
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->foreignId('assembly_root_work_order_id')->nullable()->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('assembly_parent_work_order_id')->nullable()->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('assembly_component_demand_id')->nullable()->unique()->constrained('erp_assembly_component_demands')->restrictOnDelete();
        });
        Schema::create('erp_assembly_inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_demand_id')->constrained('erp_assembly_component_demands')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->foreignId('inventory_balance_id')->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->unsignedBigInteger('material_requirement_id')->nullable()->index('assembly_reservation_requirement_idx');
            $table->unsignedBigInteger('finished_goods_receipt_id')->nullable()->unique('assembly_reservation_receipt_unique');
            $table->decimal('reserved_base_qty', 28, 8);
            $table->decimal('consumed_base_qty', 28, 8)->default(0);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->json('source_snapshot');
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_assembly_inventory_reservations');
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assembly_component_demand_id');
            $table->dropConstrainedForeignId('assembly_parent_work_order_id');
            $table->dropConstrainedForeignId('assembly_root_work_order_id');
        });
        Schema::dropIfExists('erp_assembly_component_demands');
        Schema::dropIfExists('erp_assembly_production_plans');
        Schema::table('erp_items', function (Blueprint $table): void { $table->dropColumn('manufacturing_strategy'); });
    }
};
