<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equipment identity is deliberately separate from ProductionSerial. A
        // product SN traces one physical product; an equipment/plate number is an
        // optional enterprise-management identity with a different lifecycle.
        if (! Schema::hasColumn('erp_items', 'equipment_identity_requirement')) {
            Schema::table('erp_items', function (Blueprint $table): void {
                $table->string('equipment_identity_requirement', 30)->default('not_applicable')
                    ->after('serial_generation_routing_operation_id');
                $table->index('equipment_identity_requirement', 'erp_items_equipment_identity_req_idx');
            });
        }

        if (! Schema::hasTable('erp_production_unit_equipment_identities')) {
            Schema::create('erp_production_unit_equipment_identities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('production_unit_id');
                $table->string('status', 30)->default('NOT_APPLICABLE');
                $table->string('equipment_no', 120)->nullable();
                $table->string('source_type', 40)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('bound_by_legacy_id')->nullable();
                $table->timestamp('bound_at')->nullable();
                $table->text('remark')->nullable();
                $table->unsignedInteger('business_version')->default(1);
                $table->timestamps();

                $table->unique('production_unit_id', 'erp_pu_equipment_identity_unit_uq');
                $table->unique('equipment_no', 'erp_pu_equipment_identity_no_uq');
                $table->index(['status', 'equipment_no'], 'erp_pu_equipment_identity_status_no_idx');
                $table->foreign('production_unit_id', 'erp_pu_equipment_identity_unit_fk')
                    ->references('id')->on('erp_production_units')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_unit_equipment_identities');
        if (Schema::hasColumn('erp_items', 'equipment_identity_requirement')) {
            Schema::table('erp_items', function (Blueprint $table): void {
                $table->dropIndex('erp_items_equipment_identity_req_idx');
                $table->dropColumn('equipment_identity_requirement');
            });
        }
    }
};
