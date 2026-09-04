<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_boms', function (Blueprint $table) {
            $table->id();
            $table->string('bom_no', 80)->unique();
            $table->string('bom_name', 160);
            $table->foreignId('product_id')->nullable()->constrained('erp_products')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('erp_skus')->nullOnDelete();
            $table->foreignId('output_item_id')->constrained('erp_items')->restrictOnDelete();
            $table->string('bom_type', 30)->default('standard');
            $table->string('version', 40)->default('V1.0');
            $table->boolean('is_default')->default(false);
            $table->string('status', 30)->default('draft');
            $table->string('audit_status', 30)->default('pending');
            $table->date('effective_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->foreignId('source_product_id')->nullable()->constrained('erp_products')->nullOnDelete();
            $table->foreignId('source_sku_id')->nullable()->constrained('erp_skus')->nullOnDelete();
            $table->foreignId('source_standard_bom_id')->nullable()->constrained('erp_boms')->nullOnDelete();
            $table->text('custom_description')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['sku_id', 'status', 'is_default']);
            $table->index(['output_item_id', 'status', 'is_default']);
            $table->index(['bom_type', 'status', 'audit_status']);
        });

        Schema::create('erp_bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('erp_boms')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(10);
            $table->foreignId('component_item_id')->constrained('erp_items')->restrictOnDelete();
            $table->string('component_item_code', 80);
            $table->string('component_item_name', 160);
            $table->decimal('qty', 14, 4)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('loss_rate', 8, 4)->default(0);
            $table->decimal('fixed_qty', 14, 4)->default(0);
            $table->boolean('replaceable')->default(false);
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['bom_id', 'line_no']);
            $table->index('component_item_id');
        });

        Schema::create('erp_bom_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('erp_boms')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('message', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['bom_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_bom_logs');
        Schema::dropIfExists('erp_bom_items');
        Schema::dropIfExists('erp_boms');
    }
};
