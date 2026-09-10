<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_preparation_order_lines', function (Blueprint $table): void {
            $table->timestamp('planned_start_at')->nullable()->after('received_base_qty');
            $table->unsignedInteger('delivery_lead_minutes')->nullable()->after('planned_start_at');
            $table->string('delivery_trigger_status', 40)->default('WAIT_CONFIGURATION')->after('delivery_lead_minutes');
            $table->timestamp('delivery_released_at')->nullable()->after('delivery_trigger_status');
            $table->string('delivery_release_source', 40)->nullable()->after('delivery_released_at');
            $table->text('delivery_release_reason')->nullable()->after('delivery_release_source');
            $table->unsignedBigInteger('delivery_released_by_legacy_id')->nullable()->after('delivery_release_reason');
            $table->index(['delivery_trigger_status', 'planned_start_at'], 'erp_pb_line_delivery_trigger_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_preparation_order_lines', function (Blueprint $table): void {
            $table->dropIndex('erp_pb_line_delivery_trigger_idx');
            $table->dropColumn(['planned_start_at', 'delivery_lead_minutes', 'delivery_trigger_status', 'delivery_released_at', 'delivery_release_source', 'delivery_release_reason', 'delivery_released_by_legacy_id']);
        });
    }
};
