<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These tables contain confirmed personal ratios and audit facts only.
        // Production, partial shipments and share confirmation never post money.
        Schema::create('erp_production_performance_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedBigInteger('owner_legacy_id');
            $table->unsignedInteger('version_no');
            $table->string('active_scope_key', 100)->nullable()->unique();
            $table->string('status', 20)->default('confirmed');
            $table->decimal('credited_share_ratio', 12, 8);
            $table->decimal('noncredited_share_ratio', 12, 8);
            $table->boolean('noncredited_confirmed')->default(false);
            $table->string('noncredited_reason', 500)->nullable();
            $table->json('scope_snapshot');
            $table->unsignedBigInteger('confirmed_by_legacy_id');
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id', 'version_no'], 'prod_perf_scope_version_uq');
            $table->index(['owner_legacy_id', 'confirmed_at'], 'prod_perf_owner_time_idx');
        });
        Schema::create('erp_production_performance_shares', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('employee_legacy_id');
            $table->string('employee_name_snapshot', 160);
            $table->boolean('eligible');
            $table->decimal('share_ratio', 12, 8);
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'employee_legacy_id'], 'prod_perf_assignment_employee_uq');
            $table->foreign('assignment_id', 'prod_perf_share_assignment_fk')->references('id')->on('erp_production_performance_assignments')->restrictOnDelete();
        });
        Schema::create('erp_production_performance_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_command_id', 120)->unique();
            $table->string('request_hash', 64);
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedBigInteger('initiated_by_legacy_id');
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->timestamps();
            $table->foreign('assignment_id', 'prod_perf_command_assignment_fk')->references('id')->on('erp_production_performance_assignments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_performance_commands');
        Schema::dropIfExists('erp_production_performance_shares');
        Schema::dropIfExists('erp_production_performance_assignments');
    }
};
