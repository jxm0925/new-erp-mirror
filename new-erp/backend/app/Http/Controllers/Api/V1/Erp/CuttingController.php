<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, CuttingConfigurationService, CuttingConfirmationService, CuttingCorrectionService, CuttingDemandService, CuttingHandoverService, CuttingInputService, CuttingInventoryReservationService, CuttingOrderLifecycleService, CuttingReadService, CuttingRecordService, CuttingTaskExecutionService, CuttingWarehouseReceiptService};
use Illuminate\Http\Request;

final class CuttingController extends Controller
{
    public function workerCreate(Request $r, \App\Services\Erp\CuttingWorkerOrderService $s)
    { $this->validateCommand($r,0); return response()->json(['data'=>$s->create($r->all(), ...$this->context($r))],201); }
    public function workerOutputs(Request $r, \App\Services\Erp\CuttingWorkerOrderService $s)
    { return response()->json($s->outputs($this->filters($r), ...$this->context($r))); }
    public function workerCategories(Request $r, \App\Services\Erp\CuttingWorkerOrderService $s)
    { return response()->json($s->categories($this->filters($r), ...$this->context($r))); }
    public function workerInputs(Request $r, CuttingReadService $s)
    { return response()->json($s->workerInputs($this->filters($r), ...$this->context($r))); }
    public function workerInputCategories(Request $r, CuttingReadService $s)
    { return response()->json($s->workerInputCategories($this->filters($r), ...$this->context($r))); }

    public function demands(Request $r, CuttingDemandService $s)
    { return response()->json($s->paginate($this->demandFilters($r), ...$this->context($r))); }
    public function demand(Request $r, int $id, CuttingDemandService $s)
    { return response()->json(['data'=>$s->show($id,$this->demandDetailFilters($r), ...$this->context($r))]); }
    public function generateDemand(Request $r, CuttingDemandService $s)
    { return response()->json(['message'=>'正式下料需求已生成','data'=>$s->generate($this->demandGenerationPayload($r), ...$this->context($r))],201); }
    public function reviseDemand(Request $r, int $id, CuttingDemandService $s)
    { return response()->json(['message'=>'正式下料需求变更已记录','data'=>$s->revise($id,$this->demandRevisionPayload($r), ...$this->context($r))]); }

    public function configurations(Request $r, CuttingConfigurationService $s)
    { return response()->json($s->paginate($this->configurationFilters($r), ...$this->context($r))); }
    public function configuration(Request $r, int $id, CuttingConfigurationService $s)
    { return response()->json(['data'=>$s->show($id, ...$this->context($r))]); }
    public function createConfiguration(Request $r, CuttingConfigurationService $s)
    { return response()->json(['message'=>'配置草稿已建立','data'=>$s->createDraft($this->configurationPayload($r, true), ...$this->context($r))],201); }
    public function updateConfiguration(Request $r, int $id, CuttingConfigurationService $s)
    { return response()->json(['message'=>'配置草稿已保存','data'=>$s->updateDraft($id,$this->configurationPayload($r, false), ...$this->context($r))]); }
    public function publishConfiguration(Request $r, int $id, CuttingConfigurationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'配置版本已发布','data'=>$s->publish($id,$r->only(['client_command_id','expected_version']), ...$this->context($r))]); }
    public function versionConfiguration(Request $r, int $id, CuttingConfigurationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下一版本配置草稿已建立','data'=>$s->createNextVersion($id,$r->only(['client_command_id','expected_version']), ...$this->context($r))],201); }

    public function index(Request $r, CuttingReadService $s)
    { return response()->json($s->orders($this->filters($r), ...$this->context($r))); }
    public function execution(Request $r, int $id, CuttingReadService $s)
    { return response()->json(['data'=>$s->execution($id,$this->filters($r), ...$this->context($r))]); }
    public function tasks(Request $r, CuttingReadService $s)
    { return response()->json($s->tasks($this->filters($r), ...$this->context($r))); }
    public function taskExecution(Request $r, int $id, CuttingReadService $s)
    { return response()->json(['data'=>$s->taskExecution($id,$this->filters($r), ...$this->context($r))]); }
    public function settlementExecution(Request $r, int $id, CuttingReadService $s)
    { return response()->json(['data'=>$s->settlementExecution($id,$this->filters($r), ...$this->context($r))]); }
    public function outputs(Request $r, int $id, CuttingReadService $s)
    { return response()->json($s->allowedOutputs($id,$this->filters($r), ...$this->context($r))); }
    public function selectorCategories(Request $r, int $id, CuttingReadService $s)
    { return response()->json($s->selectorCategories($id,$this->selectorCategoryFilters($r), ...$this->context($r))); }
    public function handoverTargets(Request $r, int $id, CuttingReadService $s)
    { return response()->json($s->handoverTargets($id,$this->filters($r), ...$this->context($r))); }
    public function inputs(Request $r, int $id, CuttingReadService $s)
    { return response()->json($s->inputCandidates($id,$this->filters($r), ...$this->context($r))); }
    public function materialPhysicals(Request $r, CuttingReadService $s)
    { return response()->json($s->materialPhysicals($this->filters($r), ...$this->context($r))); }
    public function materialPhysical(Request $r, int $id, CuttingReadService $s)
    { return response()->json(['data'=>$s->materialPhysical($id, ...$this->context($r))]); }
    public function publish(Request $r, CuttingRecordService $s)
    { $this->validateCommand($r,0); return response()->json(['data'=>$s->publish($r->all(), ...$this->context($r))],201); }
    public function closeOrder(Request $r, int $id, CuttingOrderLifecycleService $s)
    { $this->validateCommand($r); $r->validate(['reason'=>'required|string|max:1000']); return response()->json(['message'=>'下料单已关闭','data'=>$s->close($id,$r->all(), ...$this->context($r))]); }
    public function cancelOrder(Request $r, int $id, CuttingOrderLifecycleService $s)
    { $this->validateCommand($r); $r->validate(['reason'=>'required|string|max:1000']); return response()->json(['message'=>'下料单已取消','data'=>$s->cancel($id,$r->all(), ...$this->context($r))]); }
    public function registerPhysical(Request $r, CuttingInputService $s)
    { $this->validateCommand($r,0); [$u,$p] = $this->context($r); return response()->json(['data'=>$s->registerPhysical($r->all(),$u,$p)],201); }
    public function disposePhysical(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); $r->validate(['reason'=>'required|string|max:1000']); return response()->json(['message'=>'余料已正式处置','data'=>$s->disposeRemnant($id,$r->all(), ...$this->context($r))]); }
    public function reserve(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->reserve($id,$r->all(), ...$this->context($r))]); }
    public function release(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->release($id,$r->all(), ...$this->context($r))]); }
    public function issue(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->issue($id,$r->all(), ...$this->context($r))],201); }
    public function issuePhysicals(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->issuePhysicals($id,$r->all(), ...$this->context($r))],201); }
    public function firstCut(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->markFirstCut($id,$r->all(), ...$this->context($r))]); }
    public function returnOriginal(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); $r->validate(['reason'=>'required|string|max:1000']); return response()->json(['message'=>'未切原材料已退回','data'=>$s->returnOriginal($id,$r->all(), ...$this->context($r))]); }
    public function save(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'加工记录草稿已保存','data'=>$s->saveResults($id,$r->all(), ...$this->context($r))]); }
    public function split(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->splitRoutes($id,$r->all(), ...$this->context($r))]); }
    public function submit(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); $data = $s->submit($id,$r->all(), ...$this->context($r)); return response()->json(['message'=>'加工结果已提交','data'=>$data]); }
    public function inspect(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'质量检验已记录','data'=>$s->inspect($id,$r->all(), ...$this->context($r))]); }
    public function confirm(Request $r, int $id, CuttingConfirmationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'用料核算已确认','data'=>$s->confirm($id,$r->all(), ...$this->context($r))]); }
    public function reverseConfirmation(Request $r, int $id, CuttingCorrectionService $s)
    { $this->validateCommand($r); $r->validate(['reason'=>'required|string|max:1000']); return response()->json(['message'=>'用料核算已转入更正','data'=>$s->reverse($id,$r->all(), ...$this->context($r))]); }
    public function returnForEdit(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'加工记录已退回修改','data'=>$s->returnForEdit($id,$r->all(), ...$this->context($r))]); }
    public function claimTask(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料任务已领取','data'=>$s->claim($id,$r->all(), ...$this->context($r))]); }
    public function startTask(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料任务已开始','data'=>$s->start($id,$r->all(), ...$this->context($r))]); }
    public function pauseTask(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'本人下料计时已暂停','data'=>$s->pause($id,$r->all(), ...$this->context($r))]); }
    public function resumeTask(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'本人下料计时已恢复','data'=>$s->resume($id,$r->all(), ...$this->context($r))]); }
    public function finishTask(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料任务已完成','data'=>$s->finish($id,$r->all(), ...$this->context($r))]); }
    public function addTaskCollaborators(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料协作者已更新','data'=>$s->addCollaborators($id,$r->all(), ...$this->context($r))]); }
    public function leaveTaskCollaboration(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'已退出下料协作','data'=>$s->leaveCollaboration($id,$r->all(), ...$this->context($r))]); }
    public function startTaskCollaborationLabor(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'协同下料计时已开始','data'=>$s->startCollaboratorLabor($id,$r->all(), ...$this->context($r))]); }
    public function pauseTaskCollaborationLabor(Request $r, int $id, CuttingTaskExecutionService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'协同下料计时已暂停','data'=>$s->pauseCollaboratorLabor($id,$r->all(), ...$this->context($r))]); }
    public function dispatchHandover(Request $r, int $id, CuttingHandoverService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料产出已交出','data'=>$s->dispatch($id,$r->all(), ...$this->context($r))],201); }
    public function pendingHandovers(Request $r, CuttingHandoverService $s)
    { return response()->json($s->pending($this->filters($r), ...$this->context($r))); }
    public function acceptHandover(Request $r, int $id, CuttingHandoverService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料产出已接收','data'=>$s->accept($id,$r->all(), ...$this->context($r))]); }
    public function rejectHandover(Request $r, int $id, CuttingHandoverService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料产出已拒收并退回待交出','data'=>$s->reject($id,$r->all(), ...$this->context($r))]); }
    public function warehouseRoute(Request $r, int $id, CuttingWarehouseReceiptService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料产出已正式入库','data'=>$s->post($id,$r->all(), ...$this->context($r))],201); }
    public function inventoryReservations(Request $r, CuttingInventoryReservationService $s)
    { return response()->json($s->paginate($this->filters($r), ...$this->context($r))); }
    public function createInventoryIssue(Request $r, int $id, CuttingInventoryReservationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料库存领用单已建立','data'=>$s->createIssue($id,$r->all(), ...$this->context($r))],201); }
    public function releaseInventory(Request $r, int $id, CuttingInventoryReservationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料库存归属已释放','data'=>$s->release($id,$r->all(), ...$this->context($r))]); }
    public function cancelInventoryIssue(Request $r, int $id, CuttingInventoryReservationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'下料库存领用单已取消','data'=>$s->cancelIssue($id,$r->all(), ...$this->context($r))]); }

    private function validateCommand(Request $r, int $minimum = 1): void
    { $r->validate(['client_command_id'=>'required|string|max:120','expected_version'=>'required|integer|min:'.$minimum]); }
    private function filters(Request $r): array
    { return $r->validate(['page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100','keyword'=>'nullable|string|max:100',
        'category_id'=>'nullable|integer|min:1','status'=>'nullable|string|max:24','input_type'=>'nullable|in:physical,quantity',
        'material_form'=>'nullable|in:FULL_STOCK,REMNANT','shape'=>'nullable|in:RECTANGLE,IRREGULAR',
        'scope'=>'nullable|in:mine,pool','status_group'=>'nullable|in:active,finished','flow'=>'nullable|in:confirm,handover,warehouse']); }
    private function selectorCategoryFilters(Request $r): array
    { return $r->validate(['mode'=>'required|in:inputs,outputs','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']); }
    private function configurationFilters(Request $r): array
    { return $r->validate(['page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100',
        'keyword'=>'nullable|string|max:100','item_id'=>'nullable|integer|min:1','status'=>'nullable|in:DRAFT,PUBLISHED',
        'scope_mode'=>'nullable|in:PUBLIC,RESTRICTED']); }
    private function demandFilters(Request $r): array
    { return $r->validate(['page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100',
        'keyword'=>'nullable|string|max:100','item_id'=>'nullable|integer|min:1','consumer_work_order_id'=>'nullable|integer|min:1',
        'source_requirement_id'=>'nullable|integer|min:1','status'=>'nullable|in:ACTIVE,CLOSED,CANCELLED']); }
    private function demandDetailFilters(Request $r): array
    { return $r->validate(['revision_page'=>'nullable|integer|min:1','revision_per_page'=>'nullable|integer|min:1|max:100']); }
    private function demandGenerationPayload(Request $r): array
    { return $r->validate(['client_command_id'=>'required|string|max:120','expected_version'=>'required|integer|min:0|max:0',
        'source_requirement_id'=>'required|integer|min:1','producer_work_order_id'=>'required|integer|min:1',
        'producer_stage_id'=>'required|integer|min:1','configuration_id'=>'nullable|integer|min:1']); }
    private function demandRevisionPayload(Request $r): array
    { return $r->validate(['client_command_id'=>'required|string|max:120','expected_version'=>'required|integer|min:1','reason'=>'required|string|max:1000']); }
    private function configurationPayload(Request $r, bool $create): array
    {
        $rules = ['client_command_id'=>'required|string|max:120','expected_version'=>$create ? 'required|integer|min:0|max:0' : 'required|integer|min:1',
            'dimensions'=>'required|array|min:1|max:9','dimensions.*'=>'required','drawing_reference'=>'required|string|max:255',
            'scope_mode'=>'required|in:PUBLIC,RESTRICTED','scope_work_order_ids'=>'present|array|max:100',
            'scope_work_order_ids.*'=>'integer|min:1|distinct'];
        if ($create) $rules['item_id'] = 'required|integer|min:1';
        return $r->validate($rules);
    }
    private function context(Request $r): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($r);
        if (! $user) throw new WorkOrderDomainException('unauthenticated','请先登录ERP。',401);
        return [$user,$auth->permissionCodes($user),$auth->isSuperAdmin($user)];
    }
}
