<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_inventory_alert_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
            $table->string('scope_key', 48); // company / warehouse:{id}; keeps nullable warehouse uniqueness portable in MySQL.
            $table->string('status', 16)->default('draft'); // draft / active / disabled
            $table->boolean('is_enabled')->default(false);
            $table->decimal('min_stock', 18, 6)->nullable();
            $table->decimal('safety_stock', 18, 6)->nullable();
            $table->decimal('max_stock', 18, 6)->nullable();
            $table->decimal('suggested_replenishment_qty', 18, 6)->nullable();
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('enabled_by_legacy_id')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'scope_key'], 'erp_inv_alert_policy_item_scope_uq');
            $table->index(['item_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('erp_inventory_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('erp_warehouses')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('erp_inventory_alert_policies')->nullOnDelete();
            $table->string('alert_status', 24)->default('normal'); // normal / low_stock / out_of_stock / over_stock
            $table->string('severity', 16)->default('normal'); // normal / warning / critical / info
            $table->boolean('is_active')->default(false);
            $table->decimal('quantity_on_hand', 18, 6)->default(0);
            $table->decimal('quantity_locked', 18, 6)->default(0);
            $table->decimal('quantity_reserved', 18, 6)->default(0);
            $table->decimal('quantity_defective', 18, 6)->default(0);
            $table->decimal('available_qty', 18, 6)->default(0);
            $table->decimal('min_stock_snapshot', 18, 6)->nullable();
            $table->decimal('safety_stock_snapshot', 18, 6)->nullable();
            $table->decimal('max_stock_snapshot', 18, 6)->nullable();
            $table->decimal('suggested_replenishment_qty_snapshot', 18, 6)->nullable();
            $table->timestamp('first_triggered_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('purchase_request_id')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id'], 'erp_inventory_alert_item_warehouse_uq');
            $table->index(['is_active', 'alert_status', 'severity']);
            $table->index(['warehouse_id', 'is_read']);
        });

        Schema::create('erp_inventory_alert_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('erp_inventory_alerts')->cascadeOnDelete();
            $table->string('old_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->string('old_severity', 16)->nullable();
            $table->string('new_severity', 16);
            $table->json('quantity_snapshot');
            $table->string('change_reason', 80)->default('inventory_change');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['alert_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inventory_alert_histories');
        Schema::dropIfExists('erp_inventory_alerts');
        Schema::dropIfExists('erp_inventory_alert_policies');
    }
};
