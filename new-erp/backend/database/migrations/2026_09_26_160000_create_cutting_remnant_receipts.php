<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_cutting_remnant_receipts', function (Blueprint $t): void {
            $t->id();
            $t->string('receipt_no', 80)->unique();
            $t->foreignId('cutting_order_id')->constrained('erp_cutting_orders');
            $t->foreignId('warehouse_id')->constrained('erp_warehouses');
            $t->foreignId('location_id')->constrained('erp_locations');
            $t->string('status', 20);
            $t->unsignedInteger('piece_count');
            $t->decimal('posted_cost', 20, 4);
            $t->unsignedBigInteger('posted_by_legacy_id');
            $t->json('header_snapshot');
            $t->string('remark', 1000)->nullable();
            $t->foreignId('inventory_transaction_id')->nullable()->constrained('erp_inventory_transactions');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('erp_cutting_remnant_receipt_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('receipt_id')->constrained('erp_cutting_remnant_receipts');
            // One confirmed remnant can enter warehouse stock only once, across all commands.
            $t->foreignId('result_id')->unique()->constrained('erp_cutting_results');
            $t->foreignId('source_holding_id')->unique()->constrained('erp_material_holdings');
            $t->foreignId('physical_material_id')->nullable()->constrained('erp_material_physicals');
            $t->foreignId('material_lot_id')->constrained('erp_material_lots');
            $t->foreignId('item_id')->constrained('erp_items');
            $t->foreignId('unit_id')->constrained('erp_units');
            $t->string('batch_no', 80);
            $t->decimal('posted_qty', 20, 8);
            $t->decimal('posted_cost', 20, 4);
            $t->json('line_snapshot');
            $t->foreignId('inventory_balance_id')->nullable()->constrained('erp_inventory_balances');
            $t->foreignId('warehouse_holding_id')->nullable()->constrained('erp_material_holdings');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_cutting_remnant_receipt_lines');
        Schema::dropIfExists('erp_cutting_remnant_receipts');
    }
};
