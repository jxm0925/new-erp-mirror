<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_orders', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('published_at');
            $table->unsignedBigInteger('closed_by_legacy_id')->nullable()->after('closed_at');
            $table->string('close_reason', 1000)->nullable()->after('closed_by_legacy_id');
            $table->timestamp('cancelled_at')->nullable()->after('close_reason');
            $table->unsignedBigInteger('cancelled_by_legacy_id')->nullable()->after('cancelled_at');
            $table->string('cancel_reason', 1000)->nullable()->after('cancelled_by_legacy_id');
        });

        Schema::table('erp_cutting_plan_allocations', function (Blueprint $table): void {
            // Keep the published plan immutable. Closing freezes the quantity that
            // actually became an effective output allocation, so a shortfall no
            // longer occupies the formal demand after this order is finished.
            $table->decimal('closed_planned_qty', 18, 8)->nullable()->after('planned_qty');
        });
    }

    public function down(): void
    {
        Schema::table('erp_cutting_plan_allocations', function (Blueprint $table): void {
            $table->dropColumn('closed_planned_qty');
        });
        Schema::table('erp_cutting_orders', function (Blueprint $table): void {
            $table->dropColumn(['closed_at', 'closed_by_legacy_id', 'close_reason', 'cancelled_at',
                'cancelled_by_legacy_id', 'cancel_reason']);
        });
    }
};
