<?php

namespace App\Services\Erp;

use App\Models\Erp\WorkOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CuttingReadService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly ProductionDataScopeResolver $scopes) {}

    public function orders(array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        $visible = WorkOrder::query()->select('id');
        $this->scopes->applyWorkOrderScope($visible, $this->scopes->resolve($user, 'production.cutting.view', $permissions, $super));
        $q = DB::table('erp_cutting_orders as o')->whereExists(fn (Builder $p) => $p->selectRaw('1')->from('erp_cutting_plan_allocations as a')->whereColumn('a.cutting_order_id','o.id'))
            ->whereNotExists(fn (Builder $p) => $p->selectRaw('1')->from('erp_cutting_plan_allocations as a')->whereColumn('a.cutting_order_id','o.id')->whereNotIn('a.work_order_id',$visible->toBase()))
            ->whereNotExists(fn (Builder $p) => $p->selectRaw('1')->from('erp_cutting_plan_allocations as a')->join('erp_production_target_material_requirements as r','r.id','=','a.target_material_requirement_id')
                ->whereColumn('a.cutting_order_id','o.id')->whereNotIn('r.work_order_id',$visible->toBase()));
        if (! empty($f['status'])) $q->where('o.status',$f['status']);
        if (! empty($f['keyword'])) $q->where('o.cutting_order_no','like','%'.$f['keyword'].'%');
        return $this->page($q->orderByDesc('o.id'),$f);
    }

    public function execution(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $order = $this->commands->order($id,$user,$permissions,$super,'production.cutting.view');
        $inputs = $this->page(DB::table('erp_cutting_settlement_batches as b')->leftJoin('erp_material_physicals as p','p.id','=','b.physical_material_id')
            ->join('erp_items as i','i.id','=','b.input_item_id')->where('b.cutting_order_id',$id)
            ->select($this->sourceColumns())->orderBy('b.id'),$f);
        return ['order'=>(array) $order,'task'=>(array) DB::table('erp_cutting_tasks')->where('cutting_order_id',$id)->first(),
            'inputs'=>$inputs,'results'=>$this->results($id,$f),'page_title'=>'下料记录','submit_label'=>'提交加工结果',
            'page_scope'=>'ORDER_OVERVIEW'];
    }

    public function settlementExecution(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $batch = $this->commands->assertBatchVisible($id,$user,$permissions,$super,'production.cutting.view');
        $source = DB::table('erp_cutting_settlement_batches as b')->leftJoin('erp_material_physicals as p','p.id','=','b.physical_material_id')
            ->join('erp_items as i','i.id','=','b.input_item_id')->where('b.id',$id)
            ->select($this->sourceColumns())->first();
        // The URL, not a query/body source ID, owns the source and every result below.
        return ['order'=>(array) DB::table('erp_cutting_orders')->where('id',$batch->cutting_order_id)->first(),
            'source'=>(array) $source,'results'=>$this->results((int) $batch->cutting_order_id,$f,$id),
            'page_title'=>'下料记录','submit_label'=>'提交加工结果','page_scope'=>'SETTLEMENT_BATCH',
            'source_locked'=>true,'can_add_input'=>false];
    }

    private function results(int $orderId, array $f, ?int $batchId = null): array
    {
        // Never GROUP BY Item/configuration/stage: one result ID retains one source.
        $q = DB::table('erp_cutting_results as r')->join('erp_cutting_settlement_batches as b','b.id','=','r.settlement_batch_id')
            ->leftJoin('erp_items as i','i.id','=','r.item_id')->leftJoin('erp_material_physicals as p','p.id','=','b.physical_material_id')
            ->where('b.cutting_order_id',$orderId)->whereNotIn('r.status',['VOIDED','SUPERSEDED'])
            ->select('r.id','r.settlement_batch_id','r.client_row_id','r.result_type','r.allowed_output_id','r.item_id','r.configuration_id','r.stage_id',
                'r.actual_qty','r.piece_qty','r.cut_length_mm','r.measurements','r.measurement_status','r.quality_status','r.reported_quality',
                'r.status','r.material_lot_id','r.physical_material_id','r.business_version','r.created_at','r.updated_at',
                'b.batch_no','b.physical_material_id as input_physical_material_id','p.physical_no as input_physical_no','i.item_code','i.item_name')->orderBy('r.id');
        if ($batchId !== null) $q->where('b.id',$batchId);
        $results = $this->page($q,$f);
        $ids = array_column($results['data'],'id');
        $routes = DB::table('erp_cutting_result_routes')->whereIn('result_id',$ids)->where('status','!=','CANCELLED')->orderBy('id')->get()->groupBy('result_id');
        foreach ($results['data'] as &$row) {
            $row['routes'] = $routes->get($row['id'],collect())->map(fn ($route) => [
                'id'=>$route->id,'result_id'=>$route->result_id,'route_type'=>$route->route_type,
                'target_material_requirement_id'=>$route->target_material_requirement_id,'quantity'=>$route->quantity,
                'received_qty'=>$route->received_qty,'status'=>$route->status,'business_version'=>$route->business_version,
            ])->all();
            foreach ($row['routes'] as &$route) $route['display_status'] = $route['status'] === 'PLANNED'
                ? ($route['route_type'] === 'WAREHOUSE' ? '待入库确认' : '待交接') : $route['status'];
            unset($route);
        }
        unset($row);
        return $results;
    }

    private function sourceColumns(): array
    {
        // Mobile execution projection deliberately excludes costs, holdings and transaction internals.
        return ['b.id','b.batch_no','b.cutting_order_id','b.cutting_task_id','b.input_item_id','b.physical_material_id',
            'b.input_qty','b.standard_stock_length_mm','b.status','b.business_version','b.first_cut_at','b.submitted_at','b.confirmed_at',
            'p.physical_no','p.material_form','p.shape','p.dimensions','i.item_code','i.item_name','i.spec'];
    }

    public function allowedOutputs(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($id,$user,$permissions,$super,'production.cutting.view');
        $q = DB::table('erp_cutting_allowed_outputs as a')->join('erp_items as i','i.id','=','a.item_id')
            ->leftJoin('erp_custom_configurations as c','c.id','=','a.configuration_id')->where('a.cutting_order_id',$id)->where('i.status','enabled')
            ->where(fn (Builder $q) => $q->whereNull('a.configuration_id')->orWhere('c.status','PUBLISHED'));
        $this->itemFilter($q,$f);
        return $this->page($q->select('a.*','i.item_code','i.item_name','i.spec','i.category_id','c.configuration_no','c.version_no as configuration_version','c.drawing_reference')->orderBy('a.id'),$f);
    }

    public function inputCandidates(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($id,$user,$permissions,$super,'production.cutting.view');
        $itemIds = app(CuttingMaterialEligibilityService::class)->plans($id)->select('r.component_item_id');
        if (($f['input_type'] ?? 'physical') === 'physical') {
            $q = DB::table('erp_material_physicals as p')->join('erp_items as i','i.id','=','p.item_id')
                ->join('erp_material_holdings as h','h.id','=','p.current_holding_id')->whereIn('p.item_id',$itemIds)->where('p.status','AVAILABLE')->where('h.status','ACTIVE')
                ->where('h.position_type','WAREHOUSE');
            if (! empty($f['material_form'])) $q->where('p.material_form',$f['material_form']);
            if (! empty($f['shape'])) $q->where('p.shape',$f['shape']);
            $this->itemFilter($q,$f,'p.physical_no');
            return $this->page($q->select('p.*','i.item_code','i.item_name','i.spec','i.category_id','h.position_type','h.position_id')->orderBy('p.id'),$f);
        }
        $q = DB::table('erp_inventory_balances as b')->join('erp_items as i','i.id','=','b.item_id')
            ->leftJoin('erp_material_lots as l','l.id','=','b.material_lot_id')->whereNull('l.configuration_id')->whereNull('l.stage_id')->whereIn('b.item_id',$itemIds)
            ->where('b.quantity_available','>',0)->where('i.material_management_mode','quantity')
            ->where(fn (Builder $q) => $q->where('i.cutting_mode','length')->orWhere(fn (Builder $q) => $q->whereNull('i.cutting_mode')->where('i.is_length_cut_material',true)));
        $this->itemFilter($q,$f,'b.batch_no');
        return $this->page($q->select('b.id as inventory_balance_id','b.item_id','b.batch_no','b.quantity_available as available_root_qty',
            'i.item_code','i.item_name','i.spec','i.category_id','i.standard_stock_length_mm')->orderBy('b.id'),$f);
    }

    private function itemFilter(Builder $q, array $f, ?string $extra = null): void
    {
        if (! empty($f['category_id'])) $q->where('i.category_id',(int) $f['category_id']);
        if (! empty($f['keyword'])) $q->where(function (Builder $q) use ($f,$extra): void {
            $keyword = '%'.$f['keyword'].'%'; $q->where('i.item_code','like',$keyword)->orWhere('i.item_name','like',$keyword)->orWhere('i.spec','like',$keyword);
            if ($extra) $q->orWhere($extra,'like',$keyword);
        });
    }

    private function page(Builder $q, array $f): array
    {
        $page = filter_var($f['page'] ?? 1,FILTER_VALIDATE_INT); $size = filter_var($f['per_page'] ?? 20,FILTER_VALIDATE_INT);
        if (! $page || $page < 1 || ! $size || $size < 1 || $size > 100) $this->commands->fail('pagination_invalid','分页参数不合法，每页最多100条。');
        $result = $q->paginate($size,['*'],'page',$page);
        return ['data'=>array_map(fn ($r) => (array) $r,$result->items()),'meta'=>['current_page'=>$page,'per_page'=>$size,'total'=>$result->total(),'last_page'=>$result->lastPage()]];
    }
}
