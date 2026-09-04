<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\ApprovalBusinessObject;
use App\Services\Erp\ApprovalRegistryApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ApprovalRegistryApplicationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_empty_registry_can_register_sales_order_change_from_real_mysql_metadata(): void
    {
        ApprovalBusinessObject::query()->where('object_code', 'SALES_ORDER_CHANGE')->delete();

        $service = app(ApprovalRegistryApplicationService::class);
        $candidate = $service->candidate('erp_sales_order_change_candidates');

        $this->assertSame('sales_order_change', $candidate['adapter']['key']);
        $this->assertContains('candidate_no', collect($candidate['fields'])->pluck('field_code')->all());
        $this->assertNotContains('legacy_payload', collect($candidate['fields'])->pluck('field_code')->all());

        $defaults = $candidate['defaults'];
        $result = $service->register([
            'source_table' => 'erp_sales_order_change_candidates',
            'adapter_key' => 'sales_order_change',
            'object_code' => $defaults['object_code'],
            'object_name' => $defaults['object_name'],
            'business_module' => $defaults['business_module'],
            'primary_key' => $defaults['primary_key'],
            'route_pattern' => $defaults['route_pattern'],
            'view_permission_code' => null,
            'fields' => $candidate['fields'],
            'event' => [
                'event_code' => $defaults['event_code'],
                'event_name' => $defaults['event_name'],
                'manual_start_allowed' => $defaults['manual_start_allowed'],
                'event_trigger_allowed' => $defaults['event_trigger_allowed'],
            ],
        ], 'MySQL QA');

        $this->assertSame('SALES_ORDER_CHANGE', $result['object']['code']);
        $this->assertSame('erp_sales_order_change_candidates', $result['object']['table']);
        $this->assertSame('submit_change', $result['object']['events'][0]['key']);
        $this->assertDatabaseHas('erp_approval_business_actions', [
            'action_code' => 'sales_order_change.decide',
            'result_event' => 'node_decision',
        ]);
        $this->assertDatabaseHas('erp_approval_business_actions', ['action_code' => 'approval.complete']);
        $this->assertDatabaseHas('erp_approval_business_actions', ['action_code' => 'approval.reject']);
    }
}
