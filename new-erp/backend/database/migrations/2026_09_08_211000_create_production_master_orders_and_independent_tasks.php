<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is committed statement by statement. If a process is interrupted
        // after the final ALTER but before Laravel records the migration batch, the
        // schema is already complete. Recognize that exact completed shape so the
        // next migrate run can safely record it instead of failing on CREATE TABLE.
        if (Schema::hasTable('erp_production_master_orders')
            && Schema::hasColumns('erp_work_orders', ['production_master_order_id'])
            && Schema::hasColumns('erp_production_tasks', [
                'production_unit_id',
                'production_unit_operation_id',
                'production_quantity_operation_id',
            ])
            && Schema::hasIndex('erp_work_orders', 'erp_wo_master_status_idx')
            && Schema::hasIndex('erp_production_tasks', 'erp_prod_task_unit_operation_uq')
            && Schema::hasIndex('erp_production_tasks', 'erp_prod_task_qty_operation_uq')
            && Schema::hasIndex('erp_production_tasks', 'erp_prod_task_unit_seq_status_idx')) {
            return;
        }

        Schema::create('erp_production_master_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('master_order_no', 80)->unique();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('active_sales_order_id')->nullable()->unique();
            $table->string('sales_order_no_snapshot', 120);
            $table->unsignedBigInteger('salesperson_legacy_id')->nullable();
            $table->string('salesperson_name_snapshot', 160)->nullable();
            $table->json('customer_snapshot');
            $table->text('order_remark_snapshot')->nullable();
            $table->date('required_delivery_date_snapshot')->nullable();
            $table->string('status', 30)->default('WAIT_CONDITION');
            $table->decimal('production_progress', 8, 4)->default(0);
            $table->decimal('completed_unit_qty', 18, 8)->default(0);
            $table->decimal('total_unit_qty', 18, 8)->default(0);
            $table->decimal('in_progress_unit_qty', 18, 8)->default(0);
            $table->decimal('exception_unit_qty', 18, 8)->default(0);
            $table->string('material_status', 30)->default('WAIT_PREPARE');
            $table->string('funding_status', 30)->default('blocked');
            $table->string('shipment_status', 30)->default('blocked');
            $table->unsignedInteger('business_version')->default(1);
            $table->string('organization_code', 80)->nullable();
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'required_delivery_date_snapshot'], 'erp_mwo_status_delivery_idx');
            $table->index(['salesperson_legacy_id', 'status'], 'erp_mwo_sales_status_idx');
            $table->foreign('sales_order_id', 'erp_mwo_sales_order_fk')->references('id')->on('erp_sales_orders')->restrictOnDelete();
            $table->foreign('active_sales_order_id', 'erp_mwo_active_sales_order_fk')->references('id')->on('erp_sales_orders')->restrictOnDelete();
        });

        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_master_order_id')->nullable()->after('production_demand_id');
            $table->index(['production_master_order_id', 'status'], 'erp_wo_master_status_idx');
            $table->foreign('production_master_order_id', 'erp_wo_master_fk')->references('id')->on('erp_production_master_orders')->restrictOnDelete();
        });

        Schema::table('erp_production_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_unit_id')->nullable()->after('work_order_id');
            $table->unsignedBigInteger('production_unit_operation_id')->nullable()->after('production_unit_id');
            $table->unsignedBigInteger('production_quantity_operation_id')->nullable()->after('production_unit_operation_id');
            $table->unique('production_unit_operation_id', 'erp_prod_task_unit_operation_uq');
            $table->unique('production_quantity_operation_id', 'erp_prod_task_qty_operation_uq');
            $table->index(['production_unit_id', 'sequence_no_snapshot', 'status'], 'erp_prod_task_unit_seq_status_idx');
            $table->foreign('production_unit_id', 'erp_prod_task_unit_fk')->references('id')->on('erp_production_units')->cascadeOnDelete();
            $table->foreign('production_unit_operation_id', 'erp_prod_task_unit_operation_fk')->references('id')->on('erp_production_unit_operations')->cascadeOnDelete();
            $table->foreign('production_quantity_operation_id', 'erp_prod_task_qty_operation_fk')->references('id')->on('erp_production_quantity_operations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_tasks', function (Blueprint $table): void {
            $table->dropForeign('erp_prod_task_unit_fk');
            $table->dropForeign('erp_prod_task_unit_operation_fk');
            $table->dropForeign('erp_prod_task_qty_operation_fk');
            $table->dropUnique('erp_prod_task_unit_operation_uq');
            $table->dropUnique('erp_prod_task_qty_operation_uq');
            $table->dropIndex('erp_prod_task_unit_seq_status_idx');
            $table->dropColumn(['production_unit_id', 'production_unit_operation_id', 'production_quantity_operation_id']);
        });
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->dropForeign('erp_wo_master_fk');
            $table->dropIndex('erp_wo_master_status_idx');
            $table->dropColumn('production_master_order_id');
        });
        Schema::dropIfExists('erp_production_master_orders');
    }
};
