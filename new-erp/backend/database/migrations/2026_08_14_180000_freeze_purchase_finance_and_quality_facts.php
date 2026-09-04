<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_orders', function (Blueprint $table) {
            $table->decimal('amount_excl_tax', 18, 4)->nullable()->after('total_amount');
            $table->decimal('amount_incl_tax', 18, 4)->nullable()->after('amount_excl_tax');
            $table->decimal('other_purchase_cost_amount', 18, 4)->default(0)->after('freight_amount');
            $table->string('finance_fact_status', 32)->default('pending')->after('other_purchase_cost_amount')->index('epo_finance_fact_status_idx');
        });

        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            $table->string('currency_snapshot', 10)->nullable()->after('tax_rate');
            $table->string('tax_mode_snapshot', 30)->nullable()->after('currency_snapshot');
            $table->decimal('amount_excl_tax', 18, 4)->nullable()->after('amount');
            $table->decimal('tax_amount_snapshot', 18, 4)->nullable()->after('amount_excl_tax');
            $table->decimal('amount_incl_tax', 18, 4)->nullable()->after('tax_amount_snapshot');
            $table->decimal('freight_allocated_amount', 18, 4)->default(0)->after('amount_incl_tax');
            $table->decimal('other_purchase_cost_amount', 18, 4)->default(0)->after('freight_allocated_amount');
            $table->decimal('contract_amount_snapshot', 18, 4)->nullable()->after('other_purchase_cost_amount');
            $table->timestamp('commercial_snapshot_at')->nullable()->after('contract_amount_snapshot');
        });

        Schema::table('erp_purchase_receipts', function (Blueprint $table) {
            $table->boolean('has_stock_items')->default(true)->after('stock_post_status')->index('epr_has_stock_items_idx');
            $table->string('fulfillment_status', 32)->default('pending')->after('has_stock_items')->index('epr_fulfillment_status_idx');
            $table->decimal('physical_received_base_qty', 18, 8)->default(0)->after('total_unqualified_qty');
            $table->decimal('contract_fulfilled_base_qty', 18, 8)->default(0)->after('physical_received_base_qty');
            $table->decimal('replacement_received_base_qty', 18, 8)->default(0)->after('contract_fulfilled_base_qty');
            $table->string('currency_snapshot', 10)->nullable()->after('settlement_mode');
            $table->string('tax_mode_snapshot', 30)->nullable()->after('currency_snapshot');
            $table->decimal('amount_excl_tax', 18, 4)->nullable()->after('total_amount');
            $table->decimal('tax_amount_snapshot', 18, 4)->nullable()->after('amount_excl_tax');
            $table->decimal('amount_incl_tax', 18, 4)->nullable()->after('tax_amount_snapshot');
            $table->decimal('settlement_amount', 18, 4)->default(0)->after('rejected_claim_amount');
            $table->decimal('inventory_cost_amount', 18, 4)->default(0)->after('settlement_amount');
            $table->decimal('freight_amount_snapshot', 18, 4)->default(0)->after('inventory_cost_amount');
            $table->decimal('other_purchase_cost_amount', 18, 4)->default(0)->after('freight_amount_snapshot');
            $table->string('finance_fact_status', 32)->default('pending')->after('other_purchase_cost_amount')->index('epr_finance_fact_status_idx');
        });

        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            $table->boolean('is_stock_item_snapshot')->default(true)->after('item_id')->index('epri_stock_item_idx');
            $table->string('quality_fact_origin', 32)->default('current')->after('is_stock_item_snapshot')->index('epri_quality_origin_idx');
            $table->decimal('original_received_qty', 18, 8)->nullable()->after('unqualified_qty');
            $table->decimal('original_qualified_qty', 18, 8)->nullable()->after('original_received_qty');
            $table->decimal('original_unqualified_qty', 18, 8)->nullable()->after('original_qualified_qty');
            $table->decimal('original_received_base_qty', 18, 8)->nullable()->after('unqualified_base_qty');
            $table->decimal('original_qualified_base_qty', 18, 8)->nullable()->after('original_received_base_qty');
            $table->decimal('original_unqualified_base_qty', 18, 8)->nullable()->after('original_qualified_base_qty');
            $table->decimal('rework_qualified_base_qty', 18, 8)->default(0)->after('original_unqualified_base_qty');
            $table->decimal('concession_accepted_base_qty', 18, 8)->default(0)->after('rework_qualified_base_qty');
            $table->decimal('replacement_qualified_base_qty', 18, 8)->default(0)->after('concession_accepted_base_qty');
            $table->decimal('rejected_base_qty', 18, 8)->default(0)->after('replacement_qualified_base_qty');
            $table->decimal('scrapped_base_qty', 18, 8)->default(0)->after('rejected_base_qty');
            $table->decimal('final_stockable_base_qty', 18, 8)->default(0)->after('scrapped_base_qty');
            $table->decimal('physical_received_base_qty', 18, 8)->default(0)->after('final_stockable_base_qty');
            $table->decimal('contract_fulfilled_base_qty', 18, 8)->default(0)->after('physical_received_base_qty');
            $table->decimal('replacement_received_base_qty', 18, 8)->default(0)->after('contract_fulfilled_base_qty');
            $table->string('currency_snapshot', 10)->nullable()->after('tax_rate');
            $table->string('tax_mode_snapshot', 30)->nullable()->after('currency_snapshot');
            $table->decimal('amount_excl_tax', 18, 4)->nullable()->after('receipt_cost');
            $table->decimal('tax_amount_snapshot', 18, 4)->nullable()->after('amount_excl_tax');
            $table->decimal('amount_incl_tax', 18, 4)->nullable()->after('tax_amount_snapshot');
            $table->decimal('settlement_amount', 18, 4)->default(0)->after('qualified_payable_amount');
            $table->decimal('freight_allocated_amount', 18, 4)->default(0)->after('inventory_cost_amount');
            $table->decimal('other_purchase_cost_amount', 18, 4)->default(0)->after('freight_allocated_amount');
            $table->string('finance_fact_status', 32)->default('pending')->after('settlement_status')->index('epri_finance_fact_status_idx');
            $table->timestamp('facts_frozen_at')->nullable()->after('finance_fact_status');
        });

        Schema::table('erp_purchase_defect_handlings', function (Blueprint $table) {
            $table->decimal('amount_excl_tax', 18, 4)->nullable()->after('handling_qty');
            $table->decimal('tax_amount', 18, 4)->nullable()->after('amount_excl_tax');
            $table->decimal('amount_incl_tax', 18, 4)->nullable()->after('tax_amount');
            $table->string('settlement_effect_type', 32)->default('PENDING')->after('amount_incl_tax');
            $table->string('finance_fact_status', 32)->default('pending')->after('settlement_effect_type')->index('epdh_finance_fact_status_idx');
        });

        Schema::table('erp_purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('source_order_id')->nullable()->after('source_receipt_id')->index('eprt_source_order_idx');
            $table->string('currency_snapshot', 10)->nullable()->after('supplier_id');
            $table->string('settlement_effect_type', 32)->default('PENDING')->after('currency_snapshot')->index('eprt_settlement_effect_idx');
            $table->decimal('amount_excl_tax', 18, 4)->default(0)->after('return_reason');
            $table->decimal('tax_amount', 18, 4)->default(0)->after('amount_excl_tax');
            $table->decimal('amount_incl_tax', 18, 4)->default(0)->after('tax_amount');
            $table->decimal('settlement_amount', 18, 4)->default(0)->after('amount_incl_tax');
            $table->decimal('cost_amount', 18, 4)->default(0)->after('settlement_amount');
            $table->string('finance_fact_status', 32)->default('pending')->after('cost_amount')->index('eprt_finance_fact_status_idx');
        });

        Schema::table('erp_purchase_return_items', function (Blueprint $table) {
            $table->unsignedBigInteger('original_purchase_line_id')->nullable()->after('source_receipt_item_id')->index('eprti_purchase_line_idx');
            $table->unsignedBigInteger('original_inventory_transaction_id')->nullable()->after('original_purchase_line_id')->index('eprti_inventory_tx_idx');
            $table->string('currency_snapshot', 10)->nullable()->after('unit_cost_snapshot');
            $table->string('tax_mode_snapshot', 30)->nullable()->after('currency_snapshot');
            $table->decimal('tax_rate_snapshot', 8, 4)->nullable()->after('tax_mode_snapshot');
            $table->decimal('return_unit_price', 18, 8)->nullable()->after('tax_rate_snapshot');
            $table->decimal('return_amount_excl_tax', 18, 4)->nullable()->after('return_unit_price');
            $table->decimal('return_tax_amount', 18, 4)->nullable()->after('return_amount_excl_tax');
            $table->decimal('return_amount_incl_tax', 18, 4)->nullable()->after('return_tax_amount');
            $table->decimal('settlement_amount', 18, 4)->nullable()->after('return_amount_incl_tax');
            $table->decimal('inventory_cost_amount', 18, 4)->nullable()->after('settlement_amount');
            $table->string('finance_fact_status', 32)->default('pending')->after('inventory_cost_amount')->index('eprti_finance_fact_status_idx');
        });

        Schema::table('erp_purchase_exchange_orders', function (Blueprint $table) {
            $table->decimal('replacement_received_base_qty', 18, 8)->default(0)->after('exchange_base_qty');
            $table->decimal('contract_fulfilled_base_qty', 18, 8)->default(0)->after('replacement_received_base_qty');
            $table->decimal('replacement_payable_amount', 18, 4)->default(0)->after('exchange_additional_payable_amount');
            $table->string('currency_snapshot', 10)->nullable()->after('replacement_payable_amount');
            $table->string('finance_fact_status', 32)->default('pending')->after('currency_snapshot')->index('epeo_finance_fact_status_idx');
        });

        Schema::table('erp_inventory_transaction_items', function (Blueprint $table) {
            $table->decimal('purchase_amount_snapshot', 18, 4)->nullable()->after('cost_amount');
            $table->decimal('freight_amount_snapshot', 18, 4)->default(0)->after('purchase_amount_snapshot');
            $table->decimal('other_purchase_cost_amount_snapshot', 18, 4)->default(0)->after('freight_amount_snapshot');
            $table->string('cost_source_type', 40)->nullable()->after('other_purchase_cost_amount_snapshot')->index('eiti_cost_source_type_idx');
        });

        Schema::table('erp_inventory_reservations', function (Blueprint $table) {
            $table->string('source_document_no', 100)->nullable()->after('source_order_line_id')->index('eir_source_document_no_idx');
            $table->unsignedBigInteger('reserved_by')->nullable()->after('reserved_at');
        });

    }

    public function down(): void
    {
        Schema::table('erp_inventory_reservations', fn (Blueprint $table) => $table->dropColumn(['source_document_no', 'reserved_by']));
        Schema::table('erp_inventory_transaction_items', fn (Blueprint $table) => $table->dropColumn(['purchase_amount_snapshot', 'freight_amount_snapshot', 'other_purchase_cost_amount_snapshot', 'cost_source_type']));
        Schema::table('erp_purchase_exchange_orders', fn (Blueprint $table) => $table->dropColumn(['replacement_received_base_qty', 'contract_fulfilled_base_qty', 'replacement_payable_amount', 'currency_snapshot', 'finance_fact_status']));
        Schema::table('erp_purchase_return_items', fn (Blueprint $table) => $table->dropColumn(['original_purchase_line_id', 'original_inventory_transaction_id', 'currency_snapshot', 'tax_mode_snapshot', 'tax_rate_snapshot', 'return_unit_price', 'return_amount_excl_tax', 'return_tax_amount', 'return_amount_incl_tax', 'settlement_amount', 'inventory_cost_amount', 'finance_fact_status']));
        Schema::table('erp_purchase_returns', fn (Blueprint $table) => $table->dropColumn(['source_order_id', 'currency_snapshot', 'settlement_effect_type', 'amount_excl_tax', 'tax_amount', 'amount_incl_tax', 'settlement_amount', 'cost_amount', 'finance_fact_status']));
        Schema::table('erp_purchase_defect_handlings', fn (Blueprint $table) => $table->dropColumn(['amount_excl_tax', 'tax_amount', 'amount_incl_tax', 'settlement_effect_type', 'finance_fact_status']));
        Schema::table('erp_purchase_receipt_items', fn (Blueprint $table) => $table->dropColumn(['is_stock_item_snapshot', 'quality_fact_origin', 'original_received_qty', 'original_qualified_qty', 'original_unqualified_qty', 'original_received_base_qty', 'original_qualified_base_qty', 'original_unqualified_base_qty', 'rework_qualified_base_qty', 'concession_accepted_base_qty', 'replacement_qualified_base_qty', 'rejected_base_qty', 'scrapped_base_qty', 'final_stockable_base_qty', 'physical_received_base_qty', 'contract_fulfilled_base_qty', 'replacement_received_base_qty', 'currency_snapshot', 'tax_mode_snapshot', 'amount_excl_tax', 'tax_amount_snapshot', 'amount_incl_tax', 'settlement_amount', 'freight_allocated_amount', 'other_purchase_cost_amount', 'finance_fact_status', 'facts_frozen_at']));
        Schema::table('erp_purchase_receipts', fn (Blueprint $table) => $table->dropColumn(['has_stock_items', 'fulfillment_status', 'physical_received_base_qty', 'contract_fulfilled_base_qty', 'replacement_received_base_qty', 'currency_snapshot', 'tax_mode_snapshot', 'amount_excl_tax', 'tax_amount_snapshot', 'amount_incl_tax', 'settlement_amount', 'inventory_cost_amount', 'freight_amount_snapshot', 'other_purchase_cost_amount', 'finance_fact_status']));
        Schema::table('erp_purchase_order_items', fn (Blueprint $table) => $table->dropColumn(['currency_snapshot', 'tax_mode_snapshot', 'amount_excl_tax', 'tax_amount_snapshot', 'amount_incl_tax', 'freight_allocated_amount', 'other_purchase_cost_amount', 'contract_amount_snapshot', 'commercial_snapshot_at']));
        Schema::table('erp_purchase_orders', fn (Blueprint $table) => $table->dropColumn(['amount_excl_tax', 'amount_incl_tax', 'other_purchase_cost_amount', 'finance_fact_status']));
    }
};

