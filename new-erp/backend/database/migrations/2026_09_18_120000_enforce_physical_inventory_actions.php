<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_receipt_allocation_physicals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id');
            $table->unsignedInteger('sequence_no');
            $table->json('dimensions');
            $table->foreignId('physical_material_id')->nullable();
            $table->foreignId('inventory_transaction_item_id')->nullable();
            $table->timestamps();

            $table->unique(['allocation_id', 'sequence_no'], 'purchase_receipt_allocation_physical_sequence_uq');
            $table->unique('physical_material_id', 'purchase_receipt_physical_identity_uq');
            $table->foreign('allocation_id', 'purchase_receipt_physical_allocation_fk')
                ->references('id')->on('erp_purchase_receipt_item_allocations')->cascadeOnDelete();
            $table->foreign('physical_material_id', 'purchase_receipt_physical_identity_fk')
                ->references('id')->on('erp_material_physicals')->restrictOnDelete();
            $table->foreign('inventory_transaction_item_id', 'purchase_receipt_physical_tx_item_fk')
                ->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
        });

        Schema::create('erp_inventory_adjustment_item_physicals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adjustment_item_id');
            $table->string('direction', 16);
            $table->unsignedInteger('sequence_no');
            $table->foreignId('physical_material_id')->nullable();
            $table->json('dimensions')->nullable();
            $table->decimal('total_cost', 18, 4);
            $table->foreignId('inventory_transaction_item_id')->nullable();
            $table->timestamps();

            $table->unique(['adjustment_item_id', 'sequence_no'], 'inventory_adjustment_physical_sequence_uq');
            $table->index('physical_material_id', 'inventory_adjustment_physical_idx');
            $table->foreign('adjustment_item_id', 'inventory_adjustment_physical_line_fk')
                ->references('id')->on('erp_inventory_adjustment_items')->cascadeOnDelete();
            $table->foreign('physical_material_id', 'inventory_adjustment_physical_identity_fk')
                ->references('id')->on('erp_material_physicals')->restrictOnDelete();
            $table->foreign('inventory_transaction_item_id', 'inventory_adjustment_physical_tx_item_fk')
                ->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
        });

        Schema::create('erp_purchase_return_item_physicals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_item_id');
            $table->foreignId('physical_material_id');
            $table->foreignId('inventory_transaction_item_id')->nullable();
            $table->timestamps();
            $table->index('physical_material_id', 'purchase_return_physical_idx');
            $table->foreign('purchase_return_item_id', 'purchase_return_physical_line_fk')
                ->references('id')->on('erp_purchase_return_items')->cascadeOnDelete();
            $table->foreign('physical_material_id', 'purchase_return_physical_identity_fk')
                ->references('id')->on('erp_material_physicals')->restrictOnDelete();
            $table->foreign('inventory_transaction_item_id', 'purchase_return_physical_tx_item_fk')
                ->references('id')->on('erp_inventory_transaction_items')->restrictOnDelete();
        });

        Schema::create('erp_material_physical_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no', 80)->unique();
            $table->string('client_command_id', 120)->unique();
            $table->string('command_hash', 64);
            $table->unsignedInteger('expected_version');
            $table->foreignId('physical_material_id');
            $table->foreignId('source_holding_id');
            $table->foreignId('target_holding_id')->nullable();
            $table->foreignId('source_inventory_balance_id');
            $table->foreignId('target_inventory_balance_id')->nullable();
            $table->foreignId('target_warehouse_id');
            $table->foreignId('target_location_id');
            $table->string('target_batch_no', 80);
            $table->decimal('total_cost', 18, 4);
            $table->string('reason', 1000);
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('inventory_transaction_id')->nullable();
            $table->unsignedBigInteger('moved_by_legacy_id');
            $table->timestamp('moved_at')->nullable();
            $table->timestamps();

            $table->index(['physical_material_id', 'status'], 'material_physical_transfer_status_idx');
            $table->foreign('physical_material_id', 'material_physical_transfer_identity_fk')->references('id')->on('erp_material_physicals')->restrictOnDelete();
            $table->foreign('source_holding_id', 'material_physical_transfer_source_holding_fk')->references('id')->on('erp_material_holdings')->restrictOnDelete();
            $table->foreign('target_holding_id', 'material_physical_transfer_target_holding_fk')->references('id')->on('erp_material_holdings')->restrictOnDelete();
            $table->foreign('source_inventory_balance_id', 'material_physical_transfer_source_balance_fk')->references('id')->on('erp_inventory_balances')->restrictOnDelete();
            $table->foreign('target_inventory_balance_id', 'material_physical_transfer_target_balance_fk')->references('id')->on('erp_inventory_balances')->restrictOnDelete();
            $table->foreign('target_warehouse_id', 'material_physical_transfer_warehouse_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('target_location_id', 'material_physical_transfer_location_fk')->references('id')->on('erp_locations')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'material_physical_transfer_transaction_fk')->references('id')->on('erp_inventory_transactions')->restrictOnDelete();
        });

        Schema::table('erp_material_physical_disposals', function (Blueprint $table): void {
            $table->string('client_command_id', 120)->nullable()->unique()->after('disposal_no');
            $table->string('command_hash', 64)->nullable()->after('client_command_id');
            $table->unsignedInteger('expected_version')->nullable()->after('command_hash');
            $table->foreignId('inventory_transaction_id')->nullable()->after('disposal_holding_id');
            $table->foreign('inventory_transaction_id', 'material_physical_disposal_transaction_fk')
                ->references('id')->on('erp_inventory_transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $hasPostedFacts = DB::table('erp_purchase_receipt_allocation_physicals')->whereNotNull('physical_material_id')->exists()
            || DB::table('erp_inventory_adjustment_item_physicals')->whereNotNull('inventory_transaction_item_id')->exists()
            || DB::table('erp_purchase_return_item_physicals')->whereNotNull('inventory_transaction_item_id')->exists()
            || DB::table('erp_material_physical_transfers')->where('status', 'POSTED')->exists()
            || DB::table('erp_material_physical_disposals')->whereNotNull('inventory_transaction_id')->exists();
        if ($hasPostedFacts) {
            throw new RuntimeException('已有正式实物库存动作，不允许通过结构回退删除采购、调整、退货、调拨或报废历史。');
        }

        Schema::table('erp_material_physical_disposals', function (Blueprint $table): void {
            $table->dropForeign('material_physical_disposal_transaction_fk');
            $table->dropColumn('inventory_transaction_id');
            $table->dropUnique(['client_command_id']);
            $table->dropColumn(['client_command_id', 'command_hash', 'expected_version']);
        });
        Schema::dropIfExists('erp_material_physical_transfers');
        Schema::dropIfExists('erp_purchase_return_item_physicals');
        Schema::dropIfExists('erp_inventory_adjustment_item_physicals');
        Schema::dropIfExists('erp_purchase_receipt_allocation_physicals');
    }
};
