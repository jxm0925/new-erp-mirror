<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_no', 80)->unique();
            $table->string('operation_name', 160);
            $table->string('status', 20)->default('enabled');
            $table->unsignedInteger('sort')->default(0);
            $table->text('description')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'sort'], 'erp_prod_operation_status_sort_idx');
        });

        Schema::create('erp_production_routings', function (Blueprint $table): void {
            $table->id();
            $table->string('routing_no', 80);
            $table->string('routing_name', 160);
            $table->unsignedBigInteger('output_item_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('default_scope_key')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();

            $table->unique(['routing_no', 'version'], 'erp_prod_routing_no_version_uq');
            $table->unique('default_scope_key', 'erp_prod_routing_default_scope_uq');
            $table->index(['output_item_id', 'status'], 'erp_prod_routing_item_status_idx');
            $table->foreign('output_item_id', 'erp_prod_routing_output_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('product_id', 'erp_prod_routing_product_fk')->references('id')->on('erp_products')->nullOnDelete();
            $table->foreign('sku_id', 'erp_prod_routing_sku_fk')->references('id')->on('erp_skus')->nullOnDelete();
        });

        Schema::create('erp_production_routing_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('routing_id');
            $table->unsignedBigInteger('operation_id');
            $table->unsignedInteger('sequence');
            $table->json('parameters')->nullable();
            $table->boolean('is_key_operation')->default(false);
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->unique(['routing_id', 'sequence'], 'erp_prod_route_operation_sequence_uq');
            $table->index(['routing_id', 'operation_id'], 'erp_prod_route_operation_lookup_idx');
            $table->foreign('routing_id', 'erp_prod_route_operation_route_fk')->references('id')->on('erp_production_routings')->cascadeOnDelete();
            $table->foreign('operation_id', 'erp_prod_route_operation_operation_fk')->references('id')->on('erp_production_operations')->restrictOnDelete();
        });

        Schema::create('erp_production_master_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_command_id', 120)->unique();
            $table->string('command_type', 60);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('request_hash', 64);
            $table->json('response_snapshot')->nullable();
            $table->unsignedBigInteger('initiated_by_legacy_id')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id'], 'erp_prod_master_command_entity_idx');
        });

        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->string('source_type', 30)->default('sales_order')->after('work_order_no');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_no_snapshot', 120)->nullable()->after('source_id');
            $table->string('source_title_snapshot', 200)->nullable()->after('source_no_snapshot');
            $table->unsignedBigInteger('output_item_id')->nullable()->after('production_demand_id');
            $table->unsignedBigInteger('production_routing_id')->nullable()->after('output_item_id');
            $table->unsignedInteger('routing_version_snapshot')->nullable()->after('production_routing_id');
            $table->json('routing_snapshot')->nullable()->after('routing_version_snapshot');
            $table->unsignedBigInteger('target_operation_id')->nullable()->after('routing_snapshot');
            $table->index(['source_type', 'source_id'], 'erp_work_order_source_idx');
            $table->index(['production_routing_id', 'target_operation_id'], 'erp_work_order_routing_target_idx');
            $table->foreign('output_item_id', 'erp_work_order_output_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('production_routing_id', 'erp_work_order_routing_fk')->references('id')->on('erp_production_routings')->restrictOnDelete();
            $table->foreign('target_operation_id', 'erp_work_order_target_operation_fk')->references('id')->on('erp_production_operations')->restrictOnDelete();
        });

        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_demand_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('erp_work_orders')) {
            Schema::table('erp_work_orders', function (Blueprint $table): void {
                $table->dropForeign('erp_work_order_output_item_fk');
                $table->dropForeign('erp_work_order_routing_fk');
                $table->dropForeign('erp_work_order_target_operation_fk');
                $table->dropIndex('erp_work_order_source_idx');
                $table->dropIndex('erp_work_order_routing_target_idx');
                $table->dropColumn([
                    'source_type', 'source_id', 'source_no_snapshot', 'source_title_snapshot',
                    'output_item_id', 'production_routing_id', 'routing_version_snapshot',
                    'routing_snapshot', 'target_operation_id',
                ]);
            });
            Schema::table('erp_work_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('production_demand_id')->nullable(false)->change();
            });
        }

        Schema::dropIfExists('erp_production_master_commands');
        Schema::dropIfExists('erp_production_routing_operations');
        Schema::dropIfExists('erp_production_routings');
        Schema::dropIfExists('erp_production_operations');
    }
};
