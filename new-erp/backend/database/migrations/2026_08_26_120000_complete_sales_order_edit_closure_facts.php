<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table): void {
            $table->string('search_aliases', 500)->nullable()->after('spec_text');
            $table->text('search_keywords')->nullable()->after('search_aliases');
            $table->decimal('default_tax_rate', 10, 6)->default(0)->after('reference_cost');
            $table->string('default_price_tax_mode', 20)->default('tax_inclusive')->after('default_tax_rate');
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->string('search_aliases', 500)->nullable()->after('model');
            $table->text('search_keywords')->nullable()->after('search_aliases');
        });

        Schema::table('erp_sales_order_changes', function (Blueprint $table): void {
            $table->foreignId('candidate_id')->nullable()->after('sales_order_id')
                ->constrained('erp_sales_order_change_candidates')->nullOnDelete();
            $table->json('structured_diffs')->nullable()->after('after_snapshot');
            $table->json('impact_summary')->nullable()->after('structured_diffs');
            $table->json('approval_requirements')->nullable()->after('impact_summary');
            $table->boolean('immediate_effect')->default(false)->after('approval_requirements');
        });

        Schema::table('erp_sales_order_versions', function (Blueprint $table): void {
            $table->foreignId('candidate_id')->nullable()->after('sales_order_id')
                ->constrained('erp_sales_order_change_candidates')->nullOnDelete();
            $table->json('structured_diffs')->nullable()->after('after_snapshot');
            $table->json('impact_summary')->nullable()->after('structured_diffs');
            $table->boolean('immediate_effect')->default(false)->after('impact_summary');
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_order_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('candidate_id');
            $table->dropColumn(['structured_diffs', 'impact_summary', 'immediate_effect']);
        });

        Schema::table('erp_sales_order_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('candidate_id');
            $table->dropColumn(['structured_diffs', 'impact_summary', 'approval_requirements', 'immediate_effect']);
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->dropColumn(['search_aliases', 'search_keywords']);
        });

        Schema::table('erp_skus', function (Blueprint $table): void {
            $table->dropColumn(['search_aliases', 'search_keywords', 'default_tax_rate', 'default_price_tax_mode']);
        });
    }
};
