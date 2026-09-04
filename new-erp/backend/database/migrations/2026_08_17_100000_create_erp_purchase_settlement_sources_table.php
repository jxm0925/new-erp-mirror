<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_settlement_sources', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('source_type', 60);
            $table->string('source_document_type', 60);
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_document_no', 100);
            $table->foreignId('source_receipt_id')->constrained('erp_purchase_receipts')->restrictOnDelete();
            $table->foreignId('source_line_id')->constrained('erp_purchase_receipt_items')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('erp_purchase_orders')->nullOnDelete();
            $table->string('purchase_order_no_snapshot', 100)->nullable();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->string('supplier_name_snapshot', 160);
            $table->string('currency', 10)->default('CNY');
            $table->date('business_date');
            $table->decimal('original_amount', 18, 4)->default(0);
            $table->decimal('eligible_amount', 18, 4)->default(0);
            $table->decimal('frozen_amount', 18, 4)->default(0);
            $table->decimal('ap_offset_amount', 18, 4)->default(0);
            $table->decimal('allocated_amount', 18, 4)->default(0);
            $table->decimal('unallocated_amount', 18, 4)->default(0);
            $table->decimal('invoice_matched_amount', 18, 4)->default(0);
            $table->decimal('invoice_unmatched_amount', 18, 4)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('source_version')->default(1);
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_document_type', 'source_document_id', 'source_line_id', 'source_version'],
                'erp_purchase_settlement_source_version_unique',
            );
            $table->index(['supplier_id', 'currency', 'status', 'business_date'], 'erp_purchase_settlement_supplier_status_idx');
            $table->index(['purchase_order_id', 'status'], 'erp_purchase_settlement_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_settlement_sources');
    }
};
