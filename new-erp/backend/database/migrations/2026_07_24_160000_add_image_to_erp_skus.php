<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_skus', 'image')) $table->string('image')->nullable()->after('spec_text');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_skus', 'image')) Schema::table('erp_skus', fn (Blueprint $table) => $table->dropColumn('image'));
    }
};
