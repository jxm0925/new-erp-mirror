<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sales_return_serial_identities', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('sales_return_receipt_item_id');
            $table->unsignedBigInteger('sales_return_item_id');
            $table->unsignedBigInteger('inventory_serial_id');
            $table->unsignedBigInteger('sales_shipment_line_id');
            $table->unsignedBigInteger('outbound_transaction_item_id');
            $table->unsignedBigInteger('cost_allocation_id');
            $table->unsignedBigInteger('inventory_transaction_item_id')->nullable();
            $table->string('serial_no_snapshot', 120);
            $table->string('source_batch_no', 80);
            $table->string('disposition', 20);
            $table->decimal('unit_cost_snapshot', 18, 8);
            $table->decimal('cost_amount_snapshot', 18, 4);
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->foreign('sales_return_receipt_item_id', 'erp_srsi_receipt_line_fk')->references('id')->on('erp_sales_return_receipt_items')->restrictOnDelete();
            $table->foreign('sales_return_item_id', 'erp_srsi_return_line_fk')->references('id')->on('erp_sales_return_items')->restrictOnDelete();
            $table->foreign('inventory_serial_id', 'erp_srsi_serial_fk')->references('id')->on('erp_inventory_serials')->restrictOnDelete();
            $table->foreign('sales_shipment_line_id', 'erp_srsi_shipment_line_fk')->references('id')->on('erp_sales_shipment_lines')->restrictOnDelete();
            $table->foreign('outbound_transaction_item_id', 'erp_srsi_outbound_fk')->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
            $table->foreign('cost_allocation_id', 'erp_srsi_cost_fk')->references('id')->on('erp_sales_return_cost_allocations')->restrictOnDelete();
            $table->foreign('inventory_transaction_item_id', 'erp_srsi_inbound_fk')->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
            // A serial may be sold and returned again later, but the same outbound fact
            // can be returned only once, including non-restock dispositions.
            $table->unique(['outbound_transaction_item_id', 'inventory_serial_id'], 'erp_srsi_outbound_serial_uq');
            $table->unique(['sales_return_receipt_item_id', 'inventory_serial_id'], 'erp_srsi_receipt_serial_uq');
            $table->index(['sales_return_item_id', 'disposition'], 'erp_srsi_return_disposition_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_return_serial_identities');
    }
};
