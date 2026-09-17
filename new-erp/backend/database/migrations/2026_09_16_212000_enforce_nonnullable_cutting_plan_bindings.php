<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One ALTER preserves FK enforcement without a separately committed gap.
        // Existing unbound plans fail deployment; never guess or backfill identity.
        $this->bindings(true);
    }

    public function down(): void
    {
        $this->bindings(false);
    }

    private function bindings(bool $required): void
    {
        $nullability = $required ? 'NOT NULL' : 'NULL';
        // 8.0.12 cannot reuse a dropped FK name within the same ALTER.
        $original = ['cut_plan_demand_source_fk','erp_cutting_plan_allocations_demand_id_foreign','cut_plan_target_fk','cut_plan_input_requirement_fk'];
        $enforced = ['cut_plan_demand_source_nn_fk','cut_plan_demand_nn_fk','cut_plan_target_nn_fk','cut_plan_input_nn_fk'];
        [$sourceDrop,$demandDrop,$targetDrop,$inputDrop] = $required ? $original : $enforced;
        [$sourceAdd,$demandAdd,$targetAdd,$inputAdd] = $required ? $enforced : $original;
        DB::statement("ALTER TABLE erp_cutting_plan_allocations
            DROP FOREIGN KEY {$sourceDrop},
            DROP FOREIGN KEY {$demandDrop},
            DROP FOREIGN KEY {$targetDrop},
            DROP FOREIGN KEY {$inputDrop},
            MODIFY demand_id BIGINT UNSIGNED {$nullability},
            MODIFY target_material_requirement_id BIGINT UNSIGNED {$nullability},
            MODIFY input_material_requirement_id BIGINT UNSIGNED {$nullability},
            ADD CONSTRAINT {$demandAdd} FOREIGN KEY (demand_id) REFERENCES erp_cutting_demands (id),
            ADD CONSTRAINT {$targetAdd} FOREIGN KEY (target_material_requirement_id) REFERENCES erp_production_target_material_requirements (id),
            ADD CONSTRAINT {$inputAdd} FOREIGN KEY (input_material_requirement_id) REFERENCES erp_work_order_material_requirements (id),
            ADD CONSTRAINT {$sourceAdd} FOREIGN KEY (demand_id, target_material_requirement_id) REFERENCES erp_cutting_demands (id, source_requirement_id)");
    }
};
