<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_inventory_adjustment_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adjustment_item_id')->constrained('erp_inventory_adjustment_items')->cascadeOnDelete();
            $table->foreignId('inventory_serial_id')->nullable()->constrained('erp_inventory_serials')->nullOnDelete();
            $table->string('serial_no', 120);
            $table->string('direction', 20);
            $table->string('number_source', 30)->default('manual');
            $table->timestamps();

            $table->unique(['adjustment_item_id', 'serial_no'], 'erp_adj_serial_item_no_uq');
            $table->index(['serial_no', 'direction'], 'erp_adj_serial_no_direction_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inventory_adjustment_serials');
    }
};
