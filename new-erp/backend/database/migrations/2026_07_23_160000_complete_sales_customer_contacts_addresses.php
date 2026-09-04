<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_customers', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_customers', 'customer_type')) $table->string('customer_type', 40)->nullable();
            if (!Schema::hasColumn('erp_sales_customers', 'owner_legacy_id')) $table->unsignedBigInteger('owner_legacy_id')->nullable()->index();
            if (!Schema::hasColumn('erp_sales_customers', 'owner_name')) $table->string('owner_name', 80)->nullable();
            if (!Schema::hasColumn('erp_sales_customers', 'remark')) $table->text('remark')->nullable();
        });

        if (!Schema::hasTable('erp_sales_customer_contacts')) {
            Schema::create('erp_sales_customer_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('erp_sales_customers')->cascadeOnDelete();
                $table->string('contact_name', 80);
                $table->string('mobile', 40)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('email', 160)->nullable();
                $table->string('position', 80)->nullable();
                $table->boolean('is_default')->default(false);
                $table->string('status', 30)->default('enabled');
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index(['customer_id', 'status']);
            });
        }

        if (!Schema::hasTable('erp_sales_customer_addresses')) {
            Schema::create('erp_sales_customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('erp_sales_customers')->cascadeOnDelete();
                $table->string('receiver_name', 80);
                $table->string('receiver_phone', 40)->nullable();
                $table->string('province', 80)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('district', 80)->nullable();
                $table->string('detail_address', 500);
                $table->string('full_address', 700);
                $table->boolean('is_default')->default(false);
                $table->string('status', 30)->default('enabled');
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index(['customer_id', 'status']);
            });
        }

        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_orders', 'customer_contact_id')) $table->unsignedBigInteger('customer_contact_id')->nullable()->index()->after('customer_id');
            if (!Schema::hasColumn('erp_sales_orders', 'customer_address_id')) $table->unsignedBigInteger('customer_address_id')->nullable()->index()->after('customer_contact_id');
            if (!Schema::hasColumn('erp_sales_orders', 'customer_name_snapshot')) $table->string('customer_name_snapshot', 160)->nullable()->after('customer_name');
            if (!Schema::hasColumn('erp_sales_orders', 'contact_name_snapshot')) $table->string('contact_name_snapshot', 80)->nullable()->after('contact_name');
            if (!Schema::hasColumn('erp_sales_orders', 'contact_phone_snapshot')) $table->string('contact_phone_snapshot', 80)->nullable()->after('contact_phone');
            if (!Schema::hasColumn('erp_sales_orders', 'shipping_address_snapshot')) $table->json('shipping_address_snapshot')->nullable()->after('shipping_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_customer_addresses');
        Schema::dropIfExists('erp_sales_customer_contacts');
    }
};
