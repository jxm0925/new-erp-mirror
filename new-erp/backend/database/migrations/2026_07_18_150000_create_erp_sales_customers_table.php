<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('erp_sales_customers')) {
            Schema::create('erp_sales_customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('legacy_customer_id')->nullable()->index();
                $table->string('customer_code', 80)->nullable()->unique();
                $table->string('customer_name', 160);
                $table->string('customer_short_name', 160)->nullable();
                $table->string('contact_name', 80)->nullable();
                $table->string('contact_phone', 80)->nullable();
                $table->string('province', 80)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('district', 80)->nullable();
                $table->string('full_address', 500)->nullable();
                $table->string('status', 30)->default('enabled');
                $table->json('legacy_snapshot')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_customers');
    }
};
