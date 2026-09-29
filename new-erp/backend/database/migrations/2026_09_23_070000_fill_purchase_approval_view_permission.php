<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 历史默认登记缺少来源权限，导致正式采购对象无法发布手动审批流程。
        // 仅补系统内置对象的空配置，不改变业务单据，也不覆盖已有权限。
        DB::table('erp_approval_business_objects')
            ->where('object_code', 'PURCHASE_ORDER')
            ->where('source_table', 'erp_purchase_orders')
            ->where(fn ($query) => $query->whereNull('view_permission_code')->orWhere('view_permission_code', ''))
            ->update(['view_permission_code' => 'purchase.order.view']);
    }

    public function down(): void
    {
        // 不清空已被发布流程引用的安全权限配置。
    }
};
