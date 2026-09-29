<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_warehouses', function (Blueprint $table) {
            // 与登录、角色及业务操作者共用既有账号身份；不按历史姓名猜配员工。
            $table->unsignedBigInteger('manager_user_id')->nullable()->after('manager');
            $table->foreign('manager_user_id', 'erp_warehouse_manager_fk')
                ->references('legacy_id')->on('erp_legacy_admin_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_warehouses', function (Blueprint $table) {
            $table->dropForeign('erp_warehouse_manager_fk');
            $table->dropColumn('manager_user_id');
        });
    }
};
