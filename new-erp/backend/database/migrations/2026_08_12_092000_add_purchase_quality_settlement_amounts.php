<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_purchase_receipts', function (Blueprint $table): void {
            $table->decimal('qualified_payable_amount', 18, 4)->default(0)->after('total_amount');
            $table->decimal('quality_hold_amount', 18, 4)->default(0)->after('qualified_payable_amount');
            $table->decimal('rejected_claim_amount', 18, 4)->default(0)->after('quality_hold_amount');
            $table->string('settlement_mode', 32)->default('normal')->after('rejected_claim_amount');
        });
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table): void {
            $table->decimal('qualified_payable_amount', 18, 4)->default(0)->after('receipt_cost');
            $table->decimal('quality_hold_amount', 18, 4)->default(0)->after('qualified_payable_amount');
            $table->decimal('rejected_claim_amount', 18, 4)->default(0)->after('quality_hold_amount');
            $table->decimal('inventory_cost_amount', 18, 4)->default(0)->after('rejected_claim_amount');
            $table->string('settlement_status', 32)->default('pending_inspection')->after('inventory_cost_amount');
        });
        Schema::table('erp_inventory_balances', function (Blueprint $table): void {
            $table->decimal('inventory_value', 18, 4)->default(0)->after('quantity_pending');
            $table->decimal('average_unit_cost', 18, 8)->default(0)->after('inventory_value');
        });
        Schema::table('erp_inventory_transaction_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 18, 8)->default(0)->after('change_qty');
            $table->decimal('cost_amount', 18, 4)->default(0)->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('erp_inventory_transaction_items', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'cost_amount']));
        Schema::table('erp_inventory_balances', fn (Blueprint $table) => $table->dropColumn(['inventory_value', 'average_unit_cost']));
        Schema::table('erp_purchase_receipt_items', fn (Blueprint $table) => $table->dropColumn(['qualified_payable_amount', 'quality_hold_amount', 'rejected_claim_amount', 'inventory_cost_amount', 'settlement_status']));
        Schema::table('erp_purchase_receipts', fn (Blueprint $table) => $table->dropColumn(['qualified_payable_amount', 'quality_hold_amount', 'rejected_claim_amount', 'settlement_mode']));
    }
};
