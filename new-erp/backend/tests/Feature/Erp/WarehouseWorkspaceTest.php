<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\WarehouseWorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WarehouseWorkspaceTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_all_sources_are_scoped_before_union_pagination_and_have_separate_units(): void
    {
        $f = $this->fixture();
        $permissions = array_merge(self::PERMISSIONS, ['inventory.post.view', 'production.task.view', 'sales_return.view',
            'production.material_picking.view', 'sales_order.shipment.view', 'purchase_return.view', 'production.material_delivery.view']);
        $service = app(WarehouseWorkspaceService::class);
        foreach (['pending', 'completed'] as $status) {
            $page = $service->paginate(['status_group' => $status, 'per_page' => 1], $f['user'], $permissions, true);
            $this->assertLessThanOrEqual(1, count($page['data']));
            $this->assertSame(1, $page['meta']['per_page']);
            $this->assertCount(9, $page['types']);
            foreach ($page['data'] as $row) if ((int) $row['item_count'] > 1 && $row['kind'] !== 'remnant') $this->assertNull($row['quantity']);
        }
        $summary = $service->summary($f['user'], $permissions, true);
        $this->assertIsInt($summary['today_inbound']); $this->assertIsInt($summary['today_outbound']);
        $this->assertTrue($summary['can_delivery']);
        $this->assertSame([], $service->paginate(['direction' => 'outbound'], $f['user'], ['inventory.post.view'], false)['data']);
    }

    public function test_search_and_kind_filter_do_not_invent_access_or_totals(): void
    {
        $f = $this->fixture(); $service = app(WarehouseWorkspaceService::class);
        $permissions = array_merge(self::PERMISSIONS, ['inventory.post.view', 'production.task.view', 'sales_return.view',
            'production.material_picking.view', 'sales_order.shipment.view', 'purchase_return.view']);
        foreach (['pending', 'completed'] as $status) {
            $page = $service->paginate(['status_group' => $status, 'keyword' => 'no-such-warehouse-document-'.uniqid()], $f['user'], $permissions, true);
            $this->assertSame(0, $page['meta']['total']); $this->assertSame([], $page['data']);
        }
        $page = $service->paginate(['kind' => 'sales_shipment'], $f['user'], ['inventory.post.view'], false);
        $this->assertSame(0, $page['meta']['total']);
        $this->expectException(WorkOrderDomainException::class);
        $service->paginate([], $f['user'], [], false);
    }
}
