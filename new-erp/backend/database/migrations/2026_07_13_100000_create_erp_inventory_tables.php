<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('item_id')->constrained('erp_warehouses')->nullOnDelete();
            }
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('warehouse_id')->constrained('erp_locations')->nullOnDelete();
            }
        });

        Schema::create('erp_inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('quantity_on_hand', 14, 4)->default(0);
            $table->decimal('quantity_available', 14, 4)->default(0);
            $table->decimal('quantity_locked', 14, 4)->default(0);
            $table->decimal('quantity_defective', 14, 4)->default(0);
            $table->decimal('quantity_pending', 14, 4)->default(0);
            $table->timestamp('last_transaction_at')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id', 'location_id', 'batch_no'], 'erp_inv_balance_unique');
        });

        Schema::create('erp_inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no', 80)->unique();
            $table->string('transaction_type', 40);
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->string('source_no', 80)->nullable();
            $table->string('posting_status', 30)->default('posted');
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('erp_locations')->nullOnDelete();
            $table->date('transaction_date')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_reason', 255)->nullable();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'transaction_type'], 'erp_inv_tx_source_unique');
        });

        Schema::create('erp_inventory_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('erp_inventory_transactions')->cascadeOnDelete();
            $table->string('transaction_no', 80);
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->string('item_code', 80);
            $table->string('item_name', 160);
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('change_qty', 14, 4);
            $table->decimal('balance_after_qty', 14, 4)->default(0);
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('erp_locations')->nullOnDelete();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('production_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->string('status', 30)->default('enabled');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'batch_no'], 'erp_inv_batch_unique');
        });

        Schema::create('erp_inventory_location_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('quantity_on_hand', 14, 4)->default(0);
            $table->decimal('quantity_available', 14, 4)->default(0);
            $table->decimal('quantity_locked', 14, 4)->default(0);
            $table->decimal('quantity_defective', 14, 4)->default(0);
            $table->decimal('quantity_pending', 14, 4)->default(0);
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id', 'location_id'], 'erp_inv_location_balance_unique');
        });

        Schema::create('erp_inventory_posting_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->string('source_no', 80)->nullable();
            $table->string('transaction_type', 40);
            $table->foreignId('transaction_id')->nullable()->constrained('erp_inventory_transactions')->nullOnDelete();
            $table->string('posting_status', 30);
            $table->string('message', 255)->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 80)->unique();
            $table->string('adjustment_status', 30)->default('draft');
            $table->date('adjustment_date')->nullable();
            $table->string('reason', 160)->nullable();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_inventory_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('erp_inventory_adjustments')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80);
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('change_qty', 14, 4);
            $table->text('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'erp_inventory_adjustment_items',
            'erp_inventory_adjustments',
            'erp_inventory_posting_logs',
            'erp_inventory_location_balances',
            'erp_inventory_batches',
            'erp_inventory_transaction_items',
            'erp_inventory_transactions',
            'erp_inventory_balances',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            if (Schema::hasColumn('erp_purchase_receipt_items', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }
            if (Schema::hasColumn('erp_purchase_receipt_items', 'warehouse_id')) {
                $table->dropConstrainedForeignId('warehouse_id');
            }
        });
    }
};
