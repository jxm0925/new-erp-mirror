<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_receipt_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_item_id')->constrained('erp_purchase_receipt_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->decimal('base_qty', 18, 8);
            $table->json('serial_nos')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['receipt_item_id', 'warehouse_id', 'location_id'], 'erp_receipt_item_allocation_unique');
            $table->index(['warehouse_id', 'location_id'], 'erp_receipt_item_allocation_locator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_receipt_item_allocations');
    }
};
