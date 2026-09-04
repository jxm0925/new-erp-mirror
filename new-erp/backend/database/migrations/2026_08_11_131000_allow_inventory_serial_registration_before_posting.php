<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_inventory_serials', function (Blueprint $table): void {
            $table->foreignId('inventory_balance_id')->nullable()->change();
            $table->timestamp('registered_at')->nullable()->after('received_at');
            $table->timestamp('posted_at')->nullable()->after('registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('erp_inventory_serials', function (Blueprint $table): void {
            $table->dropColumn(['registered_at', 'posted_at']);
            $table->foreignId('inventory_balance_id')->nullable(false)->change();
        });
    }
};
