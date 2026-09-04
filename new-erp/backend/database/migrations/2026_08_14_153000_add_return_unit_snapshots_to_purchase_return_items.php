<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_return_items', function (Blueprint $table): void {
            $table->foreignId('return_unit_id')->nullable()->after('base_unit_id')->constrained('erp_units')->nullOnDelete();
            $table->string('return_unit_name_snapshot', 80)->nullable()->after('return_unit_id');
            $table->decimal('return_conversion_factor_snapshot', 18, 8)->default(1)->after('return_unit_name_snapshot');
            $table->decimal('requested_return_qty', 18, 8)->nullable()->after('return_conversion_factor_snapshot');
        });

    }

    public function down(): void
    {
        Schema::table('erp_purchase_return_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_unit_id');
            $table->dropColumn(['return_unit_name_snapshot', 'return_conversion_factor_snapshot', 'requested_return_qty']);
        });
    }
};

