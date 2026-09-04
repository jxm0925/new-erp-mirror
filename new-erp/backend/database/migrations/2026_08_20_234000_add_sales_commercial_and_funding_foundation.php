<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_sales_channels')) Schema::create('erp_sales_channels', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('channel_code', 60)->unique();
            $table->string('channel_name', 120);
            $table->string('channel_type', 40)->index();
            $table->string('transaction_mode', 40)->index();
            $table->string('default_funding_policy_code', 60)->nullable()->index();
            $table->boolean('requires_external_order_no')->default(false);
            $table->boolean('is_default')->default(false)->index();
            $table->string('status', 20)->default('enabled')->index();
            $table->unsignedInteger('sort')->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('erp_sales_funding_policies')) Schema::create('erp_sales_funding_policies', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('policy_code', 60)->unique();
            $table->string('policy_name', 120);
            $table->string('policy_type', 40)->index();
            $table->string('production_threshold_type', 20)->default('ratio');
            $table->decimal('production_threshold_value', 18, 6)->default(1);
            $table->boolean('shipment_requires_full_payment')->default(true);
            $table->string('status', 20)->default('enabled')->index();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_sales_orders', 'sales_channel_id')) $table->foreignId('sales_channel_id')->nullable()->after('platform2')->constrained('erp_sales_channels')->nullOnDelete();
            if (!Schema::hasColumn('erp_sales_orders', 'sales_channel_code_snapshot')) $table->string('sales_channel_code_snapshot', 60)->nullable()->after('sales_channel_id');
            if (!Schema::hasColumn('erp_sales_orders', 'sales_channel_name_snapshot')) $table->string('sales_channel_name_snapshot', 120)->nullable()->after('sales_channel_code_snapshot');
            if (!Schema::hasColumn('erp_sales_orders', 'channel_type_snapshot')) $table->string('channel_type_snapshot', 40)->nullable()->after('sales_channel_name_snapshot');
            if (!Schema::hasColumn('erp_sales_orders', 'transaction_mode')) $table->string('transaction_mode', 40)->nullable()->after('channel_type_snapshot');
            if (!Schema::hasColumn('erp_sales_orders', 'external_order_no')) $table->string('external_order_no', 160)->nullable()->after('origin_order_no');
            if (!Schema::hasColumn('erp_sales_orders', 'channel_ordered_at')) $table->timestamp('channel_ordered_at')->nullable()->after('order_time');
            if (!Schema::hasColumn('erp_sales_orders', 'funding_policy_id')) $table->foreignId('funding_policy_id')->nullable()->after('transaction_mode')->constrained('erp_sales_funding_policies')->nullOnDelete();
            if (!Schema::hasColumn('erp_sales_orders', 'funding_policy_snapshot')) $table->json('funding_policy_snapshot')->nullable()->after('funding_policy_id');
            if (!Schema::hasColumn('erp_sales_orders', 'contract_no')) $table->string('contract_no', 120)->nullable()->after('external_order_no');
            if (!Schema::hasColumn('erp_sales_orders', 'payment_terms_snapshot')) $table->json('payment_terms_snapshot')->nullable()->after('funding_policy_snapshot');
            if (!Schema::hasColumn('erp_sales_orders', 'final_receivable_amount')) $table->decimal('final_receivable_amount', 18, 4)->default(0)->after('total_amount');
            if (!Schema::hasColumn('erp_sales_orders', 'payment_status')) $table->string('payment_status', 30)->default('unpaid')->after('confirm_status')->index();
            if (!Schema::hasColumn('erp_sales_orders', 'production_funding_status')) $table->string('production_funding_status', 30)->default('blocked')->after('payment_status')->index();
            if (!Schema::hasColumn('erp_sales_orders', 'shipment_funding_status')) $table->string('shipment_funding_status', 30)->default('blocked')->after('production_funding_status')->index();
            if (!Schema::hasColumn('erp_sales_orders', 'actual_sales_cost_amount')) $table->decimal('actual_sales_cost_amount', 18, 4)->default(0)->after('cost_amount');
        });

        Schema::table('erp_sales_order_lines', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_sales_order_lines', 'fulfillment_method')) $table->string('fulfillment_method', 30)->default('auto')->after('fulfillment_type')->index();
            if (!Schema::hasColumn('erp_sales_order_lines', 'price_tax_mode')) $table->string('price_tax_mode', 20)->default('tax_inclusive')->after('unit_price');
            if (!Schema::hasColumn('erp_sales_order_lines', 'discount_rate')) $table->decimal('discount_rate', 10, 6)->default(1)->after('price_tax_mode');
            if (!Schema::hasColumn('erp_sales_order_lines', 'tax_rate')) $table->decimal('tax_rate', 10, 6)->default(0)->after('discount_rate');
            if (!Schema::hasColumn('erp_sales_order_lines', 'amount_excl_tax')) $table->decimal('amount_excl_tax', 18, 4)->default(0)->after('amount');
            if (!Schema::hasColumn('erp_sales_order_lines', 'tax_amount')) $table->decimal('tax_amount', 18, 4)->default(0)->after('amount_excl_tax');
            if (!Schema::hasColumn('erp_sales_order_lines', 'amount_incl_tax')) $table->decimal('amount_incl_tax', 18, 4)->default(0)->after('tax_amount');
            if (!Schema::hasColumn('erp_sales_order_lines', 'commercial_snapshot')) $table->json('commercial_snapshot')->nullable()->after('sku_snapshot');
        });

    }

    public function down(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            foreach (['sales_channel_id', 'sales_channel_code_snapshot', 'sales_channel_name_snapshot', 'channel_type_snapshot', 'transaction_mode', 'external_order_no', 'channel_ordered_at', 'funding_policy_id', 'funding_policy_snapshot', 'contract_no', 'payment_terms_snapshot', 'final_receivable_amount', 'payment_status', 'production_funding_status', 'shipment_funding_status', 'actual_sales_cost_amount'] as $column) if (Schema::hasColumn('erp_sales_orders', $column)) $table->dropColumn($column);
        });
        Schema::table('erp_sales_order_lines', function (Blueprint $table): void {
            foreach (['fulfillment_method', 'price_tax_mode', 'discount_rate', 'tax_rate', 'amount_excl_tax', 'tax_amount', 'amount_incl_tax', 'commercial_snapshot'] as $column) if (Schema::hasColumn('erp_sales_order_lines', $column)) $table->dropColumn($column);
        });
        Schema::dropIfExists('erp_sales_channels');
        Schema::dropIfExists('erp_sales_funding_policies');
    }
};

