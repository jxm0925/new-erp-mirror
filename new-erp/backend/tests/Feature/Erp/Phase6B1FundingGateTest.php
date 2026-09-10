<?php

namespace Tests\Feature\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\PaymentMethod;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesFundingPolicy;
use App\Models\Erp\SalesShipment;
use App\Services\Erp\FinanceAllocationApplicationService;
use App\Services\Erp\PaymentMethodApplicationService;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\SalesOrderFundingGateService;
use App\Services\Erp\SalesOrderSnapshotService;
use App\Services\Erp\SalesShipmentApplicationService;
use App\Services\Erp\SalesFundingPolicyApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Phase6B1FundingGateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_full_prepay_deposit_ratio_and_custom_amount_use_real_net_allocations(): void
    {
        $full = $this->order(['policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true]);
        $this->receipt($full, '99.0000');
        $status = app(SalesOrderFundingGateService::class)->status($full);
        $this->assertFalse($status['production_funds_satisfied']);
        $this->assertFalse($status['shipment_funds_satisfied']);
        $this->receipt($full, '1.0000');
        $status = app(SalesOrderFundingGateService::class)->status($full);
        $this->assertTrue($status['production_funds_satisfied']);
        $this->assertTrue($status['shipment_funds_satisfied']);

        $deposit = $this->order([
            'policy_type' => 'deposit_production', 'production_threshold_type' => 'ratio',
            'production_threshold_value' => '0.3', 'shipment_requires_full_payment' => true,
        ]);
        $this->receipt($deposit, '30.0000');
        $status = app(SalesOrderFundingGateService::class)->status($deposit);
        $this->assertSame('30.0000', $status['production_required_amount']);
        $this->assertTrue($status['production_funds_satisfied']);
        $this->assertFalse($status['shipment_funds_satisfied']);

        $custom = $this->order([
            'policy_type' => 'custom_threshold', 'production_threshold_type' => 'amount',
            'production_threshold_value' => '40', 'shipment_requires_full_payment' => true,
        ]);
        $this->receipt($custom, '40.0000');
        $status = app(SalesOrderFundingGateService::class)->status($custom);
        $this->assertSame('40.0000', $status['production_required_amount']);
        $this->assertTrue($status['production_funds_satisfied']);
        $this->assertFalse($status['shipment_funds_satisfied']);
    }

    public function test_missing_or_legacy_false_policy_blocks_and_legacy_true_maps_to_full_prepay(): void
    {
        $order = $this->order(null);
        $this->receipt($order, '100.0000');
        $status = app(SalesOrderFundingGateService::class)->status($order);
        $this->assertSame('funding_policy_missing', $status['production_block_reason']);
        $this->assertFalse($status['shipment_funds_satisfied']);

        $order->update(['funding_policy_snapshot' => ['full_payment' => false]]);
        $this->assertFalse(app(SalesOrderFundingGateService::class)->status($order->fresh())['shipment_funds_satisfied']);

        $order->update(['funding_policy_snapshot' => ['full_payment' => true]]);
        $mapped = app(SalesOrderFundingGateService::class)->status($order->fresh());
        $this->assertSame('full_prepay', $mapped['policy_type']);
        $this->assertTrue($mapped['production_funds_satisfied']);
        $this->assertTrue($mapped['shipment_funds_satisfied']);
    }

    public function test_refund_and_reversal_refresh_projection_and_all_three_shipment_points_recheck_gate(): void
    {
        $order = $this->order(['policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true]);
        $this->receipt($order, '100.0000');
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('passed', $order->fresh()->shipment_funding_status);

        $refund = $this->refund($order, '10.0000');
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        $this->assertSame('blocked', $order->fresh()->shipment_funding_status);
        $this->assertDomainBlocked(fn () => app(SalesShipmentApplicationService::class)
            ->create($order->id, ['lines' => []], '资金门禁测试'));

        app(FinanceAllocationApplicationService::class)->reverse($refund->id, '恢复创建发货测试', 1, '测试管理员');
        $this->assertSame('passed', $order->fresh()->shipment_funding_status);
        $draft = SalesShipment::create([
            'shipment_no' => 'SHP-FUND-DRAFT-'.Str::upper(Str::random(8)),
            'sales_order_id' => $order->id, 'shipment_status' => 'draft',
        ]);
        $refund = $this->refund($order, '10.0000');
        $this->assertDomainBlocked(fn () => app(SalesShipmentApplicationService::class)->confirm($draft, '资金门禁测试'));

        app(FinanceAllocationApplicationService::class)->reverse($refund->id, '恢复出库测试', 1, '测试管理员');
        $pending = SalesShipment::create([
            'shipment_no' => 'SHP-FUND-POST-'.Str::upper(Str::random(8)),
            'sales_order_id' => $order->id, 'shipment_status' => 'pending_outbound',
        ]);
        $this->refund($order, '10.0000');
        $this->assertDomainBlocked(fn () => app(SalesShipmentApplicationService::class)->postOutbound($pending, '资金门禁测试'));
    }

    public function test_payment_method_is_one_configurable_master_for_sales_receipts_and_payments(): void
    {
        $service = app(PaymentMethodApplicationService::class);
        $method = $service->create([
            'method_code' => 'TEST_SHARED_'.Str::upper(Str::random(6)),
            'method_name' => '销售财务共用测试方式',
            'available_for_sales' => true,
            'available_for_receipt' => true,
            'available_for_payment' => true,
            'status' => 'enabled',
            'sort' => 70,
        ], 1);
        $this->assertSame($method->id, $service->resolveForSales($method->method_code)->id);
        $this->assertSame($method->id, $service->resolveForFinance($method->id, 'receipt')->id);
        $this->assertSame($method->id, $service->resolveForFinance($method->id, 'payment')->id);
        $snapshot = $service->snapshot($method);

        $policy = SalesFundingPolicy::create([
            'policy_code' => 'FREEZE_'.Str::upper(Str::random(6)), 'policy_name' => '确认冻结测试策略',
            'policy_type' => 'deposit_production', 'production_threshold_type' => 'ratio',
            'production_threshold_value' => '0.25', 'shipment_requires_full_payment' => true, 'status' => 'enabled',
        ]);
        $order = $this->order(null);
        $order->update(['payment_method_id' => $method->id, 'funding_policy_id' => $policy->id]);
        $frozen = app(SalesOrderSnapshotService::class)->freezeFinancialTermsForConfirmation($order);
        $policy->update(['production_threshold_value' => '0.9']);
        $method->update(['method_name' => '主数据后续改名']);
        $this->assertSame('0.250000', (string) data_get($frozen->funding_policy_snapshot, 'production_threshold_value'));
        $this->assertSame('销售财务共用测试方式', data_get($frozen->payment_method_snapshot, 'method_name'));

        $service->setStatus($method->id, 'disabled', $method->fresh()->business_version, 1);
        $this->assertSame('销售财务共用测试方式', $snapshot['method_name']);
        $this->expectException(ValidationException::class);
        $service->resolveForSales($method->id);
    }

    public function test_amount_permission_redacts_order_and_funding_amounts_but_keeps_gate_decisions(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $suffix = Str::upper(Str::random(8));
        $userId = random_int(700000, 799999);
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $userId, 'username' => 'funding-'.$suffix, 'nickname' => '资金状态只读员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'funding_readonly_'.strtolower($suffix), 'name' => '资金状态只读角色',
            'data_scope' => 'self', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $userId, 'role_id' => $roleId]);
        $viewId = DB::table('erp_rbac_permissions')->where('code', 'sales_order.view')->value('id');
        DB::table('erp_rbac_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $viewId]);
        $order = $this->order([
            'policy_type' => 'deposit_production', 'production_threshold_type' => 'ratio',
            'production_threshold_value' => '0.3', 'shipment_requires_full_payment' => true,
        ], $userId);
        $token = Str::random(48);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $userId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/erp/sales/orders/'.$order->id)->assertOk();
        $response->assertJsonPath('funding_status.production_funds_satisfied', false)
            ->assertJsonPath('funding_status.production_block_reason', 'production_funds_insufficient')
            ->assertJsonMissingPath('funding_status.net_received_amount')
            ->assertJsonMissingPath('funding_status.production_required_amount')
            ->assertJsonMissingPath('total_amount')
            ->assertJsonMissingPath('final_receivable_amount');
    }

    public function test_financial_master_writes_require_current_version_and_do_not_enable_credit_shipping(): void
    {
        $policies = app(SalesFundingPolicyApplicationService::class);
        $policy = $policies->create([
            'policy_code' => 'VERSION_'.Str::upper(Str::random(6)),
            'policy_name' => '版本测试定金策略', 'policy_type' => 'deposit_production',
            'production_threshold_type' => 'ratio', 'production_threshold_value' => '0.3',
            'shipment_requires_full_payment' => true, 'status' => 'enabled',
        ], 1);
        $updated = $policies->update($policy->id, [
            'policy_name' => '版本测试定金策略二版', 'production_threshold_value' => '0.4',
            'expected_version' => 1,
        ], 1);
        $this->assertSame(2, $updated->business_version);
        $this->assertSame('0.400000', (string) $updated->production_threshold_value);

        try {
            $policies->update($policy->id, ['policy_name' => '过期覆盖', 'expected_version' => 1], 1);
            $this->fail('过期版本不得覆盖付款策略。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors());
        }

        try {
            $policies->create([
                'policy_code' => 'CREDIT_'.Str::upper(Str::random(6)),
                'policy_name' => '未启用的授信策略', 'policy_type' => 'custom_threshold',
                'production_threshold_type' => 'amount', 'production_threshold_value' => '0',
                'shipment_requires_full_payment' => false, 'status' => 'enabled',
            ], 1);
            $this->fail('授信未启用时不得建立非全额发货策略。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipment_requires_full_payment', $exception->errors());
        }
    }

    private function order(?array $policy, ?int $ownerId = null): SalesOrder
    {
        $customer = SalesCustomer::create([
            'customer_code' => 'C-FUND-'.Str::upper(Str::random(8)),
            'customer_name' => '资金门禁测试客户', 'status' => 'enabled',
        ]);
        return SalesOrder::create([
            'sales_order_no' => 'SO-FUND-'.Str::upper(Str::random(10)),
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'customer_name_snapshot' => $customer->customer_name,
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'shipment_status' => 'not_shipped', 'total_amount' => '100.0000',
            'final_receivable_amount' => '100.0000', 'currency' => 'CNY',
            'funding_policy_snapshot' => $policy,
            'sales_user_legacy_id' => $ownerId, 'created_by_legacy_id' => $ownerId,
        ]);
    }

    private function receipt(SalesOrder $order, string $amount): FinanceAllocation
    {
        return $this->allocate($order, $amount, FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::SOURCE_SALES_ORDER);
    }

    private function refund(SalesOrder $order, string $amount): FinanceAllocation
    {
        return $this->allocate($order, $amount, FinanceConstants::DIRECTION_PAYMENT, FinanceConstants::SOURCE_SALES_ORDER_REFUND);
    }

    private function allocate(SalesOrder $order, string $amount, string $direction, string $source): FinanceAllocation
    {
        $account = FinanceAccount::firstOrCreate(
            ['account_no' => 'FAC-P6B1-FUND'],
            ['account_name' => '资金门禁测试账户', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']
        );
        $document = FinanceCashDocument::create([
            'direction' => $direction, 'document_no' => ($direction === 'receipt' ? 'FR-' : 'FP-').Str::upper(Str::random(12)),
            'party_type' => FinanceConstants::PARTY_CUSTOMER, 'party_id' => $order->customer_id,
            'party_name_snapshot' => $order->customer_name_snapshot, 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => $amount,
            'payment_method' => 'BANK_TRANSFER', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);
        return app(FinanceAllocationApplicationService::class)->allocate($document->id, [[
            'source_business_type' => $source, 'source_document_id' => $order->id,
            'allocated_amount' => $amount, 'idempotency_key' => Str::uuid()->toString(),
        ]], 1, '测试管理员')['allocations'][0];
    }

    private function assertDomainBlocked(callable $operation): void
    {
        try {
            $operation();
            $this->fail('资金门禁未满足时必须阻断该发货动作。');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('不能发货', $exception->getMessage());
        }
    }
}
