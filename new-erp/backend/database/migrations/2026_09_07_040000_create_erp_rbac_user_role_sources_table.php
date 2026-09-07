<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_rbac_user_role_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_legacy_id')->index();
            $table->unsignedBigInteger('role_id')->index();
            $table->string('assignment_source', 20);
            $table->timestamps();
            $table->unique(['user_legacy_id', 'role_id', 'assignment_source'], 'erp_user_role_source_unique');
            $table->foreign('role_id')->references('id')->on('erp_rbac_roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_rbac_user_role_sources');
    }
};
