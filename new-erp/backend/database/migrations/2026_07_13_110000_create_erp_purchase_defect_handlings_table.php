<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_defect_handlings', function (Blueprint $table) {
            $table->id();
            $table->string('handling_no', 80)->unique();
            $table->foreignId('receipt_id')->constrained('erp_purchase_receipts')->cascadeOnDelete();
            $table->foreignId('receipt_item_id')->constrained('erp_purchase_receipt_items')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('erp_suppliers')->nullOnDelete();
            $table->string('handling_method', 40);
            $table->decimal('handling_qty', 14, 4);
            $table->string('handling_status', 30)->default('pending');
            $table->string('business_doc_type', 40)->nullable();
            $table->string('business_doc_no', 80)->nullable();
            $table->string('defect_reason', 120)->nullable();
            $table->text('defect_description')->nullable();
            $table->text('remark')->nullable();
            $table->string('created_by', 80)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['receipt_item_id', 'handling_method'], 'erp_defect_line_method_idx');
            $table->index(['handling_status', 'handling_method'], 'erp_defect_status_method_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_defect_handlings');
    }
};
