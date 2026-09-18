<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_settlement_batches', function (Blueprint $table): void {
            $table->foreignId('correction_of_batch_id')->nullable()->unique('cut_settlement_correction_of_uq')
                ->constrained('erp_cutting_settlement_batches')->restrictOnDelete();
        });
        Schema::create('erp_cutting_corrections', function (Blueprint $table): void {
            $table->id();
            $table->string('correction_no', 80)->unique();
            $table->foreignId('original_settlement_batch_id')->unique('cut_correction_original_uq')
                ->constrained('erp_cutting_settlement_batches')->restrictOnDelete();
            $table->foreignId('correction_settlement_batch_id')->unique('cut_correction_replacement_uq')
                ->constrained('erp_cutting_settlement_batches')->restrictOnDelete();
            $table->string('status', 24)->default('OPEN');
            $table->string('reason', 1000);
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamp('reversed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_cutting_corrections')->exists()) {
            throw new RuntimeException('已有正式下料更正事实，不允许通过结构回退删除业务历史。');
        }
        Schema::dropIfExists('erp_cutting_corrections');
        Schema::table('erp_cutting_settlement_batches', function (Blueprint $table): void {
            $table->dropUnique('cut_settlement_correction_of_uq');
            $table->dropConstrainedForeignId('correction_of_batch_id');
        });
    }
};
