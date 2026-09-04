<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_lines', 'line_uuid')) $table->string('line_uuid', 80)->nullable()->after('id')->index();
            if (!Schema::hasColumn('erp_sales_order_lines', 'unit_id')) $table->unsignedBigInteger('unit_id')->nullable()->after('order_qty')->index();
            if (!Schema::hasColumn('erp_sales_order_lines', 'unit_name_snapshot')) $table->string('unit_name_snapshot', 80)->nullable()->after('unit_id');
            if (!Schema::hasColumn('erp_sales_order_lines', 'unit_code_snapshot')) $table->string('unit_code_snapshot', 40)->nullable()->after('unit_name_snapshot');
            if (!Schema::hasColumn('erp_sales_order_lines', 'unit_conversion_ratio_snapshot')) $table->decimal('unit_conversion_ratio_snapshot', 18, 8)->default(1)->after('unit_code_snapshot');
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_match_status')) $table->string('item_match_status', 40)->default('pending_configuration')->after('item_id');
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_match_rule')) $table->string('item_match_rule', 120)->nullable()->after('item_match_status');
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_match_block_reason')) $table->string('item_match_block_reason', 500)->nullable()->after('item_match_rule');
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_match_snapshot')) $table->json('item_match_snapshot')->nullable()->after('item_match_block_reason');
        });

        // Fetch once before ALTER TABLE. This remains reliable if a previous
        // deployment was interrupted after MySQL auto-committed part of its DDL.
        $productionRequirementColumns = Schema::getColumnListing('erp_sales_order_production_requirements');
        Schema::table('erp_sales_order_production_requirements', function (Blueprint $table) use ($productionRequirementColumns) {
            if (!in_array('requirement_version', $productionRequirementColumns, true)) $table->unsignedInteger('requirement_version')->default(1)->after('requirement_no');
            if (!in_array('is_active', $productionRequirementColumns, true)) $table->boolean('is_active')->default(true)->after('requirement_status')->index();
            if (!in_array('consumed_qty', $productionRequirementColumns, true)) $table->decimal('consumed_qty', 14, 4)->default(0)->after('production_qty');
            if (!in_array('remaining_qty', $productionRequirementColumns, true)) $table->decimal('remaining_qty', 14, 4)->default(0)->after('consumed_qty');
            if (!in_array('closed_qty', $productionRequirementColumns, true)) $table->decimal('closed_qty', 14, 4)->default(0)->after('remaining_qty');
            if (!in_array('superseded_by_id', $productionRequirementColumns, true)) $table->unsignedBigInteger('superseded_by_id')->nullable()->after('closed_qty')->index();
            if (!in_array('consumed_at', $productionRequirementColumns, true)) $table->timestamp('consumed_at')->nullable()->after('superseded_by_id');
            if (!in_array('item_match_status', $productionRequirementColumns, true)) $table->string('item_match_status', 40)->default('pending_configuration')->after('item_id');
            if (!in_array('bom_match_status', $productionRequirementColumns, true)) $table->string('bom_match_status', 40)->default('not_checked')->after('bom_snapshot');
            if (!in_array('bom_block_reason', $productionRequirementColumns, true)) $table->string('bom_block_reason', 500)->nullable()->after('bom_match_status');
            if (!in_array('bom_id', $productionRequirementColumns, true)) $table->unsignedBigInteger('bom_id')->nullable()->after('bom_block_reason')->index();
            if (!in_array('bom_version_id', $productionRequirementColumns, true)) $table->unsignedBigInteger('bom_version_id')->nullable()->after('bom_id');
            if (!in_array('bom_version', $productionRequirementColumns, true)) $table->string('bom_version', 80)->nullable()->after('bom_version_id');
            if (!in_array('routing_match_status', $productionRequirementColumns, true)) $table->string('routing_match_status', 40)->default('not_configured')->after('routing_snapshot');
            if (!in_array('routing_block_reason', $productionRequirementColumns, true)) $table->string('routing_block_reason', 500)->nullable()->after('routing_match_status');
            if (!in_array('routing_id', $productionRequirementColumns, true)) $table->unsignedBigInteger('routing_id')->nullable()->after('routing_block_reason')->index();
            if (!in_array('routing_version_id', $productionRequirementColumns, true)) $table->unsignedBigInteger('routing_version_id')->nullable()->after('routing_id');
        });

        if (!Schema::hasTable('erp_sales_order_attachments')) {
            Schema::create('erp_sales_order_attachments', function (Blueprint $table) {
                $table->id();
                $table->string('draft_token', 120)->nullable()->index();
                $table->string('line_uuid', 80)->nullable()->index();
                $table->unsignedBigInteger('sales_order_id')->nullable()->index();
                $table->unsignedBigInteger('sales_order_line_id')->nullable()->index();
                $table->string('attachment_scope', 40)->default('order')->index();
                $table->string('attachment_type', 60)->default('other')->index();
                $table->string('original_name', 255);
                $table->string('stored_name', 255);
                $table->string('storage_disk', 40)->default('public');
                $table->string('storage_path', 500);
                $table->string('url', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('file_hash', 128)->nullable()->index();
                $table->unsignedBigInteger('uploaded_by_legacy_id')->nullable()->index();
                $table->string('uploaded_by', 80)->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
                $table->index(['sales_order_id', 'attachment_scope'], 'erp_so_att_order_scope_idx');
                $table->index(['draft_token', 'line_uuid', 'status'], 'erp_so_att_draft_line_idx');
            });
        }

        if (!Schema::hasTable('erp_inventory_reservations')) {
            Schema::create('erp_inventory_reservations', function (Blueprint $table) {
                $table->id();
                $table->string('source_type', 60);
                $table->unsignedBigInteger('source_order_id')->nullable()->index();
                $table->unsignedBigInteger('source_order_line_id')->nullable()->index();
                $table->unsignedBigInteger('item_id')->nullable()->index();
                $table->unsignedBigInteger('inventory_balance_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('location_id')->nullable()->index();
                $table->string('batch_no', 80)->nullable()->index();
                $table->decimal('reserved_qty', 14, 4);
                $table->string('reservation_status', 40)->default('active')->index();
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('release_reason', 500)->nullable();
                $table->string('idempotency_key', 160)->nullable()->unique();
                $table->json('reservation_snapshot')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('erp_document_numbers')) {
            Schema::create('erp_document_numbers', function (Blueprint $table) {
                $table->id();
                $table->string('document_type', 40);
                $table->date('number_date');
                $table->unsignedInteger('current_sequence')->default(0);
                $table->timestamps();
                $table->unique(['document_type', 'number_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_document_numbers');
        Schema::dropIfExists('erp_inventory_reservations');
        Schema::dropIfExists('erp_sales_order_attachments');
    }
};
