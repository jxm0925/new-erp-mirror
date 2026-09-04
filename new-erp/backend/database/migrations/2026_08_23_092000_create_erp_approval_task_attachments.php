<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_approval_task_attachments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('approval_task_id')->constrained('erp_approval_tasks')->cascadeOnDelete();
            $table->foreignId('approval_task_node_id')->nullable()->constrained('erp_approval_task_nodes')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_disk', 40);
            $table->string('storage_path', 600);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->string('uploaded_by_name', 100)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('erp_approval_task_attachments'); }
};
