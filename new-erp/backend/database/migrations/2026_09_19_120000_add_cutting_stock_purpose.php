<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('erp_cutting_orders', 'purpose')) {
            DB::statement("ALTER TABLE erp_cutting_orders ADD COLUMN purpose VARCHAR(16) NOT NULL DEFAULT 'FORMAL' AFTER status");
        }

        DB::statement('ALTER TABLE erp_cutting_allowed_outputs DROP FOREIGN KEY erp_cutting_allowed_outputs_plan_id_foreign');
        DB::statement('ALTER TABLE erp_cutting_allowed_outputs DROP FOREIGN KEY erp_cutting_allowed_outputs_stage_id_foreign');
        DB::statement('ALTER TABLE erp_cutting_allowed_outputs MODIFY plan_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE erp_cutting_allowed_outputs MODIFY stage_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE erp_cutting_allowed_outputs ADD CONSTRAINT erp_cutting_allowed_outputs_plan_id_foreign FOREIGN KEY (plan_id) REFERENCES erp_cutting_plan_allocations (id)');
        DB::statement('ALTER TABLE erp_cutting_allowed_outputs ADD CONSTRAINT erp_cutting_allowed_outputs_stage_id_foreign FOREIGN KEY (stage_id) REFERENCES erp_production_routing_operations (id)');
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_cutting_orders', 'purpose')) {
            DB::statement('ALTER TABLE erp_cutting_orders DROP COLUMN purpose');
        }
    }
};
