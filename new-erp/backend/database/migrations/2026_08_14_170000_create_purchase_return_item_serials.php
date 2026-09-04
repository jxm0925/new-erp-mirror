<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_return_item_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_item_id')->constrained('erp_purchase_return_items')->cascadeOnDelete();
            $table->foreignId('inventory_serial_id')->constrained('erp_inventory_serials')->restrictOnDelete();
            $table->string('serial_no', 120);
            $table->timestamps();

            $table->unique(['purchase_return_item_id', 'inventory_serial_id'], 'erp_pris_item_serial_uq');
            $table->index(['inventory_serial_id', 'serial_no'], 'erp_pris_serial_no_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_return_item_serials');
    }
};
