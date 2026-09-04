<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_finance_currencies')) {
            Schema::create('erp_finance_currencies', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('currency_code', 10)->unique();
                $table->string('currency_name', 60);
                $table->string('symbol', 12)->nullable();
                $table->unsignedTinyInteger('decimal_places')->default(2);
                $table->boolean('is_base')->default(false)->index();
                $table->string('status', 20)->default('enabled')->index();
                $table->unsignedInteger('sort')->default(0);
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('erp_finance_exchange_rates')) {
            Schema::create('erp_finance_exchange_rates', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('source_currency', 10);
                $table->string('target_currency', 10);
                $table->decimal('rate', 24, 10);
                $table->string('rate_type', 30)->index();
                $table->string('source', 30)->index();
                $table->timestamp('effective_at')->index();
                $table->string('status', 20)->default('enabled')->index();
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('disabled_by')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
                $table->unique(['source_currency', 'target_currency', 'rate_type', 'effective_at'], 'erp_fin_exrate_version_unique');
                $table->index(['source_currency', 'target_currency', 'rate_type', 'status', 'effective_at'], 'erp_fin_exrate_lookup_idx');
            });
        }

        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_finance_cash_documents', 'base_currency')) $table->string('base_currency', 10)->default('CNY')->after('currency');
            if (!Schema::hasColumn('erp_finance_cash_documents', 'exchange_rate_id')) $table->foreignId('exchange_rate_id')->nullable()->after('base_currency')->constrained('erp_finance_exchange_rates')->restrictOnDelete();
            if (!Schema::hasColumn('erp_finance_cash_documents', 'business_exchange_rate')) $table->decimal('business_exchange_rate', 24, 10)->default(1)->after('exchange_rate_id');
            if (!Schema::hasColumn('erp_finance_cash_documents', 'exchange_rate_date')) $table->date('exchange_rate_date')->nullable()->after('business_exchange_rate');
            if (!Schema::hasColumn('erp_finance_cash_documents', 'exchange_rate_source')) $table->string('exchange_rate_source', 30)->nullable()->after('exchange_rate_date');
            if (!Schema::hasColumn('erp_finance_cash_documents', 'base_amount')) $table->decimal('base_amount', 18, 4)->default(0)->after('amount');
        });

        Schema::table('erp_finance_allocations', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_finance_allocations', 'cash_currency')) $table->string('cash_currency', 10)->default('CNY')->after('currency');
            if (!Schema::hasColumn('erp_finance_allocations', 'cash_allocated_amount')) $table->decimal('cash_allocated_amount', 18, 4)->default(0)->after('cash_currency');
            if (!Schema::hasColumn('erp_finance_allocations', 'business_currency')) $table->string('business_currency', 10)->default('CNY')->after('cash_allocated_amount');
            if (!Schema::hasColumn('erp_finance_allocations', 'business_allocated_amount')) $table->decimal('business_allocated_amount', 18, 4)->default(0)->after('business_currency');
            if (!Schema::hasColumn('erp_finance_allocations', 'base_currency')) $table->string('base_currency', 10)->default('CNY')->after('business_allocated_amount');
            if (!Schema::hasColumn('erp_finance_allocations', 'exchange_rate_id')) $table->foreignId('exchange_rate_id')->nullable()->after('base_currency')->constrained('erp_finance_exchange_rates')->restrictOnDelete();
            if (!Schema::hasColumn('erp_finance_allocations', 'allocation_exchange_rate')) $table->decimal('allocation_exchange_rate', 24, 10)->default(1)->after('exchange_rate_id');
            if (!Schema::hasColumn('erp_finance_allocations', 'exchange_rate_date')) $table->date('exchange_rate_date')->nullable()->after('allocation_exchange_rate');
            if (!Schema::hasColumn('erp_finance_allocations', 'exchange_rate_source')) $table->string('exchange_rate_source', 30)->nullable()->after('exchange_rate_date');
            if (!Schema::hasColumn('erp_finance_allocations', 'base_amount')) $table->decimal('base_amount', 18, 4)->default(0)->after('allocated_amount');
        });

        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_sales_orders', 'base_currency')) $table->string('base_currency', 10)->default('CNY')->after('currency');
            if (!Schema::hasColumn('erp_sales_orders', 'exchange_rate_id')) $table->foreignId('exchange_rate_id')->nullable()->after('base_currency')->constrained('erp_finance_exchange_rates')->restrictOnDelete();
            if (!Schema::hasColumn('erp_sales_orders', 'business_exchange_rate')) $table->decimal('business_exchange_rate', 24, 10)->default(1)->after('exchange_rate_id');
            if (!Schema::hasColumn('erp_sales_orders', 'exchange_rate_date')) $table->date('exchange_rate_date')->nullable()->after('business_exchange_rate');
            if (!Schema::hasColumn('erp_sales_orders', 'exchange_rate_source')) $table->string('exchange_rate_source', 30)->nullable()->after('exchange_rate_date');
            if (!Schema::hasColumn('erp_sales_orders', 'base_total_amount')) $table->decimal('base_total_amount', 18, 4)->default(0)->after('total_amount');
        });

        Schema::table('erp_purchase_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_purchase_orders', 'base_currency')) $table->string('base_currency', 10)->default('CNY')->after('currency');
            if (!Schema::hasColumn('erp_purchase_orders', 'exchange_rate_id')) $table->foreignId('exchange_rate_id')->nullable()->after('base_currency')->constrained('erp_finance_exchange_rates')->restrictOnDelete();
            if (!Schema::hasColumn('erp_purchase_orders', 'business_exchange_rate')) $table->decimal('business_exchange_rate', 24, 10)->default(1)->after('exchange_rate_id');
            if (!Schema::hasColumn('erp_purchase_orders', 'exchange_rate_date')) $table->date('exchange_rate_date')->nullable()->after('business_exchange_rate');
            if (!Schema::hasColumn('erp_purchase_orders', 'exchange_rate_source')) $table->string('exchange_rate_source', 30)->nullable()->after('exchange_rate_date');
            if (!Schema::hasColumn('erp_purchase_orders', 'base_amount_excl_tax')) $table->decimal('base_amount_excl_tax', 18, 4)->default(0)->after('amount_excl_tax');
            if (!Schema::hasColumn('erp_purchase_orders', 'base_amount_incl_tax')) $table->decimal('base_amount_incl_tax', 18, 4)->default(0)->after('amount_incl_tax');
        });

        Schema::table('erp_purchase_settlement_sources', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_currency')) $table->string('base_currency', 10)->default('CNY')->after('currency');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'exchange_rate_id')) $table->foreignId('exchange_rate_id')->nullable()->after('base_currency')->constrained('erp_finance_exchange_rates')->restrictOnDelete();
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'business_exchange_rate')) $table->decimal('business_exchange_rate', 24, 10)->default(1)->after('exchange_rate_id');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'exchange_rate_date')) $table->date('exchange_rate_date')->nullable()->after('business_exchange_rate');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'exchange_rate_source')) $table->string('exchange_rate_source', 30)->nullable()->after('exchange_rate_date');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_original_amount')) $table->decimal('base_original_amount', 18, 4)->default(0)->after('original_amount');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_eligible_amount')) $table->decimal('base_eligible_amount', 18, 4)->default(0)->after('eligible_amount');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_frozen_amount')) $table->decimal('base_frozen_amount', 18, 4)->default(0)->after('frozen_amount');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_ap_offset_amount')) $table->decimal('base_ap_offset_amount', 18, 4)->default(0)->after('ap_offset_amount');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_allocated_amount')) $table->decimal('base_allocated_amount', 18, 4)->default(0)->after('allocated_amount');
            if (!Schema::hasColumn('erp_purchase_settlement_sources', 'base_unallocated_amount')) $table->decimal('base_unallocated_amount', 18, 4)->default(0)->after('unallocated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('erp_purchase_settlement_sources', function (Blueprint $table): void {
            $table->dropForeign(['exchange_rate_id']);
            $table->dropColumn(['base_currency', 'exchange_rate_id', 'business_exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_original_amount', 'base_eligible_amount', 'base_frozen_amount', 'base_ap_offset_amount', 'base_allocated_amount', 'base_unallocated_amount']);
        });
        Schema::table('erp_purchase_orders', function (Blueprint $table): void {
            $table->dropForeign(['exchange_rate_id']);
            $table->dropColumn(['base_currency', 'exchange_rate_id', 'business_exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_amount_excl_tax', 'base_amount_incl_tax']);
        });
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['exchange_rate_id']);
            $table->dropColumn(['base_currency', 'exchange_rate_id', 'business_exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_total_amount']);
        });
        Schema::table('erp_finance_allocations', function (Blueprint $table): void {
            $table->dropForeign(['exchange_rate_id']);
            $table->dropColumn(['cash_currency', 'cash_allocated_amount', 'business_currency', 'business_allocated_amount', 'base_currency', 'exchange_rate_id', 'allocation_exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_amount']);
        });
        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            $table->dropForeign(['exchange_rate_id']);
            $table->dropColumn(['base_currency', 'exchange_rate_id', 'business_exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_amount']);
        });
        Schema::dropIfExists('erp_finance_exchange_rates');
        Schema::dropIfExists('erp_finance_currencies');
    }

};

