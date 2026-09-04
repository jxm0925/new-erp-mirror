<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('target_routing_operation_id')->nullable()->after('target_operation_id');
            $table->index(['production_routing_id', 'target_routing_operation_id'], 'erp_work_order_route_node_idx');
            $table->foreign('target_routing_operation_id', 'erp_work_order_route_node_fk')->references('id')->on('erp_production_routing_operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->dropForeign('erp_work_order_route_node_fk');
            $table->dropIndex('erp_work_order_route_node_idx');
            $table->dropColumn('target_routing_operation_id');
        });
    }
};
