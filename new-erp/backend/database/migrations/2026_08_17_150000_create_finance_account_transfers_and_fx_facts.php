<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_finance_account_transfers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('transfer_no', 80)->unique();
            $table->foreignId('source_account_id')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->foreignId('target_account_id')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->string('source_currency', 10);
            $table->decimal('source_amount', 18, 4);
            $table->string('target_currency', 10);
            $table->decimal('target_amount', 18, 4);
            $table->string('base_currency', 10);
            $table->decimal('actual_exchange_rate', 24, 10)->default(1);
            $table->decimal('source_base_amount', 18, 4)->default(0);
            $table->decimal('target_base_amount', 18, 4)->default(0);
            $table->decimal('realized_fx_gain_loss', 18, 4)->default(0);
            $table->decimal('fee_amount', 18, 4)->default(0);
            $table->string('fee_currency', 10)->nullable();
            $table->decimal('fee_base_amount', 18, 4)->default(0);
            $table->date('business_date')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['source_account_id', 'status', 'business_date'], 'erp_fin_transfer_source_idx');
            $table->index(['target_account_id', 'status', 'business_date'], 'erp_fin_transfer_target_idx');
        });

        // Immutable account-movement facts are the sole source of account
        // balances and foreign-currency carrying value.  A transfer never
        // mutates a balance column directly.
        Schema::create('erp_finance_account_movements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('finance_account_id')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->string('movement_type', 30)->index(); // cash_document / transfer_in / transfer_out / platform_fee
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('direction', 10); // in / out
            $table->string('currency', 10);
            $table->decimal('original_amount', 18, 4);
            $table->string('base_currency', 10);
            $table->decimal('base_amount', 18, 4);
            $table->date('business_date')->index();
            $table->string('status', 20)->default('confirmed')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'direction'], 'erp_fin_account_movement_source_unique');
            $table->index(['finance_account_id', 'status', 'business_date'], 'erp_fin_account_movement_balance_idx');
        });

        // Fees stay independent of settlement / receipt facts. They are also
        // represented by an outgoing account movement for balance purposes.
        Schema::create('erp_finance_platform_fees', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('fee_no', 80)->unique();
            $table->foreignId('finance_account_id')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->foreignId('cash_document_id')->nullable()->constrained('erp_finance_cash_documents')->restrictOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('erp_finance_account_transfers')->restrictOnDelete();
            $table->string('currency', 10);
            $table->decimal('amount', 18, 4);
            $table->string('base_currency', 10);
            $table->foreignId('exchange_rate_id')->nullable()->constrained('erp_finance_exchange_rates')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->decimal('base_amount', 18, 4);
            $table->string('fee_type', 30)->default('platform')->index();
            $table->date('business_date')->index();
            $table->string('status', 20)->default('confirmed')->index();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['finance_account_id', 'status', 'business_date'], 'erp_fin_platform_fee_account_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_finance_platform_fees');
        Schema::dropIfExists('erp_finance_account_movements');
        Schema::dropIfExists('erp_finance_account_transfers');
    }
};

