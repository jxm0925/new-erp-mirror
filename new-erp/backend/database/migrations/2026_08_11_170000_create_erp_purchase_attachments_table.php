<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('erp_purchase_attachments')) return;

        Schema::create('erp_purchase_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 30)->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('draft_token', 120)->nullable()->index();
            $table->string('attachment_type', 60)->default('other')->index();
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('storage_disk', 40)->default('oss');
            $table->string('storage_path', 500);
            $table->string('url', 500)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_hash', 128)->nullable()->index();
            $table->unsignedBigInteger('uploaded_by_legacy_id')->nullable()->index();
            $table->string('uploaded_by', 80)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->string('deleted_by', 80)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['document_type', 'document_id', 'status'], 'erp_purchase_att_document_idx');
            $table->index(['draft_token', 'document_type', 'status'], 'erp_purchase_att_draft_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_attachments');
    }
};
