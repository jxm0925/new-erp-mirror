<?php

namespace App\Services\Erp;

use App\Models\Erp\{CuttingTask, Item, WorkOrder};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Worker-origin records have no demand reservation and no dispatcher/publish step. */
final class CuttingWorkerOrderService
{
    public function __construct(private readonly CuttingCommandService $commands,
        private readonly DocumentNumberService $numbers, private readonly ProductionDataScopeResolver $scopes) {}

    public function create(array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.record');
        $c->permission($permissions, 'production.cutting.issue');
        return $c->run('create_worker_cutting_order', 0, $payload, $user, function () use ($c, $payload, $user, $permissions, $super): array {
            if (($payload['expected_version'] ?? null) !== 0) $c->fail('version_required', '新建下料记录版本必须为0。');
            $inputs = $payload['inputs'] ?? null;
            if (! is_array($inputs) || ! array_is_list($inputs) || count($inputs) < 1 || count($inputs) > 100)
                $c->fail('inputs_required', '请先选择本次实际使用的钢板或方管。');
            $seen = [];
            foreach ($inputs as $input) {
                if (! is_array($input) || array_diff(array_keys($input), ['physical_material_id','inventory_balance_id','remnant_holding_id','input_qty']))
                    $c->fail('input_fields_invalid', '用料字段不合法，新建时不能预选产出。');
                $sources = array_intersect(['physical_material_id','inventory_balance_id','remnant_holding_id'], array_keys($input));
                if (count($sources) !== 1) $c->fail('input_source_invalid','每条用料必须选择唯一的实际来源。');
                $field = array_values($sources)[0]; $sourceId = filter_var($input[$field],FILTER_VALIDATE_INT);
                if (! $sourceId || $sourceId < 1) $c->fail('input_source_invalid','用料来源编号不合法。');
                $key = $field.':'.$sourceId;
                if (isset($seen[$key])) $c->fail('input_duplicate','同一份用料不能重复选择。');
                $seen[$key] = true;
            }
            $actor = $c->actor($user); $now = now();
            // Creation confirms actual material issue, atomically with the order.
            // Products are selected only later while recording this source's results.
            $id = DB::table('erp_cutting_orders')->insertGetId(['cutting_order_no' => $this->numbers->next('cutting_order', 'CUT'),
                'purpose' => 'WORKER', 'status' => 'IN_PROGRESS', 'business_version' => 1,
                'responsible_user_legacy_id' => $actor, 'created_by_legacy_id' => $actor,
                'published_at' => null, 'created_at' => $now, 'updated_at' => $now]);
            $task = CuttingTask::create(['cutting_order_id' => $id, 'task_no' => $this->numbers->next('cutting_task', 'CT'),
                'status' => 'READY', 'assignee_user_legacy_id' => $actor, 'claimed_at' => $now, 'business_version' => 1]);
            $task->participants()->create(['employee_legacy_id' => $actor, 'role' => 'owner', 'responsibility_weight' => 1,
                'joined_at' => $now, 'active_participant_key' => $task->id.':'.$actor, 'business_version' => 1]);
            $version = 1; $batches = []; $inputService = app(CuttingInputService::class);
            $prefix = 'worker-input-'.hash('sha256',$payload['client_command_id']);
            foreach ($inputs as $index => $input) {
                if (isset($input['physical_material_id'])) {
                    if (isset($input['input_qty'])) $c->fail('physical_quantity_forbidden','钢板按具体实物领用，不能填写数量。');
                    $reserved = $inputService->reserve($id,['client_command_id'=>$prefix.'-r'.$index,'expected_version'=>$version,
                        'physical_material_ids'=>[$input['physical_material_id']]],$user,$permissions,$super);
                    $version = $reserved['business_version'];
                }
                $batch = $inputService->issue($id,['client_command_id'=>$prefix.'-i'.$index,'expected_version'=>$version]+$input,$user,$permissions,$super);
                $version = $batch['order_business_version'];
                $batches[] = ['settlement_batch_id'=>$batch['settlement_batch_id'],'business_version'=>$batch['business_version']];
            }
            $response = ['cutting_order_id' => $id, 'cutting_task_id' => $task->id, 'business_version' => $version, 'batches'=>$batches,
                'task_business_version' => 1, 'purpose' => 'WORKER', 'status' => 'IN_PROGRESS'];
            $c->event('order', $id, 'worker_create', $user, null, $response + ['inputs' => $inputs]);
            return $response;
        });
    }

    /** Freeze a real output only when the worker records it, never at order creation. */
    public function resolveOutput(object $batch, array $row, object $user, array $permissions, bool $super): int
    {
        $order = DB::table('erp_cutting_orders')->where('id',$batch->cutting_order_id)->lockForUpdate()->first();
        if ($order->purpose !== 'WORKER') $this->commands->fail('output_source_invalid','计划下料必须使用已冻结的正式产出。');
        $item = Item::query()->whereKey((int) ($row['item_id'] ?? 0))->lockForUpdate()->first();
        $lengthMaterial = $item && $item->item_type === 'raw_material' && $item->materialManagementMode() === 'quantity' && $item->cuttingMode() === 'length';
        if (! $item || $item->status !== 'enabled' || (! $item->is_production_item && ! $lengthMaterial) || ! $item->is_stock_item)
            $this->commands->fail('output_item_invalid','请选择已启用、可生产且可入库的产品物料。');
        $configId = isset($row['configuration_id']) ? (int) $row['configuration_id'] : null;
        $config = $this->configuration($item,$configId,$user,$permissions,$super);
        $existing = DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$order->id)
            ->where('item_id',$item->id)->where('configuration_id',$configId)->whereNull('plan_id')->lockForUpdate()->first();
        if ($existing) return (int) $existing->id;
        return DB::table('erp_cutting_allowed_outputs')->insertGetId(['cutting_order_id'=>$order->id,'item_id'=>$item->id,
            'configuration_id'=>$configId,'plan_id'=>null,'stage_id'=>null,'quality_mode'=>'none','output_mode'=>'stockable','work_mode'=>'manual',
            'rule_snapshot'=>json_encode(['origin'=>'WORKER','item_code'=>$item->item_code,'item_name'=>$item->item_name,
                'configuration_version'=>$config?->version_no],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
    }

    public function configuration(Item $item, ?int $id, object $user, array $permissions, bool $super): ?object
    {
        if (! $id) {
            if ($item->is_custom_item) $this->commands->fail('configuration_required', '定制物料必须选择已发布的配置版本。');
            return null;
        }
        $config = DB::table('erp_custom_configurations')->where('id', $id)->where('item_id', $item->id)->where('status', 'PUBLISHED')->first();
        if (! $config) $this->commands->fail('configuration_invalid', '配置版本未发布或不属于该物料。');
        if ($config->scope_mode !== 'PUBLIC') {
            $visible = WorkOrder::query()->select('id');
            $this->scopes->applyWorkOrderScope($visible, $this->scopes->resolve($user, 'production.cutting.record', $permissions, $super));
            if (! DB::table('erp_custom_configuration_scopes')->where('configuration_id', $id)->where('source_type', 'work_order')
                ->whereIn('source_id', $visible->toBase())->exists())
                $this->commands->fail('configuration_scope_denied', '该配置不在当前可操作范围内。', 403);
        }
        return $config;
    }

    public function outputs(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.record');
        $q = $this->outputQuery($user,$permissions,$super);
        if (! empty($filters['category_id'])) $q->where('i.category_id', (int) $filters['category_id']);
        if (! empty($filters['keyword'])) {
            $keyword = '%'.$filters['keyword'].'%';
            $q->where(fn (Builder $w) => $w->where('i.item_code','like',$keyword)->orWhere('i.item_name','like',$keyword)->orWhere('i.spec','like',$keyword));
        }
        $page = $q->select('i.id','i.item_code','i.item_name','i.spec','i.category_id','i.unit_id','i.is_custom_item',
                'c.id as configuration_id','c.configuration_no','c.version_no as configuration_version','c.drawing_reference','c.dimensions as configuration_dimensions')
            ->orderBy('i.id')->orderBy('c.id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function categories(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions,'production.cutting.record');
        $q = DB::table('erp_item_categories as category')->where('category.status','enabled')->whereIn('category.id',
            $this->outputQuery($user,$permissions,$super)->select('i.category_id'));
        $page = $q->select('category.id','category.category_name')->orderBy('category.sort_order')->orderBy('category.id')
            ->paginate(min(100,max(1,(int) ($filters['per_page'] ?? 20))),['*'],'page',max(1,(int) ($filters['page'] ?? 1)));
        return ['data'=>$page->items(),'meta'=>['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total(),'per_page'=>$page->perPage()]];
    }

    private function outputQuery(object $user, array $permissions, bool $super): Builder
    {
        $visible = WorkOrder::query()->select('id');
        $this->scopes->applyWorkOrderScope($visible,$this->scopes->resolve($user,'production.cutting.record',$permissions,$super));
        return DB::table('erp_items as i')->where('i.status','enabled')->where('i.is_stock_item',true)
            ->where(fn (Builder $q) => $q->where('i.is_production_item',true)->orWhere(fn (Builder $raw) => $raw
                ->where('i.item_type','raw_material')->where('i.material_management_mode','quantity')
                ->where(fn (Builder $length) => $length->where('i.cutting_mode','length')->orWhere(fn (Builder $legacy) => $legacy
                    ->whereNull('i.cutting_mode')->where('i.is_length_cut_material',true)))))
            ->leftJoin('erp_custom_configurations as c',fn ($join) => $join->on('c.item_id','=','i.id')->where('i.is_custom_item',true)->where('c.status','PUBLISHED'))
            ->where(fn (Builder $q) => $q->where('i.is_custom_item',false)->orWhere(fn (Builder $custom) => $custom->whereNotNull('c.id')
                ->where(fn (Builder $scope) => $scope->where('c.scope_mode','PUBLIC')->orWhereExists(fn (Builder $s) => $s->selectRaw('1')
                    ->from('erp_custom_configuration_scopes as cs')->whereColumn('cs.configuration_id','c.id')->where('cs.source_type','work_order')
                    ->whereIn('cs.source_id',$visible->toBase())))));
    }
}
