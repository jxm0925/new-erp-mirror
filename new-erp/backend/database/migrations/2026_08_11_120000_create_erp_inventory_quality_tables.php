<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_inventory_serials', function (Blueprint $table): void {
            $table->id();
            $table->string('serial_no', 120)->unique();
            $table->foreignId('inventory_balance_id')->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->foreignId('source_receipt_id')->nullable()->constrained('erp_purchase_receipts')->nullOnDelete();
            $table->foreignId('source_receipt_item_id')->nullable()->constrained('erp_purchase_receipt_items')->nullOnDelete();
            $table->string('serial_status', 30)->default('available');
            $table->timestamps();

            $table->index(['inventory_balance_id', 'serial_status'], 'erp_inv_serial_balance_status_idx');
        });

        Schema::create('erp_inventory_quality_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_no', 80)->unique();
            $table->foreignId('inventory_balance_id')->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->foreignId('inventory_serial_id')->nullable()->constrained('erp_inventory_serials')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->string('serial_no', 120)->nullable();
            $table->foreignId('source_receipt_id')->nullable()->constrained('erp_purchase_receipts')->nullOnDelete();
            $table->foreignId('source_receipt_item_id')->nullable()->constrained('erp_purchase_receipt_items')->nullOnDelete();
            $table->foreignId('source_order_id')->nullable()->constrained('erp_purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('erp_suppliers')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->string('unit_name_snapshot', 40)->nullable();
            $table->decimal('issue_qty', 18, 8);
            $table->string('issue_category', 60);
            $table->text('issue_description');
            $table->string('handling_method', 40);
            $table->string('responsible_party', 40);
            $table->string('event_status', 30)->default('pending_action');
            $table->string('business_doc_type', 60)->nullable();
            $table->string('business_doc_no', 80)->nullable();
            $table->json('attachments')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['event_status', 'handling_method'], 'erp_iqe_status_method_idx');
            $table->index(['item_id', 'warehouse_id', 'location_id', 'batch_no'], 'erp_iqe_locator_idx');
            $table->index(['serial_no', 'event_status'], 'erp_iqe_serial_status_idx');
        });

        Schema::create('erp_inventory_quality_event_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_event_id')->constrained('erp_inventory_quality_events')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inventory_quality_event_logs');
        Schema::dropIfExists('erp_inventory_quality_events');
        Schema::dropIfExists('erp_inventory_serials');
    }
};
