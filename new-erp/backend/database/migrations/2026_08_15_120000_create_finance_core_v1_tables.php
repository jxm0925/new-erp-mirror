<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_finance_accounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('account_no', 80)->unique();
            $table->string('account_name', 160);
            $table->string('account_type', 30);
            $table->string('bank_name', 160)->nullable();
            $table->string('bank_account_no', 120)->nullable();
            $table->string('currency', 10)->default('CNY');
            $table->string('status', 20)->default('enabled')->index();
            $table->unsignedInteger('sort')->default(0);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_finance_cash_documents', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('direction', 20)->index();
            $table->string('document_no', 80)->unique();
            $table->string('party_type', 30)->index();
            $table->unsignedBigInteger('party_id')->index();
            $table->string('party_name_snapshot', 160);
            $table->date('business_date')->index();
            $table->foreignId('finance_account_id')->constrained('erp_finance_accounts')->restrictOnDelete();
            $table->string('currency', 10)->default('CNY');
            $table->decimal('amount', 18, 4);
            $table->string('payment_method', 50);
            $table->string('external_reference_no', 160)->nullable()->index();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name_snapshot', 80)->nullable();
            $table->text('remark')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('erp_finance_cash_documents')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['party_type', 'party_id', 'business_date'], 'erp_fin_cash_party_date_idx');
            $table->index(['direction', 'status', 'business_date'], 'erp_fin_cash_dir_status_date_idx');
        });

        Schema::create('erp_finance_allocations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cash_document_id')->constrained('erp_finance_cash_documents')->restrictOnDelete();
            $table->string('source_business_type', 60)->index();
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_document_no', 100);
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('party_type', 30);
            $table->unsignedBigInteger('party_id');
            $table->string('currency', 10);
            $table->decimal('source_amount_snapshot', 18, 4);
            $table->decimal('allocated_amount', 18, 4);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('reversal_of_id')->nullable()->constrained('erp_finance_allocations')->restrictOnDelete();
            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->timestamp('allocated_at');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();
            $table->index(['source_business_type', 'source_document_id', 'status'], 'erp_fin_alloc_source_status_idx');
            $table->index(['party_type', 'party_id', 'status'], 'erp_fin_alloc_party_status_idx');
        });

        Schema::create('erp_finance_attachments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('document_type', 40)->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('attachment_type', 50)->default('other');
            $table->string('original_name', 255);
            $table->string('storage_disk', 30)->default('oss');
            $table->string('storage_path', 500);
            $table->string('url', 1000)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('erp_finance_operation_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('document_type', 40)->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->string('action', 50)->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('fact_snapshot')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_finance_invoices', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('invoice_direction', 20)->index();
            $table->string('document_no', 80)->unique();
            $table->string('invoice_no', 120)->nullable()->index();
            $table->string('party_type', 30);
            $table->unsignedBigInteger('party_id');
            $table->string('party_name_snapshot', 160);
            $table->date('invoice_date')->nullable();
            $table->string('currency', 10)->default('CNY');
            $table->decimal('amount_excl_tax', 18, 4);
            $table->decimal('tax_amount', 18, 4);
            $table->decimal('amount_incl_tax', 18, 4);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('red_invoice_of_id')->nullable()->constrained('erp_finance_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['party_type', 'party_id', 'invoice_date'], 'erp_fin_invoice_party_date_idx');
        });

        Schema::create('erp_finance_invoice_allocations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('invoice_id')->constrained('erp_finance_invoices')->restrictOnDelete();
            $table->string('source_business_type', 60);
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_document_no', 100);
            $table->decimal('source_amount_snapshot', 18, 4);
            $table->decimal('allocated_amount', 18, 4);
            $table->string('status', 20)->default('active');
            $table->foreignId('reversal_of_id')->nullable()->constrained('erp_finance_invoice_allocations')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();
            $table->index(['source_business_type', 'source_document_id', 'status'], 'erp_fin_inv_alloc_source_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_finance_invoice_allocations');
        Schema::dropIfExists('erp_finance_invoices');
        Schema::dropIfExists('erp_finance_operation_logs');
        Schema::dropIfExists('erp_finance_attachments');
        Schema::dropIfExists('erp_finance_allocations');
        Schema::dropIfExists('erp_finance_cash_documents');
        Schema::dropIfExists('erp_finance_accounts');
    }
};

