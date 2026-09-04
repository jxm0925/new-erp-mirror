<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_sales_order_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('change_no', 80)->unique();
            $table->unsignedBigInteger('sales_order_id');
            $table->string('change_type', 40)->default('commercial_quantity');
            $table->string('change_status', 30)->default('applied');
            $table->text('reason');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->string('operator', 80)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('sales_order_id', 'erp_soc_order_fk')
                ->references('id')->on('erp_sales_orders')->cascadeOnDelete();
            $table->index(['sales_order_id', 'change_status'], 'erp_soc_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_order_changes');
    }
};
