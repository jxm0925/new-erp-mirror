<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_material_physical_disposals', function (Blueprint $table): void {
            $table->id();
            $table->string('disposal_no', 80)->unique();
            $table->foreignId('physical_material_id')->unique('material_physical_disposal_uq')
                ->constrained('erp_material_physicals')->restrictOnDelete();
            $table->foreignId('source_holding_id')->constrained('erp_material_holdings')->restrictOnDelete();
            $table->foreignId('disposal_holding_id')->nullable()->unique()->constrained('erp_material_holdings')->restrictOnDelete();
            $table->decimal('quantity', 18, 8);
            $table->decimal('total_cost', 18, 4);
            $table->string('reason', 1000);
            $table->string('status', 24)->default('POSTED');
            $table->unsignedBigInteger('disposed_by_legacy_id');
            $table->timestamp('disposed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_material_physical_disposals')->exists()) {
            throw new RuntimeException('已有正式余料处置事实，不允许通过结构回退删除业务历史。');
        }
        Schema::dropIfExists('erp_material_physical_disposals');
    }
};
