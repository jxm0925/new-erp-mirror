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
            $table->string('red_scope', 20)->nullable()->after('red_date');
            $table->string('red_match_handling', 40)->nullable()->after('red_scope');
            $table->index(['red_invoice_of_id', 'status'], 'erp_fin_invoice_red_parent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_finance_invoices', function (Blueprint $table): void {
            $table->dropIndex('erp_fin_invoice_red_parent_status_idx');
            $table->dropColumn(['red_scope', 'red_match_handling']);
        });
    }
};
