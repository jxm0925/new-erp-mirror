<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_item_material_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 20)->default('draft')->index(); // draft / active / historical
            $table->string('template_code', 60)->nullable();
            $table->boolean('is_stock_managed')->default(true);
            $table->string('inventory_management_mode', 40)->default('standard');
            $table->boolean('requires_custodian')->default(false);
            $table->boolean('is_returnable')->default(false);
            $table->boolean('requires_capitalization')->default(false);
            $table->string('serial_tracking_mode', 20)->default('none');
            $table->string('post_purchase_action', 50)->default('inventory_receipt');
            $table->string('consumption_confirmation_mode', 40)->default('none');
            $table->string('future_route', 50)->default('inventory');
            $table->string('future_bearer_type', 40)->default('company');
            $table->json('parameter_snapshot');
            $table->string('change_reason', 200)->nullable();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by_legacy_id')->nullable()->index();
            $table->unsignedBigInteger('enabled_by_legacy_id')->nullable()->index();
            $table->timestamp('effective_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['item_id', 'version_no'], 'erp_item_material_policy_version_unique');
            $table->index(['item_id', 'status', 'effective_at'], 'erp_item_material_policy_effective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_item_material_policies');
    }
};
