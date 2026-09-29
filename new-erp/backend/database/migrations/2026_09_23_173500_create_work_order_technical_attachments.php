<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_work_order_technical_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->string('client_command_id', 120)->unique('wo_tech_attachment_command_unique');
            $table->char('request_hash', 64);
            $table->string('original_name');
            $table->string('storage_disk', 40);
            $table->string('storage_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('file_hash', 64);
            $table->unsignedBigInteger('uploaded_by_legacy_id');
            $table->string('uploaded_by', 100);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'confirmed_at'], 'wo_tech_attachment_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_work_order_technical_attachments');
    }
};
