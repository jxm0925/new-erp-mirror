<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->string('management_scope', 16)->default('factory')->after('item_type');
            $table->index(['management_scope', 'status'], 'erp_items_management_scope_status_idx');
        });
        Schema::table('erp_item_categories', function (Blueprint $table): void {
            $table->string('management_scope', 16)->default('factory')->after('category_type');
            $table->index(['management_scope', 'category_type', 'parent_id'], 'erp_categories_management_scope_tree_idx');
        });
        Schema::table('erp_import_batches', function (Blueprint $table): void {
            // No default context keeps old mixed files readable; each explicit
            // row or its existing office-consumable type establishes its scope.
            $table->string('management_scope', 16)->nullable()->after('import_type');
        });

        // Only the already explicit business type identifies legacy office
        // Items. Preserve every original category reference, stock and policy;
        // names cannot establish either scope or a replacement category.
        DB::table('erp_items')->where('item_type', 'office_consumable')->update(['management_scope' => 'office']);
        DB::statement("ALTER TABLE erp_items ADD CONSTRAINT erp_items_management_scope_ck CHECK (management_scope IN ('factory','office'))");
        DB::statement("ALTER TABLE erp_item_categories ADD CONSTRAINT erp_categories_management_scope_ck CHECK (management_scope IN ('factory','office'))");
        DB::statement("ALTER TABLE erp_import_batches ADD CONSTRAINT erp_import_management_scope_ck CHECK (management_scope IS NULL OR management_scope IN ('factory','office'))");
    }

    public function down(): void
    {
        if (DB::table('erp_items')->where('management_scope', 'office')->exists()
            || DB::table('erp_item_categories')->where('management_scope', 'office')->exists()
            || DB::table('erp_import_batches')->whereNotNull('management_scope')->exists()) {
            throw new RuntimeException('已有明确的办公用品或导入管理范围，不能回退删除归属事实。');
        }
        DB::statement('ALTER TABLE erp_import_batches DROP CHECK erp_import_management_scope_ck');
        DB::statement('ALTER TABLE erp_item_categories DROP CHECK erp_categories_management_scope_ck');
        DB::statement('ALTER TABLE erp_items DROP CHECK erp_items_management_scope_ck');
        Schema::table('erp_import_batches', fn (Blueprint $table) => $table->dropColumn('management_scope'));
        Schema::table('erp_item_categories', function (Blueprint $table): void {
            $table->dropIndex('erp_categories_management_scope_tree_idx');
            $table->dropColumn('management_scope');
        });
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropIndex('erp_items_management_scope_status_idx');
            $table->dropColumn('management_scope');
        });
    }
};
