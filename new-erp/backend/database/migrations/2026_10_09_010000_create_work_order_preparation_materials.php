<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_work_order_material_preparations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('work_order_id')->unique()->constrained('erp_work_orders')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('work_order_version');
            $t->string('status', 24);
            $t->string('input_hash', 64);
            $t->json('input_snapshot');
            $t->unsignedBigInteger('prepared_by_legacy_id');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('erp_work_order_material_preparation_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('preparation_id');
            $t->foreign('preparation_id', 'erp_wompv_prep_fk')->references('id')->on('erp_work_order_material_preparations')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('input_hash', 64);
            $t->json('input_snapshot');
            $t->unsignedBigInteger('prepared_by_legacy_id');
            $t->timestamps();
            $t->unique(['preparation_id', 'version'], 'erp_wompv_version_uq');
        });
        Schema::create('erp_work_order_preparation_materials', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $t->unsignedBigInteger('preparation_id');
            $t->foreign('preparation_id', 'erp_wopm_prep_fk')->references('id')->on('erp_work_order_material_preparations')->restrictOnDelete();
            $t->unsignedInteger('preparation_version');
            $t->unsignedBigInteger('bom_item_id')->nullable();
            $t->string('identity_key', 64);
            $t->foreignId('component_item_id')->constrained('erp_items')->restrictOnDelete();
            $t->unsignedBigInteger('base_unit_id');
            $t->decimal('required_base_qty', 18, 8);
            $t->json('material_snapshot');
            $t->string('status', 24)->default('ACTIVE');
            $t->unsignedBigInteger('material_requirement_id')->nullable();
            $t->foreign('material_requirement_id', 'erp_wopm_formal_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['work_order_id', 'identity_key'], 'erp_wopm_identity_uq');
            $t->unique('material_requirement_id', 'erp_wopm_formal_uq');
        });
        Schema::table('erp_material_procurement_sources', function (Blueprint $t): void {
            $t->unsignedBigInteger('preparation_material_requirement_id')->nullable();
            $t->foreign('preparation_material_requirement_id', 'erp_procurement_prep_fk')->references('id')->on('erp_work_order_preparation_materials')->restrictOnDelete();
            $t->unsignedInteger('preparation_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('erp_material_procurement_sources', function (Blueprint $t): void {
            $t->dropForeign('erp_procurement_prep_fk');
            $t->dropColumn(['preparation_material_requirement_id', 'preparation_version']);
        });
        Schema::dropIfExists('erp_work_order_preparation_materials');
        Schema::dropIfExists('erp_work_order_material_preparation_versions');
        Schema::dropIfExists('erp_work_order_material_preparations');
    }
};
