<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_payment_methods', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('method_code', 60)->unique();
            $table->string('method_name', 120);
            $table->boolean('available_for_sales')->default(true)->index();
            $table->boolean('available_for_receipt')->default(true)->index();
            $table->boolean('available_for_payment')->default(true)->index();
            $table->string('status', 20)->default('enabled')->index();
            $table->unsignedInteger('sort')->default(0);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
        });

        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->after('pay_type')
                ->constrained('erp_payment_methods')->restrictOnDelete();
            $table->json('payment_method_snapshot')->nullable()->after('payment_method_id');
        });

        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')
                ->constrained('erp_payment_methods')->restrictOnDelete();
            $table->json('payment_method_snapshot')->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('erp_finance_cash_documents', function (Blueprint $table): void {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_snapshot', 'payment_method_id']);
        });
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_snapshot', 'payment_method_id']);
        });
        Schema::dropIfExists('erp_payment_methods');
    }
};
