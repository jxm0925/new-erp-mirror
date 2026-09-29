<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Erp\ProductionDemandResource;
use App\Http\Resources\Erp\WorkOrderMaterialRequirementResource;
use App\Http\Resources\Erp\WorkOrderResource;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionWorkOrderQueryService;
use App\Services\Erp\ReleaseGateApplicationService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionWorkOrderController extends Controller
{
    public function demands(Request $request, ProductionWorkOrderQueryService $service)
    {
        $context = $this->context($request);
        return response()->json($this->presentPaginator($service->demands($this->filters($request), ...$context), $request, ProductionDemandResource::class));
    }

    public function demand(Request $request, int $id, ProductionWorkOrderQueryService $service)
    {
        $context = $this->context($request);
        return response()->json(['data' => ProductionDemandResource::make($service->demand($id, $this->filters($request), ...$context))->resolve($request)]);
    }

    public function workOrders(Request $request, ProductionWorkOrderQueryService $service)
    {
        $context = $this->context($request);
        $filters = $this->filters($request);
        $response = $this->presentPaginator($service->workOrders($filters, ...$context), $request, WorkOrderResource::class);
        if ($request->boolean('include_summary')) $response['summary'] = $service->workOrderSummary($filters, ...$context);
        return response()->json($response);
    }

    public function operations(Request $request, int $id, \App\Services\Erp\ProductionWorkOrderOperationQueryService $service)
    {
        $filters = $request->validate([
            'status' => 'nullable|string|max:30', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        return response()->json($service->paginate($id, $filters, ...$this->context($request)));
    }

    public function showWorkOrder(Request $request, int $id, ProductionWorkOrderQueryService $service)
    {
        $context = $this->context($request);
        return response()->json(['data' => WorkOrderResource::make($service->workOrder($id, ...$context))->resolve($request)]);
    }

    public function releaseGate(Request $request, int $id, ReleaseGateApplicationService $service)
    {
        $context = $this->context($request);
        return response()->json(['data' => $service->evaluate($id, ...$context)]);
    }

    public function materialRequirements(Request $request, int $id, ReleaseGateApplicationService $service)
    {
        $context = $this->context($request);
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($this->presentPaginator(
            $service->materialRequirements($id, $filters, ...$context),
            $request,
            WorkOrderMaterialRequirementResource::class,
        ));
    }

    public function store(Request $request, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $workOrder = $service->createDraft($this->commandPayload($request, false), ...$context);
        return response()->json(['message' => '工单草稿已保存。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)], 201);
    }

    public function update(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $workOrder = $service->updateDraft($id, $this->commandPayload($request, true), ...$context);
        return response()->json(['message' => '工单草稿已更新。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function updatePlan(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $payload = $request->validate([
            'client_command_id' => ['required', 'string', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'planned_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'production_location_name' => ['prohibited'],
            'production_batch' => ['prohibited'],
            'target_qty' => ['prohibited'], 'output_item_id' => ['prohibited'],
            'production_routing_id' => ['prohibited'], 'target_routing_operation_id' => ['prohibited'],
        ]);
        $workOrder = $service->updatePlan($id, $payload, ...$this->context($request));
        return response()->json(['message' => '生产计划已保存。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function submit(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $workOrder = $service->submit($id, $this->transitionPayload($request), ...$context);
        return response()->json(['message' => '工单已提交并进入待发布。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function publish(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $workOrder = $service->publish($id, $this->transitionPayload($request), ...$context);
        return response()->json(['message' => '工单已发布。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function returnToDraft(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $workOrder = $service->returnToDraft($id, $this->transitionPayload($request, false), ...$context);
        return response()->json(['message' => '工单已退回草稿。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function cancel(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $workOrder = $service->cancel($id, $this->transitionPayload($request, false), ...$context);
        return response()->json(['message' => '工单已取消。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function rematchRouting(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $payload = $request->validate([
            'client_command_id' => ['required', 'string', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'production_routing_id' => ['prohibited'],
            'output_item_id' => ['prohibited'],
            'target_routing_operation_id' => ['prohibited'],
        ]);
        $workOrder = $service->rematchRouting($id, $payload, ...$context);
        return response()->json(['message' => '工艺路线已重新匹配并冻结。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function technicalPreparation(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $workOrder = $service->showWorkOrder($id, ...$this->context($request));
        $technical = app(\App\Services\Erp\WorkOrderTechnicalService::class);
        if ($request->filled('type')) {
            return response()->json($technical->options($workOrder, (string) $request->input('type'), $request->only(['keyword', 'item_id', 'bom_id', 'category_id', 'per_page'])));
        }
        return response()->json(['data' => $technical->preview($workOrder, $request->filled('bom_id') ? (int) $request->input('bom_id') : null)]);
    }

    public function confirmTechnical(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $context = $this->context($request);
        $payload = $request->validate([
            'client_command_id' => ['required', 'string', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'bom_id' => ['required', 'integer', 'min:1'],
            'production_routing_id' => ['required', 'integer', 'min:1'],
            'output_configuration_id' => ['nullable', 'integer', 'min:1'],
            'drawing_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attachment_ids' => ['sometimes', 'array', 'max:20'],
            'attachment_ids.*' => ['integer', 'min:1', 'distinct'],
            'materials' => ['present', 'array', 'max:500'],
            'materials.*.bom_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'materials.*.configuration_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $workOrder = $service->confirmTechnical($id, $payload, ...$context);
        return response()->json(['message' => '生产资料已确认并保存版本。', 'data' => WorkOrderResource::make($workOrder)->resolve($request)]);
    }

    public function uploadTechnicalAttachment(Request $request, int $id, \App\Services\Erp\WorkOrderTechnicalAttachmentApplicationService $service)
    {
        $payload = $request->validate(['client_command_id' => ['required', 'string', 'max:120'], 'expected_version' => ['required', 'integer', 'min:1'], 'file' => ['required', 'file']]);
        $row = $service->upload($id, $request->file('file'), $request->only(['client_command_id', 'expected_version']), ...$this->context($request));
        return response()->json(['data' => $service->metadata($row)]);
    }

    public function readTechnicalAttachment(Request $request, int $id, int $attachmentId, \App\Services\Erp\WorkOrderTechnicalAttachmentApplicationService $service)
    {
        $row = $service->read($id, $attachmentId, ...$this->context($request));
        $disk = \Illuminate\Support\Facades\Storage::disk($row->storage_disk);
        abort_unless($disk->exists($row->storage_path), 404, '附件文件不存在。');
        $preview = ! $request->boolean('download') && $service->metadata($row)['previewable'];
        return $disk->response($row->storage_path, $row->original_name, [
            'Content-Type' => $preview ? $row->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], $preview ? 'inline' : 'attachment');
    }

    public function technicalVersions(Request $request, int $id, WorkOrderApplicationService $service)
    {
        $service->showWorkOrder($id, ...$this->context($request));
        $rows = \Illuminate\Support\Facades\DB::table('erp_work_order_technical_versions')
            ->where('work_order_id', $id)->orderByDesc('version_no')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 20))));
        $rows->getCollection()->transform(function ($row) {
            $row->snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
            return $row;
        });
        return response()->json($rows);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $permissions = $auth->permissionCodes($user);
        $superAdmin = $auth->isSuperAdmin($user);
        $request->attributes->set('erp_action_context', [
            'permissions' => $permissions,
            'super_admin' => $superAdmin,
        ]);
        return [$user, $permissions, $superAdmin];
    }

    private function commandPayload(Request $request, bool $editing): array
    {
        $rules = [
            'client_command_id' => ['required', 'string', 'max:120'],
            'source_type' => [$editing ? 'prohibited' : 'nullable', 'in:sales_order,production_plan,trial,stock_prebuild'],
            'source_id' => [$editing ? 'prohibited' : 'prohibited'],
            'source_no_snapshot' => [$editing ? 'prohibited' : 'prohibited'],
            'source_title_snapshot' => [$editing ? 'prohibited' : 'prohibited'],
            'output_item_id' => [$editing ? 'prohibited' : 'nullable', 'integer', 'exists:erp_items,id'],
            'production_routing_id' => [$editing ? 'sometimes' : 'nullable', 'integer', 'exists:erp_production_routings,id'],
            'target_operation_id' => ['prohibited'],
            'target_routing_operation_id' => [$editing ? 'sometimes' : 'nullable', 'integer', 'exists:erp_production_routing_operations,id'],
            'stocking_purpose' => [$editing ? 'prohibited' : 'nullable', 'in:common_inventory,reserved_for_work_order'],
            'reserved_for_work_order_id' => [$editing ? 'prohibited' : 'nullable', 'integer', 'exists:erp_work_orders,id'],
            'reserved_for_production_unit_id' => [$editing ? 'prohibited' : 'nullable', 'integer', 'exists:erp_production_units,id'],
            'reserved_for_target_operation_id' => [$editing ? 'prohibited' : 'nullable', 'integer', 'exists:erp_production_routing_operations,id'],
            'creation_session_id' => ['nullable', 'uuid'],
            'reservation_token' => ['nullable', 'uuid'],
            'target_qty' => [$editing ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'planned_date' => ['nullable', 'date'],
            'production_batch' => ['nullable', 'string', 'max:120'],
            'responsible_user_legacy_id' => ['nullable', 'integer', 'exists:erp_legacy_admin_users,legacy_id'],
            'production_location_name' => ['nullable', 'string', 'max:160'],
        ];
        if (! $editing) {
            $rules['production_demand_id'] = ['nullable', 'integer', 'exists:erp_sales_order_production_requirements,id'];
            $rules['expected_demand_version'] = ['required_with:production_demand_id', 'nullable', 'integer', 'min:1'];
        } else {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        }
        $data = $request->validate($rules);
        if ($editing) return $data;
        $source = $data['source_type'] ?? 'sales_order';
        if ($source === 'sales_order') {
            if (empty($data['production_demand_id']) || empty($data['expected_demand_version'])) throw ValidationException::withMessages(['production_demand_id' => '销售订单来源工单必须关联真实生产需求。']);
            foreach (['output_item_id', 'production_routing_id', 'target_routing_operation_id'] as $field) if (array_key_exists($field, $data)) throw ValidationException::withMessages([$field => '销售订单来源工单的物料和工艺路线必须由系统推导。']);
        } elseif ($source === 'stock_prebuild') {
            foreach (['output_item_id', 'production_routing_id', 'target_routing_operation_id', 'stocking_purpose', 'creation_session_id', 'reservation_token'] as $field) if (empty($data[$field])) throw ValidationException::withMessages([$field => '备货生产单必须完整选择产出物料、工艺路线、目标路线工序和备货用途。']);
        }
        return $data;
    }

    private function transitionPayload(Request $request, bool $requireReason = true): array
    {
        return $request->validate([
            'client_command_id' => ['required', 'string', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => [$requireReason ? 'required' : 'nullable', 'string', 'max:500'],
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'keyword' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:40'],
            'display_status' => ['nullable', 'in:IN_PROGRESS,WAIT_CONDITION,EXCEPTION,COMPLETED'],
            'source_type' => ['nullable', 'in:sales_order,production_plan,trial,stock_prebuild'],
            'source_group' => ['nullable', 'in:stock_prebuild,other'],
            'stocking_purpose' => ['nullable', 'in:common_inventory,reserved_for_work_order'],
            'sales_order_id' => ['nullable', 'integer', 'min:1'],
            'sales_order_no' => ['nullable', 'string', 'max:120'],
            'production_demand_id' => ['nullable', 'integer', 'min:1'],
            'responsible_user_legacy_id' => ['nullable', 'integer', 'min:1'],
            'production_location_name' => ['nullable', 'string', 'max:160'],
            'customer' => ['nullable', 'string', 'max:160'],
            'product' => ['nullable', 'string', 'max:160'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'delivery_date_from' => ['nullable', 'date_format:Y-m-d'],
            'delivery_date_to' => ['nullable', 'date_format:Y-m-d'],
            'quantity_min' => ['nullable', 'numeric', 'min:0'],
            'quantity_max' => ['nullable', 'numeric', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'],
            'include_summary' => ['nullable', 'boolean'],
        ]);
    }

    private function presentPaginator($paginator, Request $request, string $resourceClass): array
    {
        $paginator->setCollection($paginator->getCollection()->map(
            fn ($item) => $resourceClass::make($item)->resolve($request)
        ));
        return $paginator->toArray();
    }
}
