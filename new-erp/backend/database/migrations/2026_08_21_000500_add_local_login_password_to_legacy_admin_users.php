<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_legacy_admin_users')
            || Schema::hasColumn('erp_legacy_admin_users', 'password_hash')) {
            return;
        }

        Schema::table('erp_legacy_admin_users', function (Blueprint $table) {
            $table->string('password_hash', 255)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('erp_legacy_admin_users')
            && Schema::hasColumn('erp_legacy_admin_users', 'password_hash')) {
            Schema::table('erp_legacy_admin_users', function (Blueprint $table) {
                $table->dropColumn('password_hash');
            });
        }
    }
};
