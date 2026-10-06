<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_orders', function (Blueprint $table): void {
            $table->unsignedInteger('payment_plan_version')->default(0);
        });
        Schema::create('erp_purchase_payment_plan_items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('erp_purchase_orders')->cascadeOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->string('title', 120)->nullable();
            $table->string('trigger_type', 30);
            $table->string('currency', 10);
            $table->decimal('amount', 18, 4);
            $table->date('due_date')->nullable();
            $table->text('remark')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['purchase_order_id', 'status', 'sequence_no'], 'erp_purchase_pay_plan_order_idx');
            $table->index(['status', 'due_date', 'trigger_type'], 'erp_purchase_pay_plan_due_idx');
        });
        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            // Draft intent never reserves cash or reduces receipt-based payables.
            $table->json('purchase_order_allocations')->nullable();
            $table->unsignedInteger('purchase_order_allocation_version')->default(0);
            $table->text('purchase_order_allocation_reason')->nullable();
        });
        Schema::create('erp_finance_cash_purchase_allocations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cash_document_id')->constrained('erp_finance_cash_documents')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('erp_purchase_orders')->restrictOnDelete();
            $table->foreignId('payment_plan_id')->nullable()->constrained('erp_purchase_payment_plan_items')->restrictOnDelete();
            $table->unsignedInteger('allocation_version');
            $table->string('purpose_key', 60);
            $table->string('direction', 20);
            $table->string('currency', 10);
            $table->decimal('amount', 18, 4);
            $table->string('status', 20)->default('active');
            $table->string('purchase_order_no_snapshot', 100);
            $table->string('plan_title_snapshot', 120)->nullable();
            $table->string('trigger_type_snapshot', 30)->nullable();
            $table->unsignedInteger('sequence_no_snapshot')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['cash_document_id', 'allocation_version', 'purpose_key'], 'erp_cash_purchase_version_purpose_uq');
            $table->index(['purchase_order_id', 'status', 'cash_document_id'], 'erp_cash_purchase_order_status_idx');
            $table->index(['payment_plan_id', 'status'], 'erp_cash_purchase_plan_status_idx');
        });
        Schema::create('erp_finance_cash_purchase_revisions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cash_document_id')->constrained('erp_finance_cash_documents')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('idempotency_key', 100)->nullable()->unique('erp_cash_purchase_revision_key_uq');
            $table->string('request_hash', 64);
            $table->text('reason')->nullable();
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->timestamps();
            $table->unique(['cash_document_id', 'version'], 'erp_cash_purchase_revision_version_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_finance_cash_purchase_revisions');
        Schema::dropIfExists('erp_finance_cash_purchase_allocations');
        Schema::table('erp_finance_cash_documents', fn (Blueprint $table) => $table->dropColumn([
            'purchase_order_allocations', 'purchase_order_allocation_version', 'purchase_order_allocation_reason',
        ]));
        Schema::dropIfExists('erp_purchase_payment_plan_items');
        Schema::table('erp_purchase_orders', fn (Blueprint $table) => $table->dropColumn('payment_plan_version'));
    }
};
