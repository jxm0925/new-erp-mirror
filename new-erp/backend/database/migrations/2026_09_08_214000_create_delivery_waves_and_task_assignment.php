<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_material_delivery_waves')) {
            Schema::create('erp_material_delivery_waves', function (Blueprint $table): void {
                $table->id();
                $table->string('wave_no', 80)->unique();
                $table->unsignedBigInteger('production_preparation_order_id');
                $table->unsignedBigInteger('production_master_order_id');
                $table->string('status', 30)->default('WAIT_EXECUTION');
                $table->string('client_command_id', 120)->unique();
                $table->string('request_hash', 64);
                $table->unsignedInteger('business_version')->default(1);
                $table->string('organization_code', 80)->nullable();
                $table->unsignedBigInteger('created_by_legacy_id')->nullable();
                $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
                $table->timestamps();

                $table->index(['production_preparation_order_id', 'status'], 'erp_dw_pb_status_idx');
                $table->index(['production_master_order_id', 'status'], 'erp_dw_mwo_status_idx');
                $table->foreign('production_preparation_order_id', 'erp_dw_pb_fk')->references('id')->on('erp_production_preparation_orders')->restrictOnDelete();
                $table->foreign('production_master_order_id', 'erp_dw_mwo_fk')->references('id')->on('erp_production_master_orders')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('erp_material_deliveries', 'delivery_wave_id')) {
            Schema::table('erp_material_deliveries', function (Blueprint $table): void {
                $table->unsignedBigInteger('delivery_wave_id')->nullable()->after('picking_task_id');
                $table->string('assignment_mode', 30)->nullable()->after('delivery_wave_id');
                $table->string('zone_pool_code', 80)->nullable()->after('assignment_mode');
                $table->unsignedBigInteger('assigned_by_legacy_id')->nullable()->after('delivery_user_legacy_id');
                $table->timestamp('assigned_at')->nullable()->after('assigned_by_legacy_id');
                $table->timestamp('claimed_at')->nullable()->after('assigned_at');
                $table->index(['delivery_wave_id', 'status'], 'erp_dt_wave_status_idx');
                $table->index(['assignment_mode', 'zone_pool_code', 'status'], 'erp_dt_pool_status_idx');
                $table->foreign('delivery_wave_id', 'erp_dt_wave_fk')->references('id')->on('erp_material_delivery_waves')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_material_deliveries', 'delivery_wave_id')) {
            Schema::table('erp_material_deliveries', function (Blueprint $table): void {
                $table->dropForeign('erp_dt_wave_fk');
                $table->dropIndex('erp_dt_wave_status_idx');
                $table->dropIndex('erp_dt_pool_status_idx');
                $table->dropColumn(['delivery_wave_id', 'assignment_mode', 'zone_pool_code', 'assigned_by_legacy_id', 'assigned_at', 'claimed_at']);
            });
        }
        Schema::dropIfExists('erp_material_delivery_waves');
    }
};
