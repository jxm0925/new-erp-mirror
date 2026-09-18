<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{FinanceInvoice, FinanceInvoiceAllocation, Item, ProductionOperation, ProductionRouting, PurchaseOrder, PurchaseOrderItem, PurchasePlan, PurchasePlanItem, PurchasePlanSupplierSplit, PurchaseRequest, PurchaseRequestItem, Supplier, SupplierItemRelation, Unit};
use App\Models\Erp\{ApprovalFlowTemplate, Bom, FinanceAccount, FinanceAccountTransfer, FinanceAttachment, FinanceCashDocument, SalesCustomer, SalesOrder};
use App\Models\Erp\{DocumentNumberRule, ImportBatch, InventoryAdjustment, ProductionLaborAllocationRule, PurchaseLog, SalesOrderAttachment};
use App\Services\Erp\{AuthContextService, FinanceDraftDeletionApplicationService, MasterDataApplicationService, ProductionMasterDataService, PurchaseDraftDeletionApplicationService, RbacBootstrapService};
use App\Services\Erp\{ApprovalFlowApplicationService, SalesCustomerDeletionApplicationService};
use App\Services\Erp\{DocumentNumberRuleService, DocumentNumberService, InventoryAdjustmentApplicationService, ProductionLaborAllocationRuleService, SalesOrderDraftService};
use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeletionLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plan_draft_deletion_returns_request_quantity_and_preserves_deletion_audit(): void
    {
        [$request, $requestItem, $plan] = $this->purchasePlan();
        app(PurchaseDraftDeletionApplicationService::class)->deletePlan($plan->id, '删除核查员');
        $this->assertDatabaseMissing('erp_purchase_plans', ['id' => $plan->id]);
        $this->assertDatabaseMissing('erp_purchase_plan_items', ['plan_id' => $plan->id]);
        $this->assertSame(0.0, (float) $requestItem->fresh()->converted_qty);
        $this->assertSame(10.0, (float) $requestItem->fresh()->remaining_qty);
        $this->assertSame('confirmed', $request->fresh()->request_status);
        $this->assertDatabaseHas('erp_purchase_logs', ['target_type' => 'purchase_plan', 'target_id' => $plan->id, 'action' => 'delete_draft', 'operator' => '删除核查员']);
    }

    public function test_purchase_order_draft_deletion_returns_supplier_split_quantity(): void
    {
        [, , $plan, $planItem, $item] = $this->purchasePlan();
        $supplier = $this->supplier();
        $order = PurchaseOrder::create(['purchase_order_no' => 'DEL-PO', 'supplier_id' => $supplier->id, 'plan_id' => $plan->id, 'purchase_status' => 'draft', 'audit_status' => 'pending']);
        $split = PurchasePlanSupplierSplit::create(['plan_id' => $plan->id, 'plan_item_id' => $planItem->id, 'item_id' => $item->id, 'supplier_id' => $supplier->id, 'purchase_qty' => 6, 'ordered_qty' => 6, 'order_id' => $order->id, 'split_status' => 'ordered']);
        PurchaseOrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'plan_item_id' => $planItem->id, 'plan_split_id' => $split->id, 'order_qty' => 6, 'planned_base_qty' => 6]);
        $planItem->update(['ordered_qty' => 6]);
        $plan->update(['order_status' => 'order_generated']);
        app(PurchaseDraftDeletionApplicationService::class)->deleteOrder($order->id, '删除核查员');
        $this->assertDatabaseMissing('erp_purchase_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('erp_purchase_order_items', ['order_id' => $order->id]);
        $this->assertSame(0.0, (float) $split->fresh()->ordered_qty);
        $this->assertNull($split->fresh()->order_id);
        $this->assertSame('not_ordered', $split->fresh()->split_status);
        $this->assertSame(0.0, (float) $planItem->fresh()->ordered_qty);
        $this->assertSame('not_ordered', $plan->fresh()->order_status);
        $this->assertDatabaseHas('erp_purchase_logs', ['target_type' => 'purchase_order', 'target_id' => $order->id, 'action' => 'delete_draft']);
    }

    public function test_rejected_purchase_plan_is_not_a_never_submitted_draft(): void
    {
        [, $requestItem, $plan] = $this->purchasePlan();
        $plan->update(['audit_status' => 'rejected']);
        $this->denied(fn () => app(PurchaseDraftDeletionApplicationService::class)->deletePlan($plan->id));
        $this->assertSame(6.0, (float) $requestItem->fresh()->converted_qty);
        $this->assertDatabaseHas('erp_purchase_plans', ['id' => $plan->id]);
    }

    public function test_rejected_purchase_order_cannot_be_deleted(): void
    {
        $order = PurchaseOrder::create(['purchase_order_no' => 'DEL-REJECTED', 'supplier_id' => $this->supplier()->id, 'purchase_status' => 'draft', 'audit_status' => 'rejected']);
        $this->denied(fn () => app(PurchaseDraftDeletionApplicationService::class)->deleteOrder($order->id));
        $this->assertDatabaseHas('erp_purchase_orders', ['id' => $order->id]);
    }

    public function test_master_data_deletion_requires_disabled_and_no_references_including_cascade_relations(): void
    {
        $service = app(MasterDataApplicationService::class);
        $supplier = $this->supplier();
        $this->denied(fn () => $service->deleteUnused('suppliers', $supplier));
        $supplier->update(['status' => 'disabled']);
        $item = $this->item();
        $relation = SupplierItemRelation::create(['supplier_id' => $supplier->id, 'item_id' => $item->id]);
        $this->denied(fn () => $service->deleteUnused('suppliers', $supplier));
        $this->assertDatabaseHas('erp_supplier_item_relations', ['id' => $relation->id]);
        $unused = $this->supplier('UNUSED');
        $unused->update(['status' => 'disabled']);
        $service->deleteUnused('suppliers', $unused);
        $this->assertDatabaseMissing('erp_suppliers', ['id' => $unused->id]);
    }

    public function test_draft_invoice_deletion_clears_its_matches_and_keeps_audit(): void
    {
        $invoice = $this->invoice();
        $match = FinanceInvoiceAllocation::create(['invoice_id' => $invoice->id, 'source_business_type' => 'sales_order', 'source_document_id' => 991001, 'source_document_no' => 'DEL-SO', 'source_amount_snapshot' => 100, 'allocated_amount' => 20, 'idempotency_key' => 'DEL-INVOICE-MATCH']);
        app(FinanceDraftDeletionApplicationService::class)->deleteInvoice($invoice->id, '录入错误重建', $this->user());
        $this->assertDatabaseMissing('erp_finance_invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('erp_finance_invoice_allocations', ['id' => $match->id]);
        $this->assertDatabaseHas('erp_finance_operation_logs', ['document_type' => 'finance_invoice', 'document_id' => $invoice->id, 'action' => 'delete_draft', 'content' => '录入错误重建']);
    }

    public function test_supplier_finance_party_reference_blocks_deletion_without_a_foreign_key(): void
    {
        $supplier = $this->supplier();
        $supplier->update(['status' => 'disabled']);
        $invoice = $this->invoice();
        $invoice->update(['invoice_direction' => 'purchase', 'party_type' => 'supplier', 'party_id' => $supplier->id]);
        $this->denied(fn () => app(MasterDataApplicationService::class)->deleteUnused('suppliers', $supplier));
        $this->assertDatabaseHas('erp_suppliers', ['id' => $supplier->id]);
    }

    public function test_financial_draft_deletion_requires_reason_and_rejects_prior_confirmation(): void
    {
        $invoice = $this->invoice();
        $service = app(FinanceDraftDeletionApplicationService::class);
        $this->denied(fn () => $service->deleteInvoice($invoice->id, ' ', $this->user()));
        $invoice->update(['confirmed_at' => now()]);
        $this->denied(fn () => $service->deleteInvoice($invoice->id, '不可擦除历史', $this->user()));
        $this->assertDatabaseHas('erp_finance_invoices', ['id' => $invoice->id]);
    }

    public function test_operation_deletion_is_versioned_and_idempotent_and_requires_delete_permission(): void
    {
        $operation = ProductionOperation::create(['operation_no' => 'DEL-OP', 'operation_name' => '未使用工序', 'status' => 'disabled', 'business_version' => 1]);
        $service = app(ProductionMasterDataService::class);
        $data = ['expected_version' => 1, 'client_command_id' => 'DEL-OP-CMD'];
        $this->denied(fn () => $service->deleteOperation($operation->id, $data, $this->user(), [], false));
        $this->denied(fn () => $service->deleteOperation($operation->id, array_replace($data, ['expected_version' => 2]), $this->user(), ['production.operation.delete'], false));
        $result = $service->deleteOperation($operation->id, $data, $this->user(), ['production.operation.delete'], false);
        $this->assertTrue($result['deleted']);
        $this->assertSame($result, $service->deleteOperation($operation->id, $data, $this->user(), ['production.operation.delete'], false));
        $this->assertDatabaseMissing('erp_production_operations', ['id' => $operation->id]);
    }

    public function test_draft_routing_deletion_checks_real_current_schema_and_keeps_referenced_operations(): void
    {
        $operation = ProductionOperation::create(['operation_no' => 'DEL-RO-OP', 'operation_name' => '路线工序', 'status' => 'disabled', 'business_version' => 1]);
        $routing = ProductionRouting::create(['routing_no' => 'DEL-RO', 'routing_name' => '草稿路线', 'output_item_id' => $this->item()->id, 'status' => 'draft', 'version' => 1, 'business_version' => 1]);
        $node = $routing->operations()->create(['operation_id' => $operation->id, 'sequence' => 1]);
        $service = app(ProductionMasterDataService::class);
        $this->denied(fn () => $service->deleteOperation($operation->id, ['expected_version' => 1, 'client_command_id' => 'DEL-REF-OP'], $this->user(), ['production.operation.delete'], false));
        $result = $service->deleteRouting($routing->id, ['expected_version' => 1, 'client_command_id' => 'DEL-RO-CMD'], $this->user(), ['production.routing.delete'], false);
        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('erp_production_routing_operations', ['id' => $node->id]);
        $this->assertDatabaseHas('erp_production_operations', ['id' => $operation->id]);
    }

    public function test_existing_master_and_purchase_delete_endpoints_reject_missing_delete_permissions(): void
    {
        $this->mockPermissions([]);
        // 收付款按真实方向决定权限，先查单据；用存在的收款草稿验证最终拒绝，
        // 不把不存在资源的 404 误判为绕过权限。
        $account = FinanceAccount::create(['account_no' => 'DEL-PERM-ACC', 'account_name' => '权限测试银行', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        $cash = FinanceCashDocument::create(['document_no' => 'DEL-PERM-CASH', 'direction' => 'receipt', 'party_type' => 'customer', 'party_id' => 991001, 'party_name_snapshot' => '权限测试客户', 'business_date' => now()->toDateString(), 'finance_account_id' => $account->id, 'amount' => 100, 'payment_method' => 'bank', 'status' => 'draft']);
        foreach ([
            'approvals/flows', 'approvals/forms', 'bom/boms', 'document-numbers/rules',
            'finance/cash-documents', 'finance/invoices', 'finance/transfers', 'inventory/adjustments',
            'master/categories', 'master/imports', 'master/item-categories', 'master/items',
            'master/locations', 'master/products', 'master/skus', 'master/suppliers', 'master/units', 'master/warehouses',
            'production/labor-allocation-rules', 'production/operations', 'production/routings',
            'purchase/orders', 'purchase/plans', 'purchase/receipts', 'purchase/requests', 'purchase/returns',
            'rbac/permissions', 'rbac/roles', 'sales/customers', 'sales/orders', 'sales/returns', 'sales/shipments',
        ] as $resource) {
            $id = $resource === 'finance/cash-documents' ? $cash->id : 991001;
            $response = $this->deleteJson('/api/v1/erp/'.$resource.'/'.$id, ['reason' => '权限核查', 'expected_version' => 1, 'client_command_id' => 'DEL-PERM-'.str_replace('/', '-', $resource)]);
            $this->assertSame(403, $response->status(), $resource.' 无删除权限必须拒绝：'.$response->json('message'));
        }
    }

    public function test_finance_attachment_survives_outer_transaction_rollback(): void
    {
        Storage::fake('deletion-test');
        Storage::disk('deletion-test')->put('rollback.txt', '附件回滚核查');
        $invoice = $this->invoice();
        $attachment = FinanceAttachment::create(['document_type' => 'finance_invoice', 'document_id' => $invoice->id, 'original_name' => 'rollback.txt', 'storage_disk' => 'deletion-test', 'storage_path' => 'rollback.txt', 'uploaded_at' => now()]);
        try {
            DB::transaction(function () use ($invoice): void {
                app(FinanceDraftDeletionApplicationService::class)->deleteInvoice($invoice->id, '外层回滚', $this->user());
                throw new \RuntimeException('模拟外层业务失败');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('模拟外层业务失败', $exception->getMessage());
        }
        $this->assertDatabaseHas('erp_finance_invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('erp_finance_attachments', ['id' => $attachment->id]);
        Storage::disk('deletion-test')->assertExists('rollback.txt');
    }

    public function test_finance_cash_and_transfer_drafts_delete_without_generating_account_movements(): void
    {
        $account = FinanceAccount::create(['account_no' => 'DEL-ACC', 'account_name' => '删除测试银行', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        $other = FinanceAccount::create(['account_no' => 'DEL-ACC2', 'account_name' => '删除测试银行2', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        $cash = FinanceCashDocument::create(['document_no' => 'DEL-CASH', 'direction' => 'receipt', 'party_type' => 'customer', 'party_id' => 991001, 'party_name_snapshot' => '删除测试客户', 'business_date' => now()->toDateString(), 'finance_account_id' => $account->id, 'amount' => 100, 'payment_method' => 'bank', 'status' => 'draft']);
        $transfer = FinanceAccountTransfer::create(['transfer_no' => 'DEL-TRANSFER', 'source_account_id' => $account->id, 'target_account_id' => $other->id, 'source_currency' => 'CNY', 'target_currency' => 'CNY', 'base_currency' => 'CNY', 'source_amount' => 100, 'target_amount' => 100, 'business_date' => now()->toDateString(), 'status' => 'draft']);
        $service = app(FinanceDraftDeletionApplicationService::class);
        $service->deleteCashDocument($cash->id, '草稿录入错误', $this->user());
        $service->deleteTransfer($transfer->id, '草稿录入错误', $this->user());
        $this->assertDatabaseMissing('erp_finance_cash_documents', ['id' => $cash->id]);
        $this->assertDatabaseMissing('erp_finance_account_transfers', ['id' => $transfer->id]);
        $this->assertDatabaseMissing('erp_finance_account_movements', ['finance_account_id' => $account->id]);
    }

    public function test_approval_flow_draft_can_be_deleted_but_published_history_cannot(): void
    {
        $flow = ApprovalFlowTemplate::create(['flow_code' => 'DEL-FLOW', 'flow_name' => '删除测试审核', 'business_module' => 'sales', 'business_type' => 'sales_order', 'business_scene' => 'confirm', 'status' => 'draft', 'current_version' => 0]);
        $service = app(ApprovalFlowApplicationService::class);
        $flow->update(['current_version' => 1]);
        $this->denied(fn () => $service->deleteDraft($flow->id));
        $flow->update(['current_version' => 0]);
        $flow->versions()->create(['version_no' => 1, 'version_status' => 'published', 'definition_snapshot' => []]);
        $this->denied(fn () => $service->deleteDraft($flow->id));
        $draft = ApprovalFlowTemplate::create(['flow_code' => 'DEL-FLOW2', 'flow_name' => '空草稿', 'business_module' => 'sales', 'business_type' => 'sales_order', 'business_scene' => 'confirm', 'status' => 'draft', 'current_version' => 0]);
        $service->deleteDraft($draft->id);
        $this->assertDatabaseMissing('erp_approval_flow_templates', ['id' => $draft->id]);
        $this->assertDatabaseHas('erp_approval_flow_templates', ['id' => $flow->id]);
    }

    public function test_bom_draft_deletion_rejects_submitted_history_even_if_status_is_draft(): void
    {
        $this->mockPermissions(['bom.manage.delete']);
        $bom = Bom::create(['bom_no' => 'DEL-BOM', 'bom_name' => '删除测试BOM', 'output_item_id' => $this->item()->id, 'bom_type' => 'standard', 'version' => 'V1', 'status' => 'draft', 'audit_status' => 'pending', 'submitted_at' => now()]);
        $this->deleteJson('/api/v1/erp/bom/boms/'.$bom->id)->assertUnprocessable();
        $bom->update(['submitted_at' => null]);
        $this->deleteJson('/api/v1/erp/bom/boms/'.$bom->id)->assertOk();
        $this->assertDatabaseMissing('erp_boms', ['id' => $bom->id]);
    }

    public function test_customer_identity_and_order_references_are_retained(): void
    {
        $customer = SalesCustomer::create(['customer_name' => '删除测试客户', 'customer_type' => 'company', 'status' => 'potential']);
        $service = app(SalesCustomerDeletionApplicationService::class);
        $customer->update(['legacy_customer_id' => 991001]);
        $this->denied(fn () => $service->delete($customer->id));
        $customer->update(['legacy_customer_id' => null]);
        SalesOrder::create(['sales_order_no' => 'DEL-CUSTOMER-SO', 'customer_id' => $customer->id, 'customer_name' => $customer->customer_name, 'order_status' => 'draft']);
        $this->denied(fn () => $service->delete($customer->id));
        $unused = SalesCustomer::create(['customer_name' => '未使用潜在客户', 'customer_type' => 'company', 'status' => 'potential']);
        $service->delete($unused->id);
        $this->assertDatabaseMissing('erp_sales_customers', ['id' => $unused->id]);
    }

    public function test_routing_node_reference_in_item_configuration_blocks_deletion(): void
    {
        $item = $this->item();
        $operation = ProductionOperation::create(['operation_no' => 'DEL-STAGE-OP', 'operation_name' => '配置引用工序', 'status' => 'enabled']);
        $routing = ProductionRouting::create(['routing_no' => 'DEL-STAGE-RO', 'routing_name' => '配置引用路线', 'output_item_id' => $item->id, 'status' => 'draft', 'version' => 1, 'business_version' => 1]);
        $node = $routing->operations()->create(['operation_id' => $operation->id, 'sequence' => 1]);
        $item->update(['serial_generation_routing_operation_id' => $node->id]);
        $this->denied(fn () => app(ProductionMasterDataService::class)->deleteRouting($routing->id, ['expected_version' => 1, 'client_command_id' => 'DEL-STAGE-CMD'], $this->user(), ['production.routing.delete'], false));
        $this->assertDatabaseHas('erp_production_routing_operations', ['id' => $node->id]);
        $this->assertSame($node->id, (int) $item->fresh()->serial_generation_routing_operation_id);
    }

    public function test_system_rbac_cannot_be_deleted_and_unused_custom_roles_can(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $this->mockPermissions(['system.role.delete', 'system.menu.delete']);
        $systemRole = DB::table('erp_rbac_roles')->where('is_system', true)->value('id');
        $systemPermission = DB::table('erp_rbac_permissions')->where('is_system', true)->value('id');
        $this->assertNotNull($systemRole);
        $this->assertNotNull($systemPermission);
        $this->deleteJson('/api/v1/erp/rbac/roles/'.$systemRole)->assertUnprocessable();
        $this->deleteJson('/api/v1/erp/rbac/permissions/'.$systemPermission)->assertUnprocessable();
        $custom = DB::table('erp_rbac_roles')->insertGetId(['code' => 'delete_unused_role', 'name' => '未使用自定义角色', 'enabled' => false, 'is_system' => false, 'data_scope' => 'self', 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson('/api/v1/erp/rbac/roles/'.$custom)->assertOk();
        $this->assertDatabaseMissing('erp_rbac_roles', ['id' => $custom]);
    }

    public function test_plan_deletion_does_not_reopen_closed_purchase_request(): void
    {
        [$request, $requestItem, $plan] = $this->purchasePlan();
        $request->update(['request_status' => 'closed', 'status' => 'closed']);
        app(PurchaseDraftDeletionApplicationService::class)->deletePlan($plan->id);
        $this->assertSame('closed', $request->fresh()->request_status);
        $this->assertSame(10.0, (float) $requestItem->fresh()->remaining_qty);
    }

    public function test_rbac_delete_fails_closed_when_system_protection_migration_is_missing(): void
    {
        $this->mockPermissions(['system.role.delete', 'system.menu.delete']);
        Schema::partialMock()->shouldReceive('hasColumn')->with('erp_rbac_roles', 'is_system')->andReturn(false);
        Schema::partialMock()->shouldReceive('hasColumn')->with('erp_rbac_permissions', 'is_system')->andReturn(false);
        $this->deleteJson('/api/v1/erp/rbac/roles/991001')->assertStatus(503);
        $this->deleteJson('/api/v1/erp/rbac/permissions/991001')->assertStatus(503);
    }

    public function test_rbac_existing_record_save_fails_closed_when_system_protection_migration_is_missing(): void
    {
        $this->mockPermissions(['system.role.save_permissions', 'system.menu.save']);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => 'save_guard_role', 'name' => '编辑保护角色', 'data_scope' => 'self', 'enabled' => false, 'is_system' => true]);
        $permission = DB::table('erp_rbac_permissions')->insertGetId(['code' => 'save.guard.permission', 'name' => '编辑保护权限', 'type' => 'button', 'enabled' => false, 'is_system' => true]);
        Schema::partialMock()->shouldReceive('hasColumn')->with('erp_rbac_roles', 'is_system')->andReturn(false);
        Schema::partialMock()->shouldReceive('hasColumn')->with('erp_rbac_permissions', 'is_system')->andReturn(false);
        $this->postJson('/api/v1/erp/rbac/roles', ['id' => $role, 'code' => 'changed_role', 'name' => '不得修改', 'data_scope' => 'all'])->assertStatus(503);
        $this->postJson('/api/v1/erp/rbac/permissions', ['id' => $permission, 'code' => 'changed.permission', 'name' => '不得修改', 'type' => 'button'])->assertStatus(503);
        $this->assertDatabaseHas('erp_rbac_roles', ['id' => $role, 'code' => 'save_guard_role']);
        $this->assertDatabaseHas('erp_rbac_permissions', ['id' => $permission, 'code' => 'save.guard.permission']);
    }

    public function test_purchase_plan_submission_log_cannot_be_erased_by_draft_status_reset(): void
    {
        [, , $plan] = $this->purchasePlan();
        PurchaseLog::create(['target_type' => 'purchase_plan', 'target_id' => $plan->id, 'action' => 'submit', 'content' => '历史提交', 'operator' => '核查员']);
        $this->denied(fn () => app(PurchaseDraftDeletionApplicationService::class)->deletePlan($plan->id));
        $this->assertDatabaseHas('erp_purchase_plans', ['id' => $plan->id]);
    }

    public function test_inventory_adjustment_deletion_rejects_submitted_and_posted_history(): void
    {
        $adjustment = InventoryAdjustment::create(['adjustment_no' => 'DEL-ADJ', 'adjustment_status' => 'draft', 'submitted_at' => now()]);
        $service = app(InventoryAdjustmentApplicationService::class);
        $this->denied(fn () => $service->deleteDraft($adjustment->id));
        $adjustment->update(['submitted_at' => null, 'posted_at' => now()]);
        $this->denied(fn () => $service->deleteDraft($adjustment->id));
        $adjustment->update(['posted_at' => null]);
        $service->deleteDraft($adjustment->id);
        $this->assertDatabaseMissing('erp_inventory_adjustments', ['id' => $adjustment->id]);
    }

    public function test_import_deletion_cascades_preview_rows_but_never_confirmed_results(): void
    {
        $this->mockPermissions(['master.import.delete']);
        $batch = ImportBatch::create(['batch_no' => 'DEL-IMPORT', 'import_type' => 'Item', 'file_name' => 'delete.csv', 'status' => 'previewed']);
        $row = $batch->rows()->create(['row_no' => 1, 'raw_data' => ['物料名称' => '预检物料'], 'validation_status' => 'valid']);
        $batch->update(['confirmed_at' => now()]);
        $this->deleteJson('/api/v1/erp/master/imports/'.$batch->id)->assertUnprocessable();
        $this->assertDatabaseHas('erp_import_rows', ['id' => $row->id]);
        $batch->update(['confirmed_at' => null]);
        $this->deleteJson('/api/v1/erp/master/imports/'.$batch->id)->assertOk();
        $this->assertDatabaseMissing('erp_import_rows', ['id' => $row->id]);
    }

    public function test_number_rule_delete_preserves_allocated_number_history(): void
    {
        $rule = DocumentNumberRule::create(['document_type' => 'deletion_unused', 'name' => '未用规则', 'prefix' => 'DU', 'enabled' => false]);
        app(DocumentNumberRuleService::class)->delete($rule, '清理未用规则', $this->user());
        $this->assertDatabaseMissing('erp_document_number_rules', ['id' => $rule->id]);
        app(DocumentNumberService::class)->next('deletion_used', 'DT');
        $used = DocumentNumberRule::create(['document_type' => 'deletion_used', 'name' => '已用规则', 'prefix' => 'DT', 'enabled' => false]);
        $this->denied(fn () => app(DocumentNumberRuleService::class)->delete($used, '保留旧编号', $this->user()));
        $this->assertDatabaseHas('erp_document_number_rules', ['id' => $used->id]);
    }

    public function test_labor_rule_delete_is_idempotent_and_active_versions_are_retained(): void
    {
        $rule = ProductionLaborAllocationRule::create(['rule_no' => 'DEL-LABOR', 'rule_name' => '草稿工时规则', 'version_no' => 1, 'owner_ratio' => .6, 'collaborator_total_ratio' => .4, 'collaborator_allocation_method' => 'actual_labor_ratio', 'status' => 'draft', 'business_version' => 1]);
        $service = app(ProductionLaborAllocationRuleService::class);
        $payload = ['expected_version' => 1, 'client_command_id' => 'DEL-LABOR-CMD'];
        $rule->update(['status' => 'active']);
        $this->denied(fn () => $service->deleteDraft($rule->id, $payload, $this->user(), ['production.labor_rule.delete']));
        $rule->update(['status' => 'draft', 'effective_at' => now()]);
        $this->denied(fn () => $service->deleteDraft($rule->id, $payload, $this->user(), ['production.labor_rule.delete']));
        $rule->update(['effective_at' => null]);
        $result = $service->deleteDraft($rule->id, $payload, $this->user(), ['production.labor_rule.delete']);
        $this->assertTrue($result['deleted']);
        $this->assertSame($result, $service->deleteDraft($rule->id, $payload, $this->user(), ['production.labor_rule.delete']));
    }

    public function test_sales_order_draft_delete_removes_all_attachment_rows_and_keeps_deletion_log(): void
    {
        $order = SalesOrder::create(['sales_order_no' => 'DEL-SO', 'customer_name' => '草稿客户', 'order_status' => 'draft']);
        foreach (['active', 'deleted', 'replaced'] as $status) {
            SalesOrderAttachment::create(['sales_order_id' => $order->id, 'original_name' => $status.'.txt', 'stored_name' => $status.'.txt', 'storage_path' => '', 'status' => $status]);
        }
        $service = app(SalesOrderDraftService::class);
        $order->update(['confirm_status' => 'pending_confirmation']);
        $this->denied(fn () => $service->delete($order, '删除核查员'));
        $order->update(['confirm_status' => 'unconfirmed']);
        $service->delete($order, '删除核查员');
        $this->assertDatabaseMissing('erp_sales_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('erp_sales_order_attachments', ['sales_order_id' => $order->id]);
        $this->assertDatabaseHas('erp_sales_order_logs', ['action' => 'delete_draft', 'operator' => '删除核查员']);
    }

    private function purchasePlan(): array
    {
        $item = $this->item();
        $request = PurchaseRequest::create(['request_no' => 'DEL-REQ', 'item_id' => $item->id, 'request_status' => 'partially_planned', 'status' => 'partially_planned', 'request_qty' => 10, 'planned_qty' => 6]);
        $requestItem = PurchaseRequestItem::create(['request_id' => $request->id, 'item_id' => $item->id, 'request_qty' => 10, 'converted_qty' => 6, 'remaining_qty' => 4]);
        $plan = PurchasePlan::create(['plan_no' => 'DEL-PLAN', 'plan_status' => 'draft', 'audit_status' => 'pending']);
        $planItem = PurchasePlanItem::create(['plan_id' => $plan->id, 'request_id' => $request->id, 'request_item_id' => $requestItem->id, 'item_id' => $item->id, 'required_qty' => 6, 'plan_qty' => 6]);
        return [$request, $requestItem, $plan, $planItem, $item];
    }

    private function item(): Item
    {
        $unit = Unit::create(['unit_code' => 'DEL-EA', 'unit_name' => '件', 'unit_type' => 'quantity', 'status' => 'enabled']);
        return Item::create(['item_code' => 'DEL-ITEM', 'item_name' => '删除核查物料', 'unit_id' => $unit->id, 'item_type' => 'raw_material', 'status' => 'enabled']);
    }

    private function supplier(string $suffix = 'MAIN'): Supplier
    {
        return Supplier::create(['supplier_code' => 'DEL-SUP-'.$suffix, 'supplier_name' => '删除核查供应商'.$suffix, 'status' => 'enabled']);
    }

    private function invoice(): FinanceInvoice
    {
        return FinanceInvoice::create(['document_no' => 'DEL-INV', 'invoice_direction' => 'sales', 'party_type' => 'customer', 'party_id' => 991001, 'party_name_snapshot' => '删除测试客户', 'amount_excl_tax' => 100, 'tax_amount' => 0, 'amount_incl_tax' => 100, 'status' => 'draft']);
    }

    private function user(): object
    {
        return (object) ['legacy_id' => 991001, 'username' => 'delete_tester', 'nickname' => '删除核查员', 'auth_group_names' => '[]'];
    }

    private function mockPermissions(array $permissions): void
    {
        $this->mock(AuthContextService::class, function (MockInterface $mock) use ($permissions): void {
            $mock->shouldReceive('currentUser')->andReturn($this->user());
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn($permissions);
        });
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('状态、权限或引用不满足时不允许删除。');
        } catch (ValidationException|HttpException|WorkOrderDomainException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }
}
