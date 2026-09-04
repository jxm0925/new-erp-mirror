<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_defect_handlings', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_defect_handlings', 'responsible_party')) {
                $table->string('responsible_party', 80)->nullable()->after('defect_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_purchase_defect_handlings', function (Blueprint $table) {
            if (Schema::hasColumn('erp_purchase_defect_handlings', 'responsible_party')) {
                $table->dropColumn('responsible_party');
            }
        });
    }
};
