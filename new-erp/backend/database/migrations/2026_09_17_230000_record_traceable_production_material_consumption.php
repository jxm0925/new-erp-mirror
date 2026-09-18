<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('erp_production_output_records', 'material_total_cost')) Schema::table('erp_production_output_records', function (Blueprint $t): void {
            $t->foreignId('material_lot_id')->nullable()->constrained('erp_material_lots')->restrictOnDelete();
            $t->foreignId('material_holding_id')->nullable()->constrained('erp_material_holdings')->restrictOnDelete();
            $t->decimal('material_total_cost', 18, 4)->nullable();
            $t->decimal('material_loss_cost', 18, 4)->nullable();
        });
        if (! Schema::hasTable('erp_production_material_consumptions')) Schema::create('erp_production_material_consumptions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('output_record_id');
            $t->unsignedBigInteger('target_material_requirement_id');
            $t->unsignedBigInteger('source_holding_id');
            $t->unsignedBigInteger('source_material_lot_id');
            $t->decimal('quantity', 18, 8); $t->decimal('total_cost', 18, 4);
            $t->unsignedBigInteger('operator_legacy_id'); $t->timestamp('occurred_at'); $t->timestamps();
            $t->unique(['output_record_id', 'source_holding_id'], 'prod_material_consumption_source_uq');
        });
        // MySQL DDL commits implicitly; resume an interrupted table creation without dropping any facts.
        $keys = Schema::getForeignKeys('erp_production_material_consumptions');
        foreach ([['output_record_id','erp_production_output_records','prod_mat_cons_output_fk'],
            ['target_material_requirement_id','erp_production_target_material_requirements','prod_mat_cons_requirement_fk'],
            ['source_holding_id','erp_material_holdings','prod_mat_cons_holding_fk'],
            ['source_material_lot_id','erp_material_lots','prod_mat_cons_lot_fk']] as [$column,$table,$name]) {
            if (! collect($keys)->contains(fn ($key) => $key['columns'] === [$column])) {
                Schema::table('erp_production_material_consumptions', fn (Blueprint $t) => $t->foreign($column, $name)->references('id')->on($table)->restrictOnDelete());
            }
        }
    }

    public function down(): void
    {
        if (DB::table('erp_production_material_consumptions')->exists()) {
            throw new RuntimeException('已有正式生产材料消耗事实，禁止删除成本及批次追溯。');
        }
        Schema::dropIfExists('erp_production_material_consumptions');
        Schema::table('erp_production_output_records', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('material_lot_id');
            $t->dropConstrainedForeignId('material_holding_id');
            $t->dropColumn(['material_total_cost', 'material_loss_cost']);
        });
    }
};
