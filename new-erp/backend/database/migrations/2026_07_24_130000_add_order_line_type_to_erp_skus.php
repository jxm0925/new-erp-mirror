<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('erp_skus', 'order_line_type')) {
            Schema::table('erp_skus', function (Blueprint $table) {
                $table->string('order_line_type', 40)->default('physical')->after('fulfillment_type');
            });
        }

    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_skus', 'order_line_type')) {
            Schema::table('erp_skus', fn (Blueprint $table) => $table->dropColumn('order_line_type'));
        }
    }
};

