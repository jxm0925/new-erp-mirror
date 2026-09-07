<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sso_ticket_consumptions', function (Blueprint $table) {
            $table->id();
            $table->string('issuer', 80);
            $table->string('nonce', 128);
            $table->unsignedBigInteger('user_legacy_id')->index();
            $table->timestamp('ticket_issued_at');
            $table->timestamp('ticket_expires_at');
            $table->timestamp('consumed_at');
            $table->string('request_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['issuer', 'nonce'], 'erp_sso_ticket_issuer_nonce_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sso_ticket_consumptions');
    }
};
