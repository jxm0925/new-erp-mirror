<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\RbacBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MwoInterfaceAcceptanceCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_local_acceptance_fixture_is_service_generated_and_idempotent(): void
    {
        Storage::fake('local');
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => 889001,
            'username' => 'mwo-interface-command',
            'nickname' => 'MWO 接口验收员',
            'status' => 'normal',
            'auth_group_names' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(RbacBootstrapService::class)->bootstrap();
        DB::table('erp_rbac_user_roles')->insert([
            'user_legacy_id' => 889001,
            'role_id' => DB::table('erp_rbac_roles')->where('code', 'production_operator')->value('id'),
        ]);

        $this->assertSame(0, Artisan::call('erp:seed-mwo-interface-acceptance', ['--user' => 889001]), Artisan::output());
        $order = DB::table('erp_sales_orders')->where('sales_order_no', 'MWO-INTERFACE-V1-SO')->first();
        $workOrder = DB::table('erp_work_orders')->where('source_type', 'sales_order')->where('source_id', $order->id)->first();
        $this->assertNotNull($workOrder);
        $this->assertSame('RELEASED', $workOrder->status);
        $this->assertSame(6, DB::table('erp_production_units')->where('work_order_id', $workOrder->id)->count());
        $this->assertSame(18, DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->count());
        $this->assertSame(1, DB::table('erp_sales_order_attachments')->where('sales_order_id', $order->id)->count());
        Storage::disk('local')->assertExists('acceptance/MWO-INTERFACE-V1/production-drawing.png');

        $this->assertSame(0, Artisan::call('erp:seed-mwo-interface-acceptance', ['--user' => 889001]), Artisan::output());
        $this->assertSame(1, DB::table('erp_sales_orders')->where('sales_order_no', 'MWO-INTERFACE-V1-SO')->count());
        $this->assertSame(1, DB::table('erp_work_orders')->where('source_type', 'sales_order')->where('source_id', $order->id)->count());
        $this->assertSame(6, DB::table('erp_production_units')->where('work_order_id', $workOrder->id)->count());
        $this->assertSame(18, DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->count());
    }
}
