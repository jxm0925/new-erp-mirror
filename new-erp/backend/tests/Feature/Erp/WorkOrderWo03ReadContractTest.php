<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Sku;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Services\Erp\BomMatcher;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\SalesOrderFulfillmentApplicationService;
use Mockery\MockInterface;

class WorkOrderWo03ReadContractTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.demand.view', 'production.work_order.view', 'production.work_order.create',
        'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
    ];

    public function test_real_http_filters_are_whitelisted_and_demand_children_are_paginated(): void
    {
        [$user, $demand] = $this->fixture(1801, 'FILTER-CUSTOMER', 'FILTER-SO', '2026-09-10');
        $otherDemand = $this->fixture(1802, 'OTHER-CUSTOMER', 'OTHER-SO', '2026-09-20')[1];
        $this->grantPermissionsRole($user->legacy_id, 'wo03_creator_all', self::PERMISSIONS, 'all');
        $token = $this->token($user->legacy_id);

        $noMatch = $this->withToken($token)->getJson('/api/v1/erp/production/demands?customer=NO-SUCH-WO03&per_page=999');
        $noMatch->assertOk()->assertJsonPath('total', 0)->assertJsonPath('per_page', 100);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?customer=FILTER-CUSTOMER')
            ->assertOk()->assertJsonPath('per_page', 20);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?customer=OTHER-CUSTOMER&per_page=-5')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 1);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?customer=FILTER-CUSTOMER&date_from=2026-09-01&date_to=2026-09-15&quantity_min=9&quantity_max=11&per_page=200')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 100);
        $this->assertNotSame($demand->id, $otherDemand->id);

        $create = app(WorkOrderApplicationService::class)->createDraft([
            'client_command_id' => 'wo03-read-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 2,
        ], $user, self::PERMISSIONS);
        $detail = $this->withToken($token)->getJson('/api/v1/erp/production/demands/'.$demand->id.'?page=1&per_page=1');
        $detail->assertOk()->assertJsonPath('data.work_orders_pagination.total', 1)->assertJsonPath('data.work_orders_pagination.per_page', 1);
        $detail->assertJsonMissingPath('data.work_orders.0.response_snapshot')->assertJsonMissingPath('data.work_orders.0.error_message');
        $this->withToken($token)->getJson('/api/v1/erp/production/demands/'.$demand->id)
            ->assertOk()->assertJsonPath('data.work_orders_pagination.per_page', 20);

        $orders = $this->withToken($token)->getJson('/api/v1/erp/production/work-orders?keyword=NO-SUCH-WO03&per_page=0');
        $orders->assertOk()->assertJsonPath('total', 0)->assertJsonPath('per_page', 1);
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders?customer=FILTER-CUSTOMER&per_page=20')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders?customer=FILTER-CUSTOMER')
            ->assertOk()->assertJsonPath('per_page', 20);
        $orders->assertJsonMissingPath('data.0.response_snapshot')->assertJsonMissingPath('data.0.request_hash')->assertJsonMissingPath('data.0.error_message');
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?date_from=not-a-date')
            ->assertStatus(422)->assertJsonPath('error_code', 'validation_error')->assertJsonStructure(['errors', 'details']);
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders?quantity_min=-1')
            ->assertStatus(422)->assertJsonPath('error_code', 'validation_error')->assertJsonStructure(['errors', 'details']);
        $this->assertNotNull($create->id);
    }

    public function test_real_http_detail_returns_allowlisted_field_audit_after_draft_edit(): void
    {
        [$user, $demand] = $this->fixture(1901, 'AUDIT-CUSTOMER', 'AUDIT-SO', '2026-09-10');
        $this->grantPermissionsRole($user->legacy_id, 'wo03_creator_all', self::PERMISSIONS, 'all');
        $token = $this->token($user->legacy_id);
        $service = app(WorkOrderApplicationService::class);
        $workOrder = $service->createDraft([
            'client_command_id' => 'wo03-audit-create', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 2,
        ], $user, self::PERMISSIONS);
        $service->updateDraft($workOrder->id, [
            'client_command_id' => 'wo03-audit-edit', 'expected_version' => 1, 'target_qty' => 3,
            'production_batch' => 'AUDIT-BATCH', 'production_location_name' => '装配车间',
        ], $user, self::PERMISSIONS);

        $response = $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/'.$workOrder->id);
        $response->assertOk()->assertJsonPath('data.quantity.target_qty', 3)
            ->assertJsonPath('data.field_audit_summary.old.target_qty', 2)
            ->assertJsonPath('data.field_audit_summary.new.target_qty', 3)
            ->assertJsonMissingPath('data.organization_code')
            ->assertJsonMissingPath('data.response_snapshot')
            ->assertJsonMissingPath('data.request_hash')
            ->assertJsonMissingPath('data.error_message');
        $this->assertDatabaseHas('erp_operation_logs', [
            'module' => 'work_order', 'action' => 'edit_draft', 'target_type' => 'work_order', 'target_id' => $workOrder->id,
        ]);
    }

    public function test_demand_and_sales_order_delivery_ranges_are_independent(): void
    {
        [$user, $demand] = $this->fixture(2051, 'DATE-CUSTOMER', 'DATE-SO', '2026-09-10');
        SalesOrder::query()->whereKey($demand->sales_order_id)->update(['required_delivery_date' => '2026-09-20']);
        $this->grantPermissionsRole($user->legacy_id, 'wo03_creator_all', self::PERMISSIONS, 'all');
        $token = $this->token($user->legacy_id);

        $this->withToken($token)->getJson('/api/v1/erp/production/demands?date_from=2026-09-10&date_to=2026-09-10&delivery_date_from=2026-09-20&delivery_date_to=2026-09-20')
            ->assertOk()->assertJsonPath('total', 1);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?date_from=2026-09-20&date_to=2026-09-20&delivery_date_from=2026-09-20&delivery_date_to=2026-09-20')
            ->assertOk()->assertJsonPath('total', 0);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?date_from=2026-09-10&date_to=2026-09-10&delivery_date_from=2026-09-10&delivery_date_to=2026-09-10')
            ->assertOk()->assertJsonPath('total', 0);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands?delivery_date_from=not-a-date')
            ->assertStatus(422)->assertJsonPath('error_code', 'validation_error')->assertJsonStructure(['errors', 'details']);
    }

    public function test_real_http_role_matrix_keeps_backend_decisive(): void
    {
        [$manager, $managerDemand] = $this->fixture(2001, 'ROLE-MATRIX-A', 'ROLE-MATRIX-A-SO', '2026-09-10');
        [$principal, $principalDemand] = $this->fixture(2002, 'ROLE-MATRIX-B', 'ROLE-MATRIX-B-SO', '2026-09-10');
        $this->grantPermissionsRole($manager->legacy_id, 'wo03_creator_all', self::PERMISSIONS, 'all');
        $this->grantRole($principal->legacy_id, 'department_principal');
        $managerToken = $this->token($manager->legacy_id);
        $principalToken = $this->token($principal->legacy_id);

        $this->withToken($managerToken)->getJson('/api/v1/erp/production/demands?keyword=ROLE-MATRIX-')->assertOk()->assertJsonPath('total', 2);
        $this->withToken($principalToken)->getJson('/api/v1/erp/production/demands/'.$principalDemand->id)
            ->assertForbidden()->assertJsonPath('error_code', 'data_scope_denied');
        $this->withToken($principalToken)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo03-principal-denied', 'production_demand_id' => $principalDemand->id,
            'expected_demand_version' => 1, 'target_qty' => 1,
        ])->assertForbidden()->assertJsonPath('error_code', 'permission_denied');
        $this->assertNotNull($managerDemand->id);
    }

    public function test_real_http_write_responses_never_expose_internal_organization_code(): void
    {
        [$user, $demand] = $this->fixture(1951, 'WRITE-PRIVACY-CUSTOMER', 'WRITE-PRIVACY-SO', '2026-09-10');
        $this->grantPermissionsRole($user->legacy_id, 'wo03_creator_all', self::PERMISSIONS, 'all');
        $token = $this->token($user->legacy_id);

        $created = $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo03-write-privacy-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 1, 'organization_code' => 'CLIENT-CANNOT-CHOOSE',
        ])->assertCreated()->assertJsonMissingPath('data.organization_code');
        $id = (int) $created->json('data.id');

        $this->withToken($token)->putJson('/api/v1/erp/production/work-orders/'.$id, [
            'client_command_id' => 'wo03-write-privacy-edit', 'expected_version' => 1, 'target_qty' => 1,
            'organization_code' => 'CLIENT-CANNOT-CHOOSE',
        ])->assertOk()->assertJsonMissingPath('data.organization_code');
    }

    public function test_real_http_actions_follow_status_permissions_and_caller_scope(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        [$manager, $publicDemand] = $this->fixture(3100, 'MATRIX-MANAGER', 'MATRIX-MANAGER-SO', '2026-09-10');
        [$operator, $operatorOwnedDemand] = $this->fixture(3101, 'MATRIX-OPERATOR', 'MATRIX-OPERATOR-SO', '2026-09-10');
        $this->grantPermissionsRole($manager->legacy_id, 'wo03_matrix_creator', self::PERMISSIONS, 'all');
        $this->assignRole($operator->legacy_id, 'production_operator');
        $managerToken = $this->token($manager->legacy_id);
        $operatorToken = $this->token($operator->legacy_id);

        $this->withToken($managerToken)->getJson('/api/v1/erp/production/demands?keyword=MATRIX-')
            ->assertOk()->assertJsonPath('total', 2);
        $this->withToken($operatorToken)->getJson('/api/v1/erp/production/demands?keyword=MATRIX-')
            ->assertOk()->assertJsonPath('total', 0);
        $this->withToken($operatorToken)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo03-operator-create-denied', 'production_demand_id' => $operatorOwnedDemand->id,
            'expected_demand_version' => 1, 'target_qty' => 1,
        ])->assertForbidden()->assertJsonPath('error_code', 'permission_denied');

        $created = $this->withToken($managerToken)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo03-manager-assign-operator', 'production_demand_id' => $publicDemand->id,
            'expected_demand_version' => 1, 'target_qty' => 1, 'planned_date' => '2026-09-10',
            'responsible_user_legacy_id' => $operator->legacy_id,
        ])->assertCreated();
        $workOrderId = (int) $created->json('data.id');

        $this->withToken($operatorToken)->getJson('/api/v1/erp/production/demands/'.$publicDemand->id)->assertOk();
        $this->withToken($operatorToken)->getJson('/api/v1/erp/production/work-orders/'.$workOrderId)
            ->assertOk()->assertJsonPath('data.actions.edit', false)
            ->assertJsonPath('data.actions.submit', false)
            ->assertJsonPath('data.actions.cancel', false)
            ->assertJsonPath('data.responsible_user.user_id', $operator->legacy_id)
            ->assertJsonMissingPath('data.responsible_user_legacy_id');
    }

    public function test_real_http_sales_confirmation_to_demand_to_draft_work_order_e2e(): void
    {
        $this->mock(BomMatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('match')->once()->andReturn([
                'status' => 'matched', 'block_reason' => null, 'bom_id' => null, 'bom_version_id' => null,
                'bom_version' => null, 'bom_snapshot' => null, 'candidates' => [],
            ]);
        });
        $this->mock(InventoryAvailabilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('analyzeSalesOrderLine')->andReturn([
                'calculated_at' => now()->toDateTimeString(), 'available_base_qty' => 0, 'available_sales_qty' => 0,
                'suggested_inventory_qty' => 0, 'suggested_production_qty' => 5, 'suggestion_reason' => 'no stock',
                'fulfillment_factor' => 1, 'balances' => collect(),
            ]);
        });
        $sequence = 0;
        $this->mock(DocumentNumberService::class, function (MockInterface $mock) use (&$sequence): void {
            $mock->shouldReceive('next')->andReturnUsing(fn () => 'WO03-E2E-'.(++$sequence));
        });

        $adminId = 4901;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $adminId, 'username' => 'admin', 'nickname' => 'WO03 E2E Admin', 'status' => 'normal',
            'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->token($adminId);
        $unit = Unit::create([
            'unit_code' => 'WO03-E2E-U', 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled',
        ]);
        $product = Product::create([
            'product_code' => 'WO03-E2E-P', 'product_name' => 'WO03 E2E Product',
            'product_type' => 'standard', 'status' => 'enabled',
        ]);
        $sku = Sku::create([
            'product_id' => $product->id, 'sales_unit_id' => $unit->id,
            'sku_code' => 'WO03-E2E-S', 'sku_name' => 'WO03 E2E SKU',
            'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'enabled',
        ]);
        $item = Item::create([
            'item_code' => 'WO03-E2E-I', 'item_name' => 'WO03 E2E Item',
            'item_type' => 'finished_good', 'unit_id' => $unit->id,
            'is_stock_item' => true, 'status' => 'enabled',
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'WO03-E2E-SO', 'customer_name' => 'WO03 E2E Customer', 'order_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'production_confirm_status' => 'pending', 'total_amount' => 0,
            'final_receivable_amount' => 0, 'required_delivery_date' => '2026-09-20',
            'funding_policy_snapshot' => ['policy_type' => 'installment_contract', 'production_threshold_type' => 'amount', 'production_threshold_value' => '0', 'shipment_requires_full_payment' => true],
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => 'WO03-E2E-LINE', 'line_type' => 'physical', 'order_qty' => 5,
            'unit_price' => 1, 'amount' => 5, 'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->unit_name,
            'item_id' => $item->id, 'item_name' => $item->item_name,
            'product_id' => $product->id, 'product_name' => $product->product_name,
            'sku_id' => $sku->id, 'sku_name' => $sku->sku_name,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id,
            'item_base_required_qty' => 5, 'line_status' => 'confirmed_pending_fulfillment',
        ]);

        $this->withToken($token)->postJson('/api/v1/erp/sales/orders/'.$order->id.'/production-confirmation', [
            'lines' => [[
                'sales_order_line_id' => $line->id, 'confirm_qty' => 5, 'inventory_qty' => 0,
                'production_qty' => 5, 'service_qty' => 0, 'no_delivery_qty' => 0,
            ]], 'remark' => 'WO03 E2E',
        ])->assertOk();
        $demandId = (int) DB::table('erp_sales_order_production_requirements')->where('sales_order_id', $order->id)->value('id');
        $this->assertGreaterThan(0, $demandId);
        $this->withToken($token)->getJson('/api/v1/erp/production/demands/'.$demandId)->assertOk()->assertJsonPath('data.sales_order.order_no', 'WO03-E2E-SO');
        $created = $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo03-e2e-create', 'production_demand_id' => $demandId,
            'expected_demand_version' => 1, 'target_qty' => 5,
        ]);
        $created->assertCreated()->assertJsonMissingPath('data.organization_code');
        $this->assertDatabaseHas('erp_work_orders', ['id' => $created->json('data.id'), 'production_demand_id' => $demandId, 'status' => 'DRAFT']);
    }

    public function test_p1_gate_people_semantics_and_full_draft_edit_are_real(): void
    {
        [$salesOwner, $demand] = $this->fixture(6101, 'P1-CUSTOMER', 'P1-SO', '2026-09-18');
        foreach ([6102, 6103] as $userId) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $userId, 'username' => 'production-'.$userId, 'nickname' => '生产用户 '.$userId,
                'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->grantRole($userId, 'production_operator');
        }
        $creatorId = 6104;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $creatorId, 'username' => 'creator-'.$creatorId, 'nickname' => '建单权限用户',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->grantPermissionsRole($creatorId, 'p1_creator_permission_role', self::PERMISSIONS, 'all');
        $token = $this->token($creatorId);

        $directory = $this->withToken($token)
            ->getJson('/api/v1/erp/user-directory/users?scope=production&status=normal&keyword=production-61&page=1&per_page=100')
            ->assertOk();
        $this->assertCount(2, $directory->json('data'));
        foreach ($directory->json('data') as $projectedUser) {
            $this->assertSame(['user_id', 'display_name', 'department_name', 'status'], array_keys($projectedUser));
        }

        $this->withToken($token)->getJson('/api/v1/erp/production/demands/'.$demand->id)
            ->assertOk()
            ->assertJsonPath('data.readiness.has_work_order_allocation', false)
            ->assertJsonPath('data.create_work_order_gate.allowed', true)
            ->assertJsonPath('data.create_work_order_gate.remaining_qty', 10)
            ->assertJsonPath('data.sales_owner.user_id', $salesOwner->legacy_id);

        $created = $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'p1-gate-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 2, 'responsible_user_legacy_id' => 6102,
        ])->assertCreated()
            ->assertJsonPath('data.responsible_user.display_name', '生产用户 6102')
            ->assertJsonMissingPath('data.responsible_user_legacy_id');

        $workOrderId = (int) $created->json('data.id');
        $this->withToken($token)->getJson('/api/v1/erp/production/demands/'.$demand->id)
            ->assertOk()
            ->assertJsonPath('data.readiness.has_work_order_allocation', true)
            ->assertJsonPath('data.create_work_order_gate.allowed', true)
            ->assertJsonPath('data.create_work_order_gate.remaining_qty', 8)
            ->assertJsonPath('data.production_responsible_user.user_id', 6102)
            ->assertJsonPath('data.sales_owner.user_id', $salesOwner->legacy_id);

        $this->withToken($token)->putJson('/api/v1/erp/production/work-orders/'.$workOrderId, [
            'client_command_id' => 'p1-full-edit', 'expected_version' => 1, 'target_qty' => 3,
            'planned_date' => '2026-09-22', 'production_batch' => 'P1-BATCH',
            'responsible_user_legacy_id' => 6103, 'production_location_name' => '一号装配区',
        ])->assertOk()
            ->assertJsonPath('data.quantity.target_qty', 3)
            ->assertJsonPath('data.plan.planned_date', '2026-09-22')
            ->assertJsonPath('data.plan.production_batch', 'P1-BATCH')
            ->assertJsonPath('data.plan.production_location_name', '一号装配区')
            ->assertJsonPath('data.responsible_user.display_name', '生产用户 6103')
            ->assertJsonMissingPath('data.responsible_user_legacy_id');
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/'.$workOrderId)
            ->assertOk()
            ->assertJsonPath('data.field_audit_summary.new.responsible_user.display_name', '生产用户 6103')
            ->assertJsonMissingPath('data.field_audit_summary.new.responsible_user_legacy_id')
            ->assertJsonMissingPath('data.field_audit_summary.old.responsible_user_legacy_id');
    }

    public function test_create_permission_code_is_required_even_if_context_is_marked_super_admin(): void
    {
        [$user, $demand] = $this->fixture(6151, 'STRICT-PERMISSION', 'STRICT-PERMISSION-SO', '2026-09-18');

        try {
            app(WorkOrderApplicationService::class)->createDraft([
                'client_command_id' => 'p1-strict-permission',
                'production_demand_id' => $demand->id,
                'expected_demand_version' => 1,
                'target_qty' => 1,
            ], $user, [], true);
            $this->fail('Context flags must not replace production.work_order.create.');
        } catch (\App\Exceptions\Erp\WorkOrderDomainException $exception) {
            $this->assertSame('permission_denied', $exception->errorCode);
            $this->assertSame('production.work_order.create', $exception->details['permission'] ?? null);
        }
    }

    public function test_sales_role_cannot_manage_production_and_validation_message_is_utf8(): void
    {
        [$salesUser, $demand] = $this->fixture(6201, 'SALES-ONLY', 'SALES-ONLY-SO', '2026-09-18');
        $this->grantRole($salesUser->legacy_id, 'sales_user');
        $this->withToken($this->token($salesUser->legacy_id))->getJson('/api/v1/erp/production/demands/'.$demand->id)
            ->assertForbidden()->assertJsonPath('error_code', 'permission_denied');

        $creatorId = 6202;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $creatorId, 'username' => 'utf8-creator', 'nickname' => '异常测试用户',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->grantPermissionsRole($creatorId, 'p1_utf8_creator', self::PERMISSIONS, 'all');
        $creator = DB::table('erp_legacy_admin_users')->where('legacy_id', $creatorId)->first();
        try {
            app(WorkOrderApplicationService::class)->createDraft([
                'client_command_id' => 'p1-utf8-missing-version', 'production_demand_id' => $demand->id, 'target_qty' => 1,
            ], $creator, self::PERMISSIONS);
            $this->fail('Missing demand version must be rejected.');
        } catch (\App\Exceptions\Erp\WorkOrderDomainException $exception) {
            $this->assertSame('validation_error', $exception->errorCode);
            $this->assertSame('expected_demand_version 不能为空。', $exception->getMessage());
            $this->assertDoesNotMatchRegularExpression('/�|鏃|鍒|绾|闇/', $exception->getMessage());
        }
    }

    public function test_p1_frontend_source_has_no_route_rename_collapse_override_or_mock_actions(): void
    {
        $app = file_get_contents(base_path('../frontend/src/App.vue'));
        $styles = file_get_contents(base_path('../frontend/src/styles.css'));
        $admin = file_get_contents(base_path('../frontend/src/views/erp/system/SystemAdminManagement.vue'));
        $salesForm = file_get_contents(base_path('../frontend/src/views/erp/sales/SalesOrderForm.vue'));
        $workOrder = file_get_contents(base_path('../frontend/src/views/erp/production/WorkOrderDetail.vue'));

        $this->assertStringNotContainsString('productionShellTitle', $app);
        $this->assertStringNotContainsString('质量管理', $app);
        $this->assertStringNotContainsString('报表中心', $app);
        $this->assertStringContainsString('#app.production-route.sidebar-collapsed .erp-sidebar { width: 64px; }', $styles);
        $this->assertStringNotContainsString('mockAction', $admin);
        $this->assertStringNotContainsString('批量上传', $salesForm);
        foreach (['开始生产', '现场报工', '领料成功', '完工成功'] as $fakeAction) {
            $this->assertStringNotContainsString($fakeAction, $workOrder);
        }
    }

    private function fixture(int $ownerId, string $customer, string $orderNo, string $requiredDate): array
    {
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $ownerId, 'username' => 'qa-'.$ownerId, 'nickname' => 'QA '.$ownerId,
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->first();
        $order = SalesOrder::create([
            'sales_order_no' => $orderNo, 'customer_name' => $customer, 'order_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed', 'sales_user_legacy_id' => $ownerId,
            'created_by_legacy_id' => $ownerId, 'total_amount' => 0, 'final_receivable_amount' => 0,
            'required_delivery_date' => $requiredDate,
        ]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => $orderNo.'-line', 'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 0, 'amount' => 0]);
        $demand = ProductionDemand::create([
            'requirement_no' => $orderNo.'-DEMAND', 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'production_qty' => 10, 'allocated_qty' => 0, 'consumed_qty' => 0, 'remaining_qty' => 10, 'closed_qty' => 0,
            'requirement_status' => 'ready', 'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1,
            'business_version' => 1, 'is_ready_for_work_order' => false, 'required_delivery_date' => $requiredDate,
        ]);
        return [$user, $demand];
    }

    private function grantProductionOperator(int $userId): void
    {
        $this->grantRole($userId, 'production_operator');
    }

    private function grantRole(int $userId, string $roleCode): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => $userId, 'role_id' => $roleId]);
    }

    private function grantPermissionsRole(int $userId, string $roleCode, array $permissions, string $dataScope): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        DB::table('erp_rbac_roles')->updateOrInsert(
            ['code' => $roleCode],
            ['name' => '测试权限角色', 'data_scope' => $dataScope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => $userId, 'role_id' => $roleId]);
    }

    private function assignRole(int $userId, string $roleCode): void
    {
        $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => $userId, 'role_id' => $roleId]);
    }

    private function attachDepartment(int $departmentId, array $userIds, ?int $principalId = null): void
    {
        DB::table('erp_departments')->insert([
            'legacy_id' => $departmentId, 'parent_legacy_id' => 0, 'name' => 'WO03 Department '.$departmentId,
            'status' => 'normal', 'sort' => 0, 'legacy_payload' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($userIds as $userId) {
            DB::table('erp_department_users')->insert([
                'department_legacy_id' => $departmentId, 'user_legacy_id' => $userId,
                'is_principal' => $principalId === $userId, 'is_owner' => false,
                'legacy_payload' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function token(int $userId): string
    {
        $token = 'wo03-token-'.uniqid();
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $token;
    }
}
