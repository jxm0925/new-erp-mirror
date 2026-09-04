<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_approval_form_templates', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('form_code', 100)->unique();
            $table->string('form_name', 160);
            $table->string('business_module', 80)->default('其他')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedInteger('current_version')->default(0);
            $table->text('description')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('erp_approval_form_versions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('form_template_id')->constrained('erp_approval_form_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('version_status', 30)->default('draft')->index();
            $table->json('schema_snapshot');
            $table->json('validation_snapshot')->nullable();
            $table->string('published_by', 100)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
            $table->unique(['form_template_id', 'version_no'], 'erp_approval_form_version_unique');
        });

        Schema::create('erp_approval_form_submissions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('submission_no', 80)->nullable()->unique();
            $table->foreignId('form_template_id')->constrained('erp_approval_form_templates')->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained('erp_approval_form_versions')->restrictOnDelete();
            $table->string('subject', 240);
            $table->string('submission_status', 30)->default('draft')->index();
            $table->json('form_data');
            $table->unsignedBigInteger('submitted_by')->nullable()->index();
            $table->string('submitted_by_name', 100)->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_approval_form_submissions');
        Schema::dropIfExists('erp_approval_form_versions');
        Schema::dropIfExists('erp_approval_form_templates');
    }
};

