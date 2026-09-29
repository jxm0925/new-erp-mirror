<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->unsignedInteger('technical_version')->default(0);
            $table->json('technical_snapshot')->nullable();
            $table->unsignedBigInteger('output_configuration_id')->nullable()->index();
        });
        Schema::create('erp_work_order_technical_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('snapshot');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('confirmed_by_legacy_id');
            $table->timestamp('confirmed_at');
            $table->unique(['work_order_id', 'version_no'], 'wo_technical_version_unique');
        });
        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->unsignedBigInteger('configuration_id')->nullable()->index();
            $table->json('configuration_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('erp_work_order_technical_versions')->exists()) {
            throw new RuntimeException('已有工单技术确认历史，不允许回退并删除版本事实。');
        }
        Schema::table('erp_work_order_material_requirements', fn (Blueprint $table) => $table->dropColumn(['configuration_id', 'configuration_snapshot']));
        Schema::dropIfExists('erp_work_order_technical_versions');
        Schema::table('erp_work_orders', fn (Blueprint $table) => $table->dropColumn(['technical_version', 'technical_snapshot', 'output_configuration_id']));
    }
};
