<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_orders', fn (Blueprint $t) => $t->unsignedInteger('purchase_link_version')->default(1));
        Schema::create('erp_sales_order_purchase_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sales_order_id')->constrained('erp_sales_orders')->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('erp_purchase_orders')->restrictOnDelete();
            $t->foreignId('purchase_order_item_id')->constrained('erp_purchase_order_items')->restrictOnDelete();
            $t->decimal('purchase_qty', 18, 8);
            $t->decimal('contract_amount', 18, 4)->nullable();
            $t->string('currency', 20)->nullable();
            $t->json('source_snapshot');
            $t->string('status', 20)->default('active');
            $t->string('idempotency_key', 100)->unique('so_purchase_link_request_uq');
            $t->string('request_hash', 64);
            $t->string('reason', 500);
            $t->string('created_by', 80);
            $t->string('reversed_by', 80)->nullable();
            $t->string('reverse_reason', 500)->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->timestamps();
            $t->index(['sales_order_id', 'status'], 'so_purchase_link_status_idx');
            $t->index(['purchase_order_item_id', 'status'], 'so_purchase_link_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_order_purchase_links');
        Schema::table('erp_sales_orders', fn (Blueprint $t) => $t->dropColumn('purchase_link_version'));
    }
};
