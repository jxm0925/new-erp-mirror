<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_warehouse_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_command_id', 120)->unique();
            $table->string('action', 60);
            $table->unsignedBigInteger('aggregate_id');
            $table->unsignedBigInteger('actor_legacy_id');
            $table->char('request_hash', 64);
            $table->json('request_payload');
            $table->string('status', 20);
            $table->json('response')->nullable();
            $table->timestamps();
            $table->index(['actor_legacy_id', 'action', 'aggregate_id'], 'erp_warehouse_command_actor_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('erp_warehouse_commands'); }
};
