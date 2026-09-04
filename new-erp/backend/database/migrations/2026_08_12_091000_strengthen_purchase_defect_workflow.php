<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_defect_handlings', function (Blueprint $table): void {
            $table->string('current_step', 50)->nullable()->after('handling_status')->index();
            $table->foreignId('replacement_receipt_id')->nullable()->after('business_doc_no')->constrained('erp_purchase_receipts')->nullOnDelete();
            $table->text('result_description')->nullable()->after('remark');
            $table->timestamp('started_at')->nullable()->after('handled_at');
            $table->timestamp('approved_at')->nullable()->after('started_at');
            $table->timestamp('completed_at')->nullable()->after('approved_at');
            $table->string('updated_by', 80)->nullable()->after('created_by');
        });

        Schema::create('erp_purchase_defect_handling_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('handling_id')->constrained('erp_purchase_defect_handlings')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_step', 50)->nullable();
            $table->string('to_step', 50)->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->text('content')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['handling_id', 'created_at'], 'erp_pdhl_handling_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_defect_handling_logs');
        Schema::table('erp_purchase_defect_handlings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replacement_receipt_id');
            $table->dropColumn(['current_step', 'result_description', 'started_at', 'approved_at', 'completed_at', 'updated_by']);
        });
    }
};
