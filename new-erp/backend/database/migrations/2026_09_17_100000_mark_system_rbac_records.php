<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_rbac_permissions', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('enabled')->index();
        });
        Schema::table('erp_rbac_roles', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('enabled')->index();
        });
    }

    public function down(): void
    {
        Schema::table('erp_rbac_roles', function (Blueprint $table): void {
            $table->dropIndex(['is_system']);
            $table->dropColumn('is_system');
        });
        Schema::table('erp_rbac_permissions', function (Blueprint $table): void {
            $table->dropIndex(['is_system']);
            $table->dropColumn('is_system');
        });
    }
};
