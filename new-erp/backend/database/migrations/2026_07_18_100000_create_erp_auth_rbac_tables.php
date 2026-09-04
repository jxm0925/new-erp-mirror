<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_legacy_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_rbac_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->string('data_scope', 40)->default('self'); // all / department / self
            $table->boolean('enabled')->default(true);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_rbac_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('code', 120)->unique();
            $table->string('name', 120);
            $table->string('type', 30)->default('menu'); // menu / button / api
            $table->string('path', 200)->nullable();
            $table->string('component', 200)->nullable();
            $table->string('icon', 80)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_rbac_role_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id'], 'erp_role_perm_pk');
        });

        Schema::create('erp_rbac_user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_legacy_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_legacy_id', 'role_id'], 'erp_user_role_pk');
        });

        Schema::create('erp_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('parent_legacy_id')->default(0)->index();
            $table->string('name', 120);
            $table->string('status', 40)->default('normal');
            $table->integer('sort')->default(0);
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_department_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_legacy_id')->index();
            $table->unsignedBigInteger('user_legacy_id')->index();
            $table->boolean('is_principal')->default(false);
            $table->boolean('is_owner')->default(false);
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
            $table->unique(['department_legacy_id', 'user_legacy_id'], 'erp_dept_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_department_users');
        Schema::dropIfExists('erp_departments');
        Schema::dropIfExists('erp_rbac_user_roles');
        Schema::dropIfExists('erp_rbac_role_permissions');
        Schema::dropIfExists('erp_rbac_permissions');
        Schema::dropIfExists('erp_rbac_roles');
        Schema::dropIfExists('erp_auth_tokens');
    }
};
