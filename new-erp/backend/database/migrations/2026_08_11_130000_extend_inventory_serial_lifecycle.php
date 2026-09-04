<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_inventory_serials', function (Blueprint $table): void {
            $table->string('origin_type', 30)->default('purchase')->after('batch_no');
            $table->string('number_source', 30)->default('existing')->after('origin_type');
            $table->string('manufacturer_serial_no', 120)->nullable()->after('number_source');
            $table->string('source_document_type', 60)->nullable()->after('manufacturer_serial_no');
            $table->unsignedBigInteger('source_document_id')->nullable()->after('source_document_type');
            $table->string('source_document_no', 80)->nullable()->after('source_document_id');
            $table->foreignId('supplier_id')->nullable()->after('source_receipt_item_id')->constrained('erp_suppliers')->nullOnDelete();
            $table->string('legacy_source_table', 100)->nullable()->after('supplier_id');
            $table->unsignedBigInteger('legacy_source_id')->nullable()->after('legacy_source_table');
            $table->string('legacy_device_no', 120)->nullable()->after('legacy_source_id');
            $table->timestamp('received_at')->nullable()->after('serial_status');
            $table->timestamp('reserved_at')->nullable()->after('received_at');
            $table->timestamp('outbound_at')->nullable()->after('reserved_at');

            $table->index(['origin_type', 'serial_status'], 'erp_inv_serial_origin_status_idx');
            $table->index(['source_document_type', 'source_document_id'], 'erp_inv_serial_source_doc_idx');
            $table->index(['manufacturer_serial_no', 'item_id'], 'erp_inv_serial_mfr_item_idx');
            $table->index(['legacy_source_table', 'legacy_source_id'], 'erp_inv_serial_legacy_idx');
        });

        Schema::create('erp_inventory_serial_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_serial_id')->constrained('erp_inventory_serials')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('document_type', 60)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_no', 80)->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('erp_locations')->nullOnDelete();
            $table->string('batch_no', 80)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->json('event_payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['inventory_serial_id', 'occurred_at'], 'erp_inv_serial_event_time_idx');
            $table->index(['document_type', 'document_id'], 'erp_inv_serial_event_doc_idx');
        });

        Schema::create('erp_inventory_serial_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_serial_id')->constrained('erp_inventory_serials')->cascadeOnDelete();
            $table->string('relation_type', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name_snapshot', 80);
            $table->string('source_document_type', 60)->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['inventory_serial_id', 'relation_type'], 'erp_inv_serial_participant_type_idx');
            $table->index(['user_id', 'relation_type'], 'erp_inv_serial_participant_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inventory_serial_participants');
        Schema::dropIfExists('erp_inventory_serial_events');

        Schema::table('erp_inventory_serials', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropIndex('erp_inv_serial_origin_status_idx');
            $table->dropIndex('erp_inv_serial_source_doc_idx');
            $table->dropIndex('erp_inv_serial_mfr_item_idx');
            $table->dropIndex('erp_inv_serial_legacy_idx');
            $table->dropColumn([
                'origin_type', 'number_source', 'manufacturer_serial_no',
                'source_document_type', 'source_document_id', 'source_document_no',
                'supplier_id', 'legacy_source_table', 'legacy_source_id', 'legacy_device_no',
                'received_at', 'reserved_at', 'outbound_at',
            ]);
        });
    }
};
