<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Account names identify login identities. Refuse an ambiguous upgrade
        // before any DDL rather than renaming or merging employee identities.
        if (DB::table('erp_legacy_admin_users')->select('username')->whereNotNull('username')
            ->groupBy('username')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('管理员账号存在重复登录名，请先核对重复身份后再升级。');
        }
        foreach (['erp_legacy_admin_users', 'erp_departments', 'erp_rbac_roles'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unsignedInteger('business_version')->default(1);
                if ($name !== 'erp_rbac_roles') {
                    $table->boolean('local_managed')->default(false);
                    $table->timestamp('deleted_at')->nullable()->index();
                    $table->unsignedBigInteger('deleted_by')->nullable();
                }
            });
        }

        Schema::table('erp_legacy_admin_users', fn (Blueprint $table) => $table->unique('username', 'erp_admin_username_unique'));

        Schema::create('erp_system_management_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('command_id', 80)->unique();
            $table->string('operation', 60);
            $table->unsignedBigInteger('operator_id');
            $table->string('request_hash', 64);
            $table->json('response_snapshot');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_system_management_commands')->exists()) {
            throw new RuntimeException('已有系统管理操作事实，不能回退并删除命令账本。');
        }
        Schema::dropIfExists('erp_system_management_commands');
        Schema::table('erp_legacy_admin_users', fn (Blueprint $table) => $table->dropUnique('erp_admin_username_unique'));
        foreach (['erp_legacy_admin_users', 'erp_departments', 'erp_rbac_roles'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropColumn('business_version');
                if ($name !== 'erp_rbac_roles') $table->dropColumn(['local_managed', 'deleted_at', 'deleted_by']);
            });
        }
    }
};
