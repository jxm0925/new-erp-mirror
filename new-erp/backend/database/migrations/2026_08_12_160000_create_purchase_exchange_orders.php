<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_exchange_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange_no', 80)->unique();
            $table->foreignId('defect_handling_id')->unique()->constrained('erp_purchase_defect_handlings')->restrictOnDelete();
            $table->foreignId('source_receipt_id')->constrained('erp_purchase_receipts')->restrictOnDelete();
            $table->foreignId('source_receipt_item_id')->constrained('erp_purchase_receipt_items')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('erp_purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('replacement_receipt_id')->nullable()->constrained('erp_purchase_receipts')->nullOnDelete();
            $table->decimal('exchange_base_qty', 18, 8);
            $table->string('base_unit_name_snapshot', 40)->nullable();
            $table->decimal('original_contract_amount', 18, 4)->default(0);
            $table->decimal('original_payable_amount', 18, 4)->default(0);
            $table->decimal('exchange_additional_payable_amount', 18, 4)->default(0);
            $table->decimal('replacement_inventory_cost', 18, 4)->default(0);
            $table->string('exchange_status', 40)->default('processing')->index();
            $table->string('current_step', 60)->default('pending_original_return')->index();
            $table->string('return_warehouse_name', 120)->nullable();
            $table->string('return_location_name', 120)->nullable();
            $table->decimal('returned_base_qty', 18, 8)->default(0);
            $table->string('return_logistics_company', 120)->nullable();
            $table->string('return_tracking_no', 120)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('supplier_receiver', 80)->nullable();
            $table->timestamp('supplier_received_at')->nullable();
            $table->date('replacement_shipped_date')->nullable();
            $table->string('replacement_logistics_company', 120)->nullable();
            $table->string('replacement_tracking_no', 120)->nullable();
            $table->date('replacement_expected_date')->nullable();
            $table->timestamp('replacement_shipped_at')->nullable();
            $table->timestamp('replacement_accepted_at')->nullable();
            $table->text('remark')->nullable();
            $table->string('created_by', 80)->nullable();
            $table->string('updated_by', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'current_step'], 'erp_peo_supplier_step_idx');
            $table->index(['source_receipt_id', 'item_id'], 'erp_peo_receipt_item_idx');
        });

        Schema::create('erp_purchase_exchange_serial_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exchange_order_id')->constrained('erp_purchase_exchange_orders')->cascadeOnDelete();
            $table->string('original_serial_no', 120)->nullable();
            $table->string('original_return_status', 30)->default('pending');
            $table->string('replacement_serial_no', 120)->nullable();
            $table->string('replacement_receipt_status', 30)->default('pending');
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange_order_id', 'original_serial_no'], 'erp_pesl_order_original_uq');
            $table->unique(['exchange_order_id', 'replacement_serial_no'], 'erp_pesl_order_replacement_uq');
        });

        Schema::create('erp_purchase_exchange_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exchange_order_id')->constrained('erp_purchase_exchange_orders')->cascadeOnDelete();
            $table->string('action', 60);
            $table->string('from_step', 60)->nullable();
            $table->string('to_step', 60)->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->text('content')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['exchange_order_id', 'created_at'], 'erp_pel_order_time_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_exchange_logs');
        Schema::dropIfExists('erp_purchase_exchange_serial_links');
        Schema::dropIfExists('erp_purchase_exchange_orders');
    }
};

