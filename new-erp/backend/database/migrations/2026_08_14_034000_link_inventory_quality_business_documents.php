<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('erp_inventory_quality_events', 'business_doc_id')) {
            Schema::table('erp_inventory_quality_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('business_doc_id')->nullable()->after('business_doc_type')->index();
            });
        }
        if (!Schema::hasColumn('erp_purchase_return_items', 'source_inventory_quality_event_id')) {
            Schema::table('erp_purchase_return_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_inventory_quality_event_id')->nullable()
                    ->after('source_defect_handling_id');
            });
        }
        $supportsAlterConstraints = DB::getDriverName() !== 'sqlite';
        if ($supportsAlterConstraints) {
            DB::statement('ALTER TABLE erp_purchase_return_items ADD CONSTRAINT pri_quality_event_fk FOREIGN KEY (source_inventory_quality_event_id) REFERENCES erp_inventory_quality_events(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE erp_purchase_exchange_orders MODIFY defect_handling_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('erp_purchase_exchange_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('defect_handling_id')->nullable()->change();
            });
        }
        Schema::table('erp_purchase_exchange_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('inventory_quality_event_id')->nullable()->unique('peo_quality_event_uq')
                ->after('defect_handling_id');
        });
        if ($supportsAlterConstraints) {
            DB::statement('ALTER TABLE erp_purchase_exchange_orders ADD CONSTRAINT peo_quality_event_fk FOREIGN KEY (inventory_quality_event_id) REFERENCES erp_inventory_quality_events(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        Schema::table('erp_purchase_exchange_orders', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') $table->dropForeign('peo_quality_event_fk');
            $table->dropUnique('peo_quality_event_uq');
            $table->dropColumn('inventory_quality_event_id');
        });
        Schema::table('erp_purchase_return_items', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') $table->dropForeign('pri_quality_event_fk');
            $table->dropColumn('source_inventory_quality_event_id');
        });
        Schema::table('erp_inventory_quality_events', function (Blueprint $table): void {
            $table->dropColumn('business_doc_id');
        });
    }
};
