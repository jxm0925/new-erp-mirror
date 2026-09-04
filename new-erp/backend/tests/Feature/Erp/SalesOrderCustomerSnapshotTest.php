<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\SalesChannel;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesFundingPolicy;
use App\Services\Erp\SalesOrderSnapshotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesOrderCustomerSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_identity_creates_then_reuses_one_customer_and_refreshes_master_data(): void
    {
        $this->ensureChannelFoundation();
        $service = app(SalesOrderSnapshotService::class);

        $first = $service->lock([
            'customer_name' => '平台买家初始昵称',
            'customer_kind' => 'individual',
            'platform' => '线上平台',
            'platform2' => '店铺A',
            'platform_buyer_id' => 'BUYER-UNIQUE-1001',
            'contact_name' => '张三',
            'contact_phone' => '17000000001',
            'full_address' => '山东省青岛市市南区测试路 1 号',
            'carrier_id' => null,
        ]);
        $customerId = (int) $first['customer_id'];
        $this->assertDatabaseHas('erp_sales_customers', [
            'id' => $customerId,
            'platform_buyer_id' => 'BUYER-UNIQUE-1001',
            'contact_phone' => '17000000001',
        ]);
        $this->assertNotEmpty($first['customer_contact_id']);
        $this->assertNotEmpty($first['customer_address_id']);

        // A later platform order uses a changed nickname/virtual phone. The
        // buyer identity, rather than the display name or phone, dedupes it.
        $second = $service->lock([
            'customer_name' => '平台买家更新昵称',
            'customer_kind' => 'individual',
            'platform' => '线上平台',
            'platform2' => '店铺A',
            'platform_buyer_id' => 'BUYER-UNIQUE-1001',
            'contact_name' => '李四',
            'contact_phone' => '17000000002',
            'full_address' => '山东省青岛市崂山区测试路 2 号',
            'carrier_id' => null,
        ]);

        $this->assertSame($customerId, (int) $second['customer_id']);
        $this->assertSame(1, SalesCustomer::query()->where('platform_buyer_id', 'BUYER-UNIQUE-1001')->count());
        $this->assertDatabaseHas('erp_sales_customers', [
            'id' => $customerId,
            'customer_name' => '平台买家更新昵称',
            'contact_phone' => '17000000002',
            'full_address' => '山东省青岛市崂山区测试路 2 号',
        ]);
    }

    private function ensureChannelFoundation(): void
    {
        $policy = SalesFundingPolicy::query()->firstOrCreate(
            ['policy_code' => 'TEST-CUSTOMER-SNAPSHOT'],
            [
                'policy_name' => '测试资金策略',
                'policy_type' => 'full_prepay',
                'production_threshold_type' => 'ratio',
                'production_threshold_value' => 1,
                'shipment_requires_full_payment' => true,
                'status' => 'enabled',
            ]
        );
        SalesChannel::query()->update(['is_default' => false]);
        SalesChannel::query()->firstOrCreate(
            ['channel_code' => 'TEST-CUSTOMER-SNAPSHOT'],
            [
                'channel_name' => '测试直销',
                'channel_type' => 'offline_direct',
                'transaction_mode' => 'cash_sale',
                'default_funding_policy_code' => $policy->policy_code,
                'requires_external_order_no' => false,
                'is_default' => true,
                'status' => 'enabled',
                'sort' => 1,
            ]
        );
    }
}
