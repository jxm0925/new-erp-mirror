<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_inventory_balances', function (Blueprint $table): void {
            $table->timestamp('fifo_initialized_at')->nullable();
            $table->timestamp('oldest_received_at')->nullable()->index();
        });
        Schema::create('erp_inventory_fifo_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_balance_id')->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->foreignId('inbound_transaction_item_id')->nullable()->constrained('erp_inventory_transaction_items')->restrictOnDelete();
            $table->unsignedBigInteger('origin_layer_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('fifo_at');
            $table->decimal('received_qty', 20, 8);
            $table->decimal('remaining_qty', 20, 8);
            $table->boolean('legacy_opening')->default(false);
            $table->timestamps();
            $table->index(['inventory_balance_id', 'fifo_at', 'id'], 'inventory_fifo_queue');
            $table->index('inbound_transaction_item_id', 'inventory_fifo_receipt');
        });
        Schema::create('erp_inventory_fifo_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_item_id')->constrained('erp_inventory_transaction_items')->restrictOnDelete();
            $table->foreignId('layer_id')->constrained('erp_inventory_fifo_layers')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->timestamp('created_at');
            $table->unique(['transaction_item_id', 'layer_id'], 'inventory_fifo_consumption_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inventory_fifo_consumptions');
        Schema::dropIfExists('erp_inventory_fifo_layers');
        Schema::table('erp_inventory_balances', fn (Blueprint $table) => $table->dropColumn(['fifo_initialized_at', 'oldest_received_at']));
    }
};
