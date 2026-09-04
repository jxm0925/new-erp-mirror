<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_suppliers', 'approval_status')) {
                $table->string('approval_status', 30)->default('approved')->after('level')->index();
            }
            if (! Schema::hasColumn('erp_suppliers', 'is_blacklisted')) {
                $table->boolean('is_blacklisted')->default(false)->after('approval_status')->index();
            }
            if (! Schema::hasColumn('erp_suppliers', 'cooperation_status')) {
                $table->string('cooperation_status', 30)->default('normal')->after('is_blacklisted')->index();
            }
            if (! Schema::hasColumn('erp_suppliers', 'purchase_restricted')) {
                $table->boolean('purchase_restricted')->default(false)->after('cooperation_status')->index();
            }
            if (! Schema::hasColumn('erp_suppliers', 'quality_status')) {
                $table->string('quality_status', 30)->default('normal')->after('purchase_restricted')->index();
            }
            if (! Schema::hasColumn('erp_suppliers', 'quality_frozen_until')) {
                $table->timestamp('quality_frozen_until')->nullable()->after('quality_status');
            }
        });

        if (! Schema::hasTable('erp_supplier_category_capabilities')) {
            Schema::create('erp_supplier_category_capabilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('erp_suppliers')->cascadeOnDelete();
                $table->foreignId('item_category_id')->constrained('erp_item_categories')->restrictOnDelete();
                $table->string('status', 20)->default('active')->index();
                $table->timestamp('effective_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['supplier_id', 'item_category_id'], 'erp_supplier_category_capability_unique');
            });
        }

        if (! Schema::hasTable('erp_supplier_item_relations')) {
            Schema::create('erp_supplier_item_relations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('erp_suppliers')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
                $table->string('capability_source', 30)->default('manual_confirmed')->index();
                $table->string('relation_status', 20)->default('active')->index();
                $table->boolean('is_default')->default(false)->index();
                $table->timestamp('effective_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->index(['supplier_id', 'item_id'], 'erp_supplier_item_relation_lookup');
            });
        }

        if (! Schema::hasTable('erp_supplier_item_relation_logs')) {
            Schema::create('erp_supplier_item_relation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('relation_id')->nullable()->constrained('erp_supplier_item_relations')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('erp_suppliers')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
                $table->string('action', 40);
                $table->json('old_snapshot')->nullable();
                $table->json('new_snapshot')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['supplier_id', 'item_id', 'created_at'], 'erp_supplier_item_relation_log_lookup');
            });
        }

        Schema::table('erp_item_supplier_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_item_supplier_prices', 'unit_id')) {
                $table->foreignId('unit_id')->nullable()->after('supplier_id')->constrained('erp_units')->nullOnDelete();
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'currency')) {
                $table->string('currency', 10)->default('CNY')->after('price');
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'tax_mode')) {
                $table->string('tax_mode', 30)->default('tax_included')->after('currency');
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'max_order_qty')) {
                $table->decimal('max_order_qty', 14, 4)->nullable()->after('min_order_qty');
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'valid_from')) {
                $table->date('valid_from')->nullable()->after('max_order_qty');
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'valid_until')) {
                $table->date('valid_until')->nullable()->after('valid_from')->index();
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'quote_status')) {
                $table->string('quote_status', 30)->default('approved')->after('valid_until')->index();
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'tier_prices')) {
                $table->json('tier_prices')->nullable()->after('quote_status');
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('tier_prices');
            }
            if (! Schema::hasColumn('erp_item_supplier_prices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });

        if (! Schema::hasTable('erp_supplier_quotation_histories')) {
            Schema::create('erp_supplier_quotation_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->nullable()->constrained('erp_item_supplier_prices')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('erp_suppliers')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
                $table->string('action', 30);
                $table->json('quotation_snapshot');
                $table->text('change_reason')->nullable();
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['supplier_id', 'item_id', 'created_at'], 'erp_supplier_quote_history_lookup');
            });
        }

        Schema::table('erp_purchase_price_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_purchase_price_histories', 'unit_id')) {
                $table->foreignId('unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            }
            if (! Schema::hasColumn('erp_purchase_price_histories', 'currency')) {
                $table->string('currency', 10)->default('CNY')->after('price');
            }
            if (! Schema::hasColumn('erp_purchase_price_histories', 'tax_mode')) {
                $table->string('tax_mode', 30)->default('tax_included')->after('currency');
            }
        });

        foreach (['erp_purchase_plan_supplier_splits', 'erp_purchase_order_items'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $indexPrefix = $tableName === 'erp_purchase_plan_supplier_splits' ? 'epss' : 'epoi';
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexPrefix) {
                if (! Schema::hasColumn($tableName, 'recommended_supplier_id_snapshot')) {
                    // A snapshot intentionally keeps the historical supplier id even if
                    // the master record is later archived, so it is indexed without an FK.
                    $table->unsignedBigInteger('recommended_supplier_id_snapshot')
                        ->nullable()
                        ->index("{$indexPrefix}_recommended_supplier_snapshot_idx");
                }
                if (! Schema::hasColumn($tableName, 'recommended_price_snapshot')) {
                    $table->decimal('recommended_price_snapshot', 14, 4)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'recommendation_basis_snapshot')) {
                    $table->string('recommendation_basis_snapshot', 60)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'recommendation_time')) {
                    $table->timestamp('recommendation_time')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'actual_supplier_id')) {
                    $table->unsignedBigInteger('actual_supplier_id')
                        ->nullable()
                        ->index("{$indexPrefix}_actual_supplier_idx");
                }
                if (! Schema::hasColumn($tableName, 'supplier_override_reason')) {
                    $table->string('supplier_override_reason', 60)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'supplier_override_remark')) {
                    $table->text('supplier_override_remark')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'supplier_override_by')) {
                    $table->unsignedBigInteger('supplier_override_by')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'supplier_override_at')) {
                    $table->timestamp('supplier_override_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('erp_document_number_rules')) {
            Schema::create('erp_document_number_rules', function (Blueprint $table) {
                $table->id();
                $table->string('document_type', 60)->unique();
                $table->string('name', 120);
                $table->string('prefix', 20);
                $table->string('date_format', 20)->default('Ymd');
                $table->unsignedTinyInteger('sequence_length')->default(5);
                $table->string('reset_cycle', 20)->default('daily');
                $table->boolean('allow_manual_edit')->default(false);
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('erp_document_number_reservations')) {
            Schema::create('erp_document_number_reservations', function (Blueprint $table) {
                $table->id();
                $table->string('document_type', 60);
                $table->uuid('creation_session_id');
                $table->string('document_no', 100);
                $table->uuid('reservation_token');
                $table->string('status', 20)->default('reserved')->index();
                $table->unsignedBigInteger('reserved_by_legacy_id')->nullable()->index();
                $table->string('reserved_page', 160)->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('consumed_at')->nullable();
                $table->string('business_type', 80)->nullable();
                $table->unsignedBigInteger('business_id')->nullable();
                $table->text('void_reason')->nullable();
                $table->timestamps();
                $table->unique(['document_type', 'creation_session_id'], 'erp_document_number_session_unique');
                $table->unique(['document_type', 'document_no'], 'erp_document_number_no_unique');
                $table->unique('reservation_token', 'erp_document_number_token_unique');
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_document_number_reservations');
        Schema::dropIfExists('erp_document_number_rules');
        Schema::dropIfExists('erp_supplier_quotation_histories');
        Schema::dropIfExists('erp_supplier_item_relation_logs');
        Schema::dropIfExists('erp_supplier_item_relations');
        Schema::dropIfExists('erp_supplier_category_capabilities');

        foreach (['erp_purchase_plan_supplier_splits', 'erp_purchase_order_items'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach ([
                    'supplier_override_at', 'supplier_override_by', 'supplier_override_remark',
                    'supplier_override_reason', 'actual_supplier_id', 'recommendation_time',
                    'recommendation_basis_snapshot', 'recommended_price_snapshot',
                    'recommended_supplier_id_snapshot',
                ] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

};

