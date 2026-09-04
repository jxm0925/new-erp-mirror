<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Zero is a business value. Legacy NULL means "not maintained", so it
        // must not be rewritten as a zero price during the one-time import.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('erp_skus', function (Blueprint $table) {
                $table->decimal('sale_price', 14, 2)->nullable()->default(null)->change();
                $table->decimal('reference_cost', 14, 2)->nullable()->default(null)->change();
            });
        } else {
            DB::statement('ALTER TABLE erp_skus MODIFY sale_price DECIMAL(14,2) NULL DEFAULT NULL');
            DB::statement('ALTER TABLE erp_skus MODIFY reference_cost DECIMAL(14,2) NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('erp_skus', function (Blueprint $table) {
                $table->decimal('sale_price', 14, 2)->nullable(false)->default(0)->change();
                $table->decimal('reference_cost', 14, 2)->nullable(false)->default(0)->change();
            });
        } else {
            DB::statement("ALTER TABLE erp_skus MODIFY sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00");
            DB::statement("ALTER TABLE erp_skus MODIFY reference_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00");
        }
    }
};

