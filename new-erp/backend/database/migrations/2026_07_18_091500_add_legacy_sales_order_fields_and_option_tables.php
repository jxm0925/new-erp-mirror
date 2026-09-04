<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_orders', 'pay_type')) $table->string('pay_type', 80)->nullable()->after('platform2');
            if (!Schema::hasColumn('erp_sales_orders', 'created_by_legacy_id')) $table->unsignedBigInteger('created_by_legacy_id')->nullable()->after('created_by')->index();
            if (!Schema::hasColumn('erp_sales_orders', 'sales_user_legacy_id')) $table->unsignedBigInteger('sales_user_legacy_id')->nullable()->after('created_by_legacy_id')->index();
            if (!Schema::hasColumn('erp_sales_orders', 'is_share')) $table->boolean('is_share')->default(false)->after('currency');
            if (!Schema::hasColumn('erp_sales_orders', 'share_user')) $table->text('share_user')->nullable()->after('is_share');
            if (!Schema::hasColumn('erp_sales_orders', 'carrier_id')) $table->string('carrier_id', 80)->nullable()->after('share_user');
            if (!Schema::hasColumn('erp_sales_orders', 'carrier_fee')) $table->decimal('carrier_fee', 14, 2)->default(0)->after('carrier_id');
        });

        Schema::create('erp_sales_order_pay_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('name', 160);
            $table->string('trade_type', 40)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sales_order_trade_platforms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('parent_legacy_id')->default(0)->index();
            $table->string('name', 160);
            $table->string('short_name', 160)->nullable();
            $table->string('trade_type', 40)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sales_order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('name', 160);
            $table->string('code', 80)->nullable();
            $table->string('trade_type', 40)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sales_order_share_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('username', 80)->nullable();
            $table->string('nickname', 120);
            $table->string('mobile', 40)->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->json('legacy_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales_order_share_users');
        Schema::dropIfExists('erp_sales_order_deliveries');
        Schema::dropIfExists('erp_sales_order_trade_platforms');
        Schema::dropIfExists('erp_sales_order_pay_types');

        Schema::table('erp_sales_orders', function (Blueprint $table) {
            foreach (['pay_type', 'created_by_legacy_id', 'sales_user_legacy_id', 'is_share', 'share_user', 'carrier_id', 'carrier_fee'] as $column) {
                if (Schema::hasColumn('erp_sales_orders', $column)) $table->dropColumn($column);
            }
        });
    }
};
