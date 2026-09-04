<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('erp_operation_logs')) {
            Schema::create('erp_operation_logs', function (Blueprint $table) {
                $table->id();
                $table->string('module', 80)->index();
                $table->string('action', 40)->index();
                $table->string('target_type', 100);
                $table->unsignedBigInteger('target_id')->nullable()->index();
                $table->json('old_snapshot')->nullable();
                $table->json('new_snapshot')->nullable();
                $table->string('reason', 200)->nullable();
                $table->unsignedBigInteger('operator_id')->nullable()->index();
                $table->string('operator_name', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_operation_logs');
    }
};

