<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_routing_operation_output_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('routing_id');
            $table->unsignedBigInteger('routing_operation_id');
            // A copied route version retains the business key; draft node IDs may be rebuilt on save.
            $table->uuid('output_rule_key');
            $table->unsignedInteger('line_no');
            $table->string('output_role', 30);
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('base_unit_id');
            $table->string('item_code_snapshot', 80);
            $table->string('item_name_snapshot', 160);
            $table->string('spec_snapshot', 500)->nullable();
            $table->string('base_unit_name_snapshot', 80);
            $table->unsignedSmallInteger('base_unit_decimal_places_snapshot');
            $table->unsignedBigInteger('reference_item_id');
            $table->unsignedBigInteger('reference_base_unit_id');
            $table->string('reference_item_code_snapshot', 80);
            $table->string('reference_item_name_snapshot', 160);
            $table->string('reference_base_unit_name_snapshot', 80);
            $table->unsignedSmallInteger('reference_base_unit_decimal_places_snapshot');
            $table->decimal('base_qty_per_reference_unit', 28, 8);
            $table->string('quality_mode', 30);
            $table->string('output_mode', 30);
            $table->boolean('allow_continue_without_warehouse');
            $table->string('remark', 500)->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();
            $table->foreign('routing_id', 'route_output_route_fk')->references('id')->on('erp_production_routings')->cascadeOnDelete();
            $table->foreign('routing_operation_id', 'route_output_node_fk')->references('id')->on('erp_production_routing_operations')->cascadeOnDelete();
            $table->foreign('item_id', 'route_output_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('base_unit_id', 'route_output_unit_fk')->references('id')->on('erp_units')->restrictOnDelete();
            $table->foreign('reference_item_id', 'route_output_ref_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('reference_base_unit_id', 'route_output_ref_unit_fk')->references('id')->on('erp_units')->restrictOnDelete();
            $table->unique(['routing_id', 'output_rule_key'], 'routing_output_rule_key_uq');
            $table->unique(['routing_operation_id', 'item_id'], 'routing_output_rule_item_uq');
            $table->index(['routing_operation_id', 'line_no'], 'routing_output_rule_lookup_idx');
        });
        // Old routes and frozen work orders keep their existing single-output facts; no backfill.
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_routing_operation_output_rules');
    }
};
