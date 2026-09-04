<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_legacy_admin_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('username', 80)->nullable();
            $table->string('nickname', 120)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('status', 40)->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_sales')->default(false)->index();
            $table->json('department_ids')->nullable();
            $table->json('department_names')->nullable();
            $table->json('auth_group_ids')->nullable();
            $table->json('auth_group_names')->nullable();
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_legacy_admin_users');
    }
};
