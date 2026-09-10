<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('erp_payment_methods', 'business_version')) {
            Schema::table('erp_payment_methods', function (Blueprint $table): void {
                $table->unsignedInteger('business_version')->default(1)->after('updated_by');
            });
        }
        if (! Schema::hasColumn('erp_sales_funding_policies', 'business_version')) {
            Schema::table('erp_sales_funding_policies', function (Blueprint $table): void {
                $table->unsignedBigInteger('created_by')->nullable()->after('remark');
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                $table->unsignedInteger('business_version')->default(1)->after('remark');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_sales_funding_policies', 'business_version')) {
            Schema::table('erp_sales_funding_policies', fn (Blueprint $table) => $table->dropColumn(['business_version', 'updated_by', 'created_by']));
        }
        if (Schema::hasColumn('erp_payment_methods', 'business_version')) {
            Schema::table('erp_payment_methods', fn (Blueprint $table) => $table->dropColumn('business_version'));
        }
    }
};
