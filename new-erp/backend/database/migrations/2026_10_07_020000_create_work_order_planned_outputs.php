<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_work_order_planned_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->uuid('line_uuid')->unique('wo_planned_output_uuid_uq');
            $table->unsignedInteger('line_no');
            $table->boolean('is_reference')->default(false);
            // Nullable unique identity enforces one active reference without preventing many additional lines.
            $table->unsignedBigInteger('active_reference_work_order_id')->nullable()->unique('wo_planned_reference_uq');
            $table->string('output_role', 30);
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('erp_units')->restrictOnDelete();
            $table->string('item_code_snapshot', 80);
            $table->string('item_name_snapshot', 160);
            $table->string('spec_snapshot', 500)->nullable();
            $table->string('base_unit_name_snapshot', 80);
            $table->unsignedSmallInteger('base_unit_decimal_places_snapshot');
            $table->decimal('planned_base_qty', 28, 8);
            $table->string('remark', 500)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('business_version')->default(1);
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'status', 'line_no'], 'wo_planned_output_lookup_idx');
        });

        Schema::create('erp_work_order_planned_output_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->string('client_command_id', 120)->unique('wo_planned_output_audit_command_uq');
            $table->string('command_type', 50);
            $table->unsignedInteger('before_version');
            $table->unsignedInteger('after_version');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->unsignedBigInteger('operator_legacy_id');
            $table->string('operator_name', 120)->nullable();
            $table->string('organization_code', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['work_order_id', 'after_version'], 'wo_planned_output_audit_lookup_idx');
        });
        // Existing WOs and their frozen snapshots are deliberately not backfilled.
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_work_order_planned_output_versions');
        Schema::dropIfExists('erp_work_order_planned_outputs');
    }
};
