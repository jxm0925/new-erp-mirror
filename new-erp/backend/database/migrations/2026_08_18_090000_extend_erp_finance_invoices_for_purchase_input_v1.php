<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_finance_invoices', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('invoice_code', 80)->nullable()->after('invoice_no');
            $table->string('invoice_type', 40)->nullable()->after('invoice_code');
            $table->date('received_date')->nullable()->after('invoice_date');
            $table->json('tax_detail')->nullable()->after('tax_amount');
            $table->text('remark')->nullable()->after('tax_detail');
            $table->string('red_reason', 255)->nullable()->after('red_invoice_of_id');
            $table->date('red_date')->nullable()->after('red_reason');
            $table->index(['invoice_direction', 'currency', 'status', 'invoice_date'], 'erp_fin_invoice_query_idx');
            $table->index(['party_type', 'party_id', 'received_date'], 'erp_fin_invoice_party_received_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_finance_invoices', function (Blueprint $table): void {
            $table->dropIndex('erp_fin_invoice_query_idx');
            $table->dropIndex('erp_fin_invoice_party_received_idx');
            $table->dropColumn(['invoice_code', 'invoice_type', 'received_date', 'tax_detail', 'remark', 'red_reason', 'red_date']);
        });
    }
};
