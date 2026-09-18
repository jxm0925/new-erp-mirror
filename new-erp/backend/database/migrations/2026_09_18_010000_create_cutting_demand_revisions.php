<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('erp_cutting_demand_revisions')) {
            return;
        }

        Schema::create('erp_cutting_demand_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demand_id')->constrained('erp_cutting_demands')->restrictOnDelete();
            $table->string('source_change_id', 120);
            $table->unsignedInteger('source_business_version_before');
            $table->unsignedInteger('source_business_version_after');
            $table->decimal('required_base_qty_before', 18, 8);
            $table->decimal('required_base_qty_after', 18, 8);
            $table->decimal('quantity_delta', 18, 8);
            $table->decimal('cut_length_mm_before', 18, 2)->nullable();
            $table->decimal('cut_length_mm_after', 18, 2)->nullable();
            $table->decimal('required_piece_qty_before', 18, 8)->nullable();
            $table->decimal('required_piece_qty_after', 18, 8)->nullable();
            $table->string('demand_status_before', 24);
            $table->string('demand_status_after', 24);
            $table->text('reason');
            $table->json('source_snapshot_before');
            $table->json('source_snapshot_after');
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamps();
            $table->unique(['demand_id', 'source_change_id'], 'cut_demand_revision_source_uq');
            $table->index(['demand_id', 'id'], 'cut_demand_revision_history_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('erp_cutting_demand_revisions') && DB::table('erp_cutting_demand_revisions')->exists()) {
            throw new RuntimeException('已有正式下料需求变更事实，不允许回退删除历史。');
        }
        Schema::dropIfExists('erp_cutting_demand_revisions');
    }
};
