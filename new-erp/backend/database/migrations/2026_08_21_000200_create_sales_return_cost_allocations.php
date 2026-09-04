<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales returns must reverse the historical outbound cost, never the current
     * moving-average cost.  A return item may span more than one shipment, so a
     * separate allocation fact is required instead of a single shipment_id field.
     */
    public function up(): void
    {
        if (Schema::hasTable('erp_sales_return_cost_allocations')) {
            return;
        }

        Schema::create('erp_sales_return_cost_allocations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('sales_return_item_id');
            $table->unsignedBigInteger('sales_shipment_id');
            $table->unsignedBigInteger('sales_shipment_line_id');
            $table->unsignedBigInteger('outbound_transaction_item_id');
            $table->foreign('sales_return_item_id', 'erp_srca_return_item_fk')->references('id')->on('erp_sales_return_items')->cascadeOnDelete();
            $table->foreign('sales_shipment_id', 'erp_srca_shipment_fk')->references('id')->on('erp_sales_shipments')->restrictOnDelete();
            $table->foreign('sales_shipment_line_id', 'erp_srca_shipment_line_fk')->references('id')->on('erp_sales_shipment_lines')->restrictOnDelete();
            $table->foreign('outbound_transaction_item_id', 'erp_srca_outbound_fk')->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
            $table->decimal('allocated_base_qty', 18, 8);
            $table->decimal('posted_base_qty', 18, 8)->default(0);
            $table->decimal('unit_cost_snapshot', 18, 8);
            $table->decimal('cost_amount_snapshot', 18, 4);
            $table->string('allocation_status', 30)->default('reserved')->index();
            $table->timestamps();
            $table->index(['sales_return_item_id', 'allocation_status'], 'erp_srca_return_item_status_idx');
            $table->index(['outbound_transaction_item_id'], 'erp_srca_outbound_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_return_cost_allocations');
    }
};

