<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_boms', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_boms', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('audit_status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_boms', function (Blueprint $table) {
            if (Schema::hasColumn('erp_boms', 'submitted_at')) {
                $table->dropColumn('submitted_at');
            }
        });
    }
};
