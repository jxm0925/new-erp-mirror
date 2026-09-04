<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_orders', 'order_date')) {
                $table->date('order_date')->nullable()->after('order_time');
            }
            if (!Schema::hasColumn('erp_sales_orders', 'salesperson_id')) {
                $table->unsignedBigInteger('salesperson_id')->nullable()->after('sales_user_legacy_id')->index();
            }
            if (!Schema::hasColumn('erp_sales_orders', 'sales_department_id')) {
                $table->unsignedBigInteger('sales_department_id')->nullable()->after('salesperson_id')->index();
            }
            if (!Schema::hasColumn('erp_sales_orders', 'default_carrier_id')) {
                $table->string('default_carrier_id', 80)->nullable()->after('carrier_id')->index();
            }
            if (!Schema::hasColumn('erp_sales_orders', 'default_carrier_name_snapshot')) {
                $table->string('default_carrier_name_snapshot', 160)->nullable()->after('default_carrier_id');
            }
            if (!Schema::hasColumn('erp_sales_orders', 'logistics_requirement')) {
                $table->text('logistics_requirement')->nullable()->after('shipping_address_snapshot');
            }
            if (!Schema::hasColumn('erp_sales_orders', 'customer_remark')) {
                $table->text('customer_remark')->nullable()->after('logistics_requirement');
            }
            if (!Schema::hasColumn('erp_sales_orders', 'order_remark')) {
                $table->text('order_remark')->nullable()->after('remark');
            }
            if (!Schema::hasColumn('erp_sales_orders', 'quickly')) {
                $table->boolean('quickly')->default(false)->after('is_urgent');
            }
            if (!Schema::hasColumn('erp_sales_orders', 'delay')) {
                $table->boolean('delay')->default(false)->after('is_delay');
            }
        });

        Schema::table('erp_sales_order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_lines', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('line_no')->index();
            }
            if (!Schema::hasColumn('erp_sales_order_lines', 'customization_description')) {
                $table->text('customization_description')->nullable()->after('is_special_customized');
            }
        });

        Schema::table('erp_sales_order_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_attachments', 'version_no')) {
                $table->unsignedInteger('version_no')->default(1)->after('attachment_type');
            }
            if (!Schema::hasColumn('erp_sales_order_attachments', 'replaced_attachment_id')) {
                $table->unsignedBigInteger('replaced_attachment_id')->nullable()->after('version_no')->index();
            }
            if (!Schema::hasColumn('erp_sales_order_attachments', 'deleted_by')) {
                $table->string('deleted_by', 80)->nullable()->after('status');
            }
            if (!Schema::hasColumn('erp_sales_order_attachments', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('deleted_by');
            }
            if (!Schema::hasColumn('erp_sales_order_attachments', 'metadata')) {
                $table->json('metadata')->nullable()->after('deleted_at');
            }
        });
    }

    public function down(): void
    {
        // Stage 5 columns deliberately remain available for historical order snapshots.
    }
};
