<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_requests', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('deleted_by', 80)->nullable();
            $table->unsignedBigInteger('deleted_by_legacy_id')->nullable();
            $table->index(['deleted_at', 'updated_at'], 'purchase_requests_deleted_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('erp_purchase_requests', function (Blueprint $table) {
            $table->dropIndex('purchase_requests_deleted_updated_index');
            $table->dropColumn(['deleted_at', 'deleted_by', 'deleted_by_legacy_id']);
        });
    }
};
