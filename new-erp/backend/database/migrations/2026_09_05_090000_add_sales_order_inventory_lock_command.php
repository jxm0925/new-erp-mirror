<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->unsignedInteger('business_version')->default(1)->after('change_status');
            $table->string('inventory_lock_status', 30)->default('pending')->after('business_version');
            $table->timestamp('inventory_locked_at')->nullable()->after('inventory_lock_status');
            $table->unsignedBigInteger('inventory_locked_by_legacy_id')->nullable()->after('inventory_locked_at');
        });
        Schema::table('erp_sales_order_lines', function (Blueprint $table): void {
            $table->decimal('production_replenished_qty', 18, 8)->default(0)->after('production_required_qty');
        });
        Schema::table('erp_inventory_reservations', function (Blueprint $table): void {
            $table->unsignedBigInteger('sales_order_fulfillment_id')->nullable()->after('source_order_line_id');
            $table->foreign('sales_order_fulfillment_id', 'erp_inv_res_sales_fulfillment_fk')
                ->references('id')->on('erp_sales_order_fulfillments')->nullOnDelete();
            $table->index(['sales_order_fulfillment_id', 'reservation_status'], 'erp_inv_res_fulfillment_status_idx');
        });
        Schema::table('erp_production_output_warehouse_postings', function (Blueprint $table): void {
            $table->unsignedBigInteger('sales_order_reservation_id')->nullable()->after('inventory_transaction_id');
            $table->foreign('sales_order_reservation_id', 'erp_prod_output_post_sales_res_fk')
                ->references('id')->on('erp_inventory_reservations')->nullOnDelete();
        });

        Schema::create('erp_sales_order_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_command_id', 120)->unique();
            $table->string('command_type', 60);
            $table->unsignedBigInteger('sales_order_id');
            $table->string('request_hash', 64);
            $table->json('response_snapshot')->nullable();
            $table->string('status', 30)->default('processing');
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('initiated_by_legacy_id')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
            $table->timestamps();

            $table->foreign('sales_order_id', 'erp_so_command_order_fk')
                ->references('id')->on('erp_sales_orders')->restrictOnDelete();
            $table->index(['sales_order_id', 'command_type'], 'erp_so_command_order_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_order_commands');
        if (Schema::hasColumn('erp_production_output_warehouse_postings', 'sales_order_reservation_id')) {
            Schema::table('erp_production_output_warehouse_postings', function (Blueprint $table): void {
                $table->dropForeign('erp_prod_output_post_sales_res_fk');
                $table->dropColumn('sales_order_reservation_id');
            });
        }
        if (Schema::hasColumn('erp_inventory_reservations', 'sales_order_fulfillment_id')) {
            Schema::table('erp_inventory_reservations', function (Blueprint $table): void {
                $table->dropForeign('erp_inv_res_sales_fulfillment_fk');
                $table->dropIndex('erp_inv_res_fulfillment_status_idx');
                $table->dropColumn('sales_order_fulfillment_id');
            });
        }
        if (Schema::hasColumn('erp_sales_order_lines', 'production_replenished_qty')) {
            Schema::table('erp_sales_order_lines', function (Blueprint $table): void {
                $table->dropColumn('production_replenished_qty');
            });
        }
        Schema::table('erp_sales_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'inventory_locked_by_legacy_id', 'inventory_locked_at',
                'inventory_lock_status', 'business_version',
            ]);
        });
    }
};
