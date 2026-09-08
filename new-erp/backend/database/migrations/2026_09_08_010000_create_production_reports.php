<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_quantity_operations', function (Blueprint $table): void {
            $table->decimal('unqualified_base_qty', 18, 8)->default(0)->after('completed_base_qty');
        });

        Schema::create('erp_production_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_no', 80)->unique();
            $table->string('client_command_id', 120)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->decimal('qualified_base_qty', 18, 8)->default(0);
            $table->decimal('unqualified_base_qty', 18, 8)->default(0);
            $table->decimal('scrapped_base_qty', 18, 8)->default(0);
            $table->string('defect_reason', 1000)->nullable();
            $table->string('remark', 2000)->nullable();
            $table->json('attachment_snapshot')->nullable();
            $table->json('labor_snapshot')->nullable();
            $table->boolean('ended_reporter_labor')->default(false);
            $table->unsignedBigInteger('reported_by_legacy_id');
            $table->string('organization_code', 80)->nullable();
            $table->timestamp('reported_at');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['task_id', 'reported_at'], 'erp_prod_report_task_time_idx');
            $table->index(['target_type', 'target_id', 'reported_at'], 'erp_prod_report_target_time_idx');
            $table->index(['work_order_id', 'reported_at'], 'erp_prod_report_wo_time_idx');
            $table->foreign('work_order_id', 'erp_prod_report_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('task_id', 'erp_prod_report_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
            $table->foreign('base_unit_id', 'erp_prod_report_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_reports');
        Schema::table('erp_production_quantity_operations', function (Blueprint $table): void {
            $table->dropColumn('unqualified_base_qty');
        });
    }
};
