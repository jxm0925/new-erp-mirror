<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\BusinessStaffService;
use App\Services\Erp\ProductionMediaService;
use App\Services\Erp\Manufacturing\MaterialReturnService;
use App\Services\Erp\Manufacturing\ReceiptService;
use App\Services\Erp\Manufacturing\TaskQueryService;
use App\Services\Erp\Manufacturing\TaskService;
use App\Services\Erp\Manufacturing\WorkOrderService;
use App\Models\Erp\MfgWorkOrderOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** 车间报工（小程序）、生产作业看板、报废确认、成品入库、工序“能做的人”。 */
class MfgTaskController extends Controller
{
    public function index(Request $request, TaskQueryService $query)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.task.view']);
        $data = $request->validate(['tab' => 'nullable|in:mine,available,done', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($query->tasks($user, $data['tab'] ?? 'mine', $data, $codes));
    }

    public function show(Request $request, int $id, TaskQueryService $query)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.task.view']);
        $lane = $request->validate(['lane' => 'nullable|in:normal,rework'])['lane'] ?? null;
        return response()->json(['data' => $query->task($id, $user, $codes, $lane)]);
    }

    public function action(Request $request, int $id, TaskService $tasks, TaskQueryService $query)
    {
        $input = $request->validate(['action' => 'required|in:claim,start,pause,stop,pass,prepare,reject,collaborators,assign,semi_order,repair_return',
            'lane' => 'nullable|in:normal,rework']);
        $action = $input['action'];
        $lane = TaskService::lane($input['lane'] ?? null);
        [$user, $codes] = $this->user($request, [in_array($action, ['assign', 'stop'], true) ? 'production.task.assign' : 'production.task.execute']);
        $reminders = [];
        $message = match ($action) {
            'claim' => $this->run(fn () => $tasks->claim($id, $user, $lane), $lane === 'rework' ? '已接下返工。' : '接单成功。'),
            'start' => $this->run(function () use ($tasks, $id, $user, $lane, $request, &$reminders) {
                $tasks->start($id, $user, $lane, $request->validate(['unit_ids' => 'sometimes|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct'])['unit_ids'] ?? null);
                // 开工已提交，提醒只读计算；出错不能把成功的开工报成失败。
                if ($lane === 'normal') {
                    try { $reminders = $tasks->childOrderReminders($id); } catch (\Throwable $e) { report($e); }
                }
            }, $lane === 'rework' ? '返工开始计时（返工工时单独记，不算提成）。' : '开始计时。'),
            'pause' => $this->run(fn () => $tasks->pause($id, $user), '已暂停计时。'),
            'stop' => $this->run(fn () => $tasks->stopTiming($id, (int) $request->validate(['employee_legacy_id' => 'required|integer|min:1'])['employee_legacy_id'], $user), '已停止这个人的计时。'),
            'assign' => $this->run(fn () => $tasks->assign($id, (int) $request->validate(['employee_legacy_id' => 'required|integer|min:1'])['employee_legacy_id'], $user, $lane), '已派工。'),
            'collaborators' => $this->collaborators($request, $id, $tasks, $user, $lane),
            'pass' => $this->pass($request, $id, $user, $lane),
            'prepare' => $this->prepare($request, $id, $user),
            'reject' => $this->reject($request, $id, $tasks, $user, $lane),
            'semi_order' => $this->semiOrder($request, $id, $user, $lane),
            'repair_return' => $this->run(fn () => app(\App\Services\Erp\ShipmentSerialApplicationService::class)->returnFromTask($id,
                $request->validate(['repair_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1',
                    'client_command_id' => 'required|string|max:100', 'finished_location_id' => 'required|integer|min:1',
                    'reason' => 'required|string|max:500']), $user), '返修合格已返回待发区，请在发货作业中重新试水。'),
        };
        return response()->json(['message' => $message, 'reminders' => $reminders, 'data' => $query->task($id, $user, $codes, $lane)]);
    }

    /** 待我审核（小程序）：报废申请、不良处置。工单生产负责人审自己工单的，有报废确认权限的都能审。 */
    public function reviews(Request $request, TaskQueryService $query)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.scrap.approve']);
        return response()->json(['data' => $query->reviews($user, $this->canApprove($user, $codes))]);
    }

    /** 审核员工提交的不良处置（返工 / 报废 / 判定可用）。 */
    public function reviewPlan(Request $request, int $id, \App\Services\Erp\WarehouseCommandService $commands)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.scrap.approve']);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'approve' => 'required|boolean', 'remark' => 'nullable|string|max:500']);
        $commandId = $data['client_command_id'];
        unset($data['client_command_id']);
        $result = $commands->run('material_return.plan_review', $id, $commandId, $data, $user, $codes, app(AuthContextService::class)->isSuperAdmin($user));
        return response()->json(['message' => $data['approve'] ? '处置方案已通过，待仓库确认入库后执行。' : '处置方案已驳回，料还在原工序。',
            'data' => $result['result']]);
    }

    public function people(Request $request, int $id, TaskQueryService $query)
    {
        $this->user($request, ['production.task.execute', 'production.task.assign']);
        $request->validate(['page' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120']);
        return response()->json($query->people($id, $request->query('keyword'), $request->integer('page', 1)));
    }

    public function photo(Request $request)
    {
        $this->user($request, ['production.task.execute']);
        $request->validate(['file' => 'required|image|max:10240']);
        $file = $request->file('file');
        $path = $file->storeAs('erp/production/photos/'.date('Ymd'), Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg'), 'public');
        return response()->json(['data' => ['url' => self::publicUrl($request, $path), 'path' => $path]]);
    }

    public function media(Request $request, ProductionMediaService $media)
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user || ! empty($user->deleted_at)) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $allowed = $auth->isSuperAdmin($user) || array_intersect(['production.scrap.approve', 'production.task.execute', 'sales_order.shipment.packing.execute',
            'sales_order.shipment.packing.quality'], $auth->permissionCodes($user));
        if (! $allowed && Schema::hasTable(BusinessStaffService::TABLE)) {
            // 业务身份独立于报工权限；只读识别，不能用会自动补档或缺表放行的 isActive。
            $workers = DB::table('erp_legacy_admin_users')->where('legacy_id', $user->legacy_id)->whereNull('deleted_at');
            app(BusinessStaffService::class)->applyActive($workers, 'production_worker');
            $allowed = $workers->exists();
        }
        if (! $allowed) throw new WorkOrderDomainException('permission_denied', '仅生产工人或有生产、发货作业权限的员工可以上传影像。', 403);
        $request->validate(['file' => 'required|file|max:102400']);
        return response()->json(['data' => $media->upload($request->file('file'), $user)]);
    }

    public function board(Request $request, TaskQueryService $query)
    {
        [, $codes] = $this->user($request, ['production.board', 'production.task.view']);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'owner_legacy_id' => 'nullable|integer|min:1',
            'urgent_only' => 'nullable|boolean', 'custom_only' => 'nullable|boolean']);
        $data = $query->board($filters);
        $data['permissions'] = ['assign' => in_array('production.task.assign', $codes, true), 'scrap' => in_array('production.scrap.approve', $codes, true),
            'receipt' => in_array('production.receipt.execute', $codes, true)];
        return response()->json(['data' => $data]);
    }

    public function reviewScrap(Request $request, int $id, TaskService $tasks)
    {
        [$user, $codes] = $this->user($request, ['production.scrap.approve', 'production.task.execute']);
        $data = $request->validate(['approve' => 'required|boolean', 'remark' => 'nullable|string|max:500']);
        $tasks->reviewScrap($id, (bool) $data['approve'], $data['remark'] ?? null, $user, $this->canApprove($user, $codes));
        return response()->json(['message' => $data['approve'] ? '已确认报废。' : '已驳回，继续返工。']);
    }

    public function receive(Request $request, int $id)
    {
        [$user] = $this->user($request, ['production.receipt.execute']);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct',
            'warehouse_id' => 'required|integer|exists:erp_warehouses,id', 'location_id' => 'required|integer|exists:erp_locations,id', 'remark' => 'nullable|string|max:500']);
        $result = $this->productionCommand('mfg_receipt.request', $id, $data, $user);
        return response()->json(['message' => '已提交 '.(int) ($result['total_requested_qty'] ?? $result['qty']).' '.$this->unitWord(null, $id).'，待仓库确认（'.$result['receipt_no'].'）。', 'data' => $result]);
    }

    public function receiptIndex(Request $request, ReceiptService $receipts)
    {
        [$user] = $this->user($request, ['inventory.inbound.confirm', 'production.receipt.execute', 'production.work_order.view', 'production.task.view', 'production.task.execute']);
        $filters = $request->validate(['status' => 'nullable|in:pending,confirmed,rejected,cancelled', 'keyword' => 'nullable|string|max:120',
            'work_order_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($receipts->paginate($filters, $user));
    }

    public function receiptShow(Request $request, int $id, ReceiptService $receipts)
    {
        [$user] = $this->user($request, ['inventory.inbound.confirm', 'production.receipt.execute', 'production.work_order.view', 'production.task.view', 'production.task.execute']);
        return response()->json(['data' => $receipts->show($id, $user)]);
    }

    public function confirmReceipt(Request $request, int $id)
    {
        return $this->receiptAction($request, $id, 'confirm');
    }

    public function rejectReceipt(Request $request, int $id)
    {
        return $this->receiptAction($request, $id, 'reject');
    }

    public function cancelReceipt(Request $request, int $id)
    {
        return $this->receiptAction($request, $id, 'cancel');
    }

    private function receiptAction(Request $request, int $id, string $action)
    {
        [$user, $codes] = $this->user($request, $action === 'cancel' ? ['production.receipt.execute', 'production.task.execute'] : ['inventory.inbound.confirm']);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1'] + match ($action) {
            'confirm' => ['warehouse_id' => 'nullable|integer|min:1', 'location_id' => 'required|integer|min:1',
                'serial_nos' => 'nullable|array|max:1000', 'serial_nos.*' => 'required|string|max:120|distinct', 'remark' => 'nullable|string|max:500'],
            'reject' => ['reason' => 'required|string|max:500'],
            default => [],
        });
        $commandId = $data['client_command_id'];
        unset($data['client_command_id']);
        $result = app(\App\Services\Erp\WarehouseCommandService::class)->run('mfg_receipt.'.$action, $id, $commandId, $data, $user, $codes,
            app(AuthContextService::class)->isSuperAdmin($user));
        return response()->json(['message' => match ($action) { 'confirm' => '入库已确认。', 'reject' => '已驳回，设备回到等入库。', default => '入库申请已撤回。' },
            'data' => $result['result']]);
    }

    public function workers(Request $request, int $id, TaskQueryService $query)
    {
        $this->user($request, ['production.operation.view']);
        return response()->json(['data' => $query->operationWorkers($id)]);
    }

    public function saveWorkers(Request $request, int $id, TaskService $tasks)
    {
        [$user] = $this->user($request, ['production.operation.edit']);
        $data = $request->validate(['employee_legacy_ids' => 'present|array|max:200', 'employee_legacy_ids.*' => 'integer|min:1']);
        return response()->json(['message' => '能做的人已保存。', 'data' => $tasks->saveWorkers($id, $data['employee_legacy_ids'], $user)]);
    }

    public static function publicUrl(Request $request, string $path): string
    {
        $url = Storage::disk('public')->url($path);
        return preg_match('#^https?://#i', $url)
            ? preg_replace('#^https?://[^/]+#i', $request->getSchemeAndHttpHost(), $url)
            : rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($url, '/');
    }

    private function collaborators(Request $request, int $id, TaskService $tasks, object $user, string $lane): string
    {
        $data = $request->validate(['collaborators' => 'present|array|max:20', 'collaborators.*.employee_legacy_id' => 'required|integer|min:1',
            'collaborators.*.share_percent' => 'nullable|numeric|min:0|max:100', 'owner_share_percent' => 'nullable|numeric|min:0|max:100']);
        $updateShares = array_key_exists('owner_share_percent', $data) || collect($data['collaborators'])->contains(fn ($row) => array_key_exists('share_percent', $row));
        $tasks->collaborators($id, $data['collaborators'], isset($data['owner_share_percent']) ? (float) $data['owner_share_percent'] : null, $user, $lane, $updateShares);
        return '协同人已保存。';
    }

    private function pass(Request $request, int $id, object $user, string $lane): string
    {
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct'] + $this->completionRules());
        $result = $this->productionCommand('mfg_task.pass', $id, $data + ['lane' => $lane], $user);
        $word = $this->unitWord($id);
        $paused = ! empty($result['timing_paused']) ? '本道暂时没有可做的'.($lane === 'rework' ? '返工' : '').'，计时已自动暂停。' : '';
        if (isset($result['completion_action'])) return $this->completionMessage($result, $word).$paused;
        if ($result['next_operation_name']) return $result['passed'].' '.$word.($lane === 'rework' ? '返工' : '').'合格，已交'.$result['next_operation_name'].'。'.$paused;
        if (($result['returned'] ?? 0) > 0) return $result['returned'].' '.$word.'返工合格，已解冻回仓库可用库存，总装那边重新收料就能用。'.$paused;
        if (($result['repaired'] ?? 0) > 0 && $result['repaired'] === $result['passed']) return $result['passed'].' 台设备返修合格，请仓库在发货单上把设备返回成品位后重新试水。'.$paused;
        return $result['passed'].' '.$word.'合格。'.$paused;
    }

    /** 已备料：集中下料提前切好的料，不计时直接交下道。 */
    private function prepare(Request $request, int $id, object $user): string
    {
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct', 'remark' => 'nullable|string|max:500',
            'cutting_run_id' => 'nullable|integer|min:1'] + $this->completionRules());
        $result = $this->productionCommand('mfg_task.prepare', $id, $data, $user);
        if (isset($result['completion_action'])) return $this->completionMessage($result, $this->unitWord($id));
        if ($result['next_operation_name']) return $result['passed'].' '.$this->unitWord($id).'已备料，交'.$result['next_operation_name'].'。';
        return $result['passed'].' '.$this->unitWord($id).'已备料，生产完成。';
    }

    private function completionRules(): array
    {
        return ['completion_action' => 'nullable|in:store,handoff', 'completion_location_id' => 'nullable|integer|min:1', 'handoff_operation_id' => 'nullable|integer|min:1']
            + ProductionMediaService::rules();
    }

    private function productionCommand(string $action, int $id, array $data, object $user): array
    {
        $auth = app(AuthContextService::class);
        $commandId = $data['client_command_id'];
        unset($data['client_command_id']);
        return app(\App\Services\Erp\WarehouseCommandService::class)->run($action, $id, $commandId, $data, $user,
            $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
    }

    private function completionMessage(array $result, string $word): string
    {
        $message = $result['received_qty'].' '.$word.($result['completion_action'] === 'handoff'
            ? '已交接给'.$result['handoff_operation_name'].(! empty($result['shipment_no']) ? '（'.$result['shipment_no'].'）' : '').'。'
            : '已提交入库，待仓库确认（'.implode('、', $result['receipt_nos'] ?? [$result['receipt_no']]).'）。');
        if (($result['repaired'] ?? 0) > 0) $message .= '另有 '.$result['repaired'].' 台原设备返修合格，请仓库在发货单上返回成品位后重新试水。';
        return $message;
    }

    private function reject(Request $request, int $id, TaskService $tasks, object $user, string $lane): string
    {
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1', 'mode' => 'required|in:return,scrap',
            'return_operation_id' => 'required_if:mode,return|nullable|integer|min:1', 'reason_tags' => 'nullable|array|max:10', 'reason_tags.*' => 'string|max:30',
            'reason' => 'nullable|string|max:500', 'photos' => 'nullable|array|max:9', 'photos.*' => 'string|max:1000'] + ProductionMediaService::rules());
        $result = $tasks->reject($id, $data['unit_ids'], $data, $user, $lane);
        $word = $this->unitWord($id);
        $paused = ! empty($result['timing_paused']) ? '本道暂时没有可做的，计时已自动暂停。' : '';
        if ($result['mode'] === 'scrap') return $result['count'].' '.$word.($result['immediate'] ? '已报废（无需审核，直接生效）。' : '已申请报废，等 '.$result['reviewers'].' 审核。').$paused;
        return $result['count'].' '.$word.'已退回'.$result['return_operation_name'].'返工'.($result['rework_owner_name'] ? '，默认交给 '.$result['rework_owner_name'] : '，等人接返工').'。'.$paused;
    }

    /** 领子件那道的正常生产接单人，可按缺口给半成品下工单。 */
    private function semiOrder(Request $request, int $id, object $user, string $lane): string
    {
        if ($lane !== 'normal') throw new WorkOrderDomainException('lane_invalid', '返工任务不能开半成品工单。');
        $data = $request->validate(['component_item_id' => 'required|integer|min:1', 'qty' => 'required|integer|min:1|max:100000']);
        $woId = (int) MfgWorkOrderOperation::query()->whereKey($id)->value('work_order_id');
        if (! $woId) throw new WorkOrderDomainException('not_found', '工序不存在。', 404);
        $child = app(WorkOrderService::class)->createForParent($woId, (int) $data['component_item_id'], (float) $data['qty'], $user, $id);
        $text = $child->status === 'released' ? '已下达给下料组' : '资料不全，停在计划：'.$child->block_reason;
        return "半成品工单 {$child->work_order_no}（{$child->item_name} ".(int) $data['qty'].($child->unit_name ?: '')."）已开，{$text}。";
    }

    private function canApprove(object $user, array $codes): bool
    {
        return app(AuthContextService::class)->isSuperAdmin($user) || in_array('production.scrap.approve', $codes, true);
    }

    /** 提示语里的量词：整机说“台设备”，电箱、架子等半成品按工单单位说（如“个”）。 */
    private function unitWord(?int $taskId, ?int $workOrderId = null): string
    {
        $wo = \Illuminate\Support\Facades\DB::table('erp_mfg_work_orders as w')->leftJoin('erp_items as i', 'i.id', '=', 'w.item_id')
            ->when($taskId, fn ($q) => $q->join('erp_mfg_work_order_operations as o', 'o.work_order_id', '=', 'w.id')->where('o.id', $taskId), fn ($q) => $q->where('w.id', $workOrderId))
            ->first(['i.item_type', 'w.unit_name']);
        if (! $wo || $wo->item_type === 'finished_product') return '台设备';
        return trim((string) $wo->unit_name) ?: '件';
    }

    private function run(\Closure $fn, string $message): string
    {
        $fn();
        return $message;
    }

    /** @return array{0: object, 1: array} 当前用户、权限编码（满足任一所需权限即可） */
    private function user(Request $request, array $anyOf): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        if (! $auth->isSuperAdmin($user) && ! array_intersect($anyOf, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有这项生产权限。', 403);
        return [$user, $codes];
    }
}
