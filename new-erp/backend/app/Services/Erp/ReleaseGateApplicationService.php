<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderMaterialRequirement;
use App\Models\Erp\WorkOrderReleaseGateCheck;
use App\Models\Erp\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseGateApplicationService
{
    public function __construct(
        private readonly BomMatcher $bomMatcher,
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly ProductionExecutionFoundationService $productionExecution,
        private readonly SalesOrderFundingGateService $fundingGates,
        private readonly LengthCutRequirementService $lengthCutRequirements,
    ) {
    }

    public function evaluate(int $workOrderId, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->assertPermission($permissions, 'production.work_order.gate.view');
        $workOrder = WorkOrder::query()->with(['demand.order', 'demand.line'])->find($workOrderId);
        if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
        $this->assertVisible($workOrder, $user, $permissions, $superAdmin);

        return DB::transaction(function () use ($workOrderId, $user, $permissions, $superAdmin): array {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            if (app(WorkOrderOutputPlanService::class)->historical($locked)) {
                return $this->releasedResult($locked);
            }

            return $this->evaluateLocked($locked, $user, true, $permissions, $superAdmin);
        }, 3);
    }

    public function evaluateLocked(WorkOrder $workOrder, object $user, bool $persist = true, ?array $permissions = null, ?bool $superAdmin = null): array
    {
        if (app(WorkOrderOutputPlanService::class)->historical($workOrder)) {
            return $this->releasedResult($workOrder);
        }

        $demand = $workOrder->production_demand_id
            ? ProductionDemand::query()->with(['line', 'order'])->lockForUpdate()->find($workOrder->production_demand_id)
            : null;
        if (($workOrder->source_type ?: 'sales_order') === 'sales_order' && ! $demand) $this->fail('demand_missing', '销售订单来源工单的生产需求不存在。', 409);

        $line = $demand?->line;
        $match = $this->bomMatcher->match(
            (int) (($demand?->product_id ?? 0) ?: ($line->product_id ?? 0)) ?: null,
            (int) (($demand?->sku_id ?? 0) ?: ($line->sku_id ?? 0)) ?: null,
            (int) (($workOrder->output_item_id ?? 0) ?: ($demand?->item_id ?? 0) ?: ($line->item_id ?? 0)) ?: null,
            app(WorkOrderTechnicalService::class)->effectiveConfiguration($workOrder),
            ($workOrder->bom_id ?: $demand?->bom_id) ? (int) ($workOrder->bom_id ?: $demand?->bom_id) : null,
        );
        $bom = null;
        if (! empty($match['bom_id'])) {
            $bom = Bom::query()
                ->with(['items.componentItem.unit', 'items.unit', 'outputItem.unit'])
                ->lockForUpdate()
                ->find((int) $match['bom_id']);
        }
        $outputItem = $workOrder->output_item_id ? Item::query()->find($workOrder->output_item_id) : null;
        $executionMode = (string) ($outputItem?->production_execution_mode ?: 'unit');
        $unitQuantityValid = $executionMode !== 'unit' || $this->isPositiveIntegerDecimal((string) $workOrder->target_base_qty);
        $supplyCoverage = $this->materialSupplyCoverage($workOrder, $bom);
        $fundingOrder = $demand?->order;
        if (! $fundingOrder && $workOrder->assembly_root_work_order_id) {
            $fundingOrder = WorkOrder::with('demand.order')->find($workOrder->assembly_root_work_order_id)?->demand?->order;
        }
        $funding = $fundingOrder ? $this->fundingGates->status($fundingOrder) : null;
        $outputPlan = app(WorkOrderOutputPlanService::class)->prepare($workOrder, true);
        $additionalOutputCount = app(WorkOrderPlannedOutputService::class)->additionalCount($workOrder);

        $checks = [
            $this->check('planned_outputs', $additionalOutputCount === 0,
                'multi_output_execution_not_available', '工单包含多项计划产出，当前尚未开放多产出执行，不能发布。',
                ['additional_output_count' => $additionalOutputCount]),
            $this->check('work_order_state', $workOrder->status === WorkOrderApplicationService::WAIT_RELEASE, 'state_not_wait_release', '工单必须处于待发布状态。', ['status' => $workOrder->status]),
            $this->check(
                'production_funding',
                $funding === null || (bool) ($funding['production_funds_satisfied'] ?? false),
                'production_funding_blocked',
                '来源销售订单当前有效净收款未达到生产资金门槛。',
                ['funding_status' => $funding['production_funding_status'] ?? 'not_required'],
            ),
            $this->check(
                'stock_prebuild_output',
                $workOrder->source_type !== 'stock_prebuild' || (
                    in_array((string) $workOrder->stocking_purpose, ['common_inventory', 'reserved_for_work_order'], true)
                    && (int) $workOrder->effective_output_item_id_snapshot > 0
                    && in_array((string) $workOrder->configured_output_mode_snapshot, ['flow_only', 'warehouse_optional', 'warehouse_required'], true)
                    && in_array((string) $workOrder->effective_output_mode_snapshot, ['flow_only', 'warehouse_optional', 'warehouse_required'], true)
                    && ($workOrder->stocking_purpose !== 'common_inventory' || $workOrder->effective_output_mode_snapshot === 'warehouse_required')
                ),
                'stock_prebuild_output_item_required',
                '备货生产目标工序必须冻结正式产出物料和有效产出模式；公共库存备货必须正式入库。',
                [
                    'stocking_purpose' => $workOrder->stocking_purpose,
                    'configured_output_mode' => $workOrder->configured_output_mode_snapshot,
                    'effective_output_mode' => $workOrder->effective_output_mode_snapshot,
                    'effective_output_item_id' => $workOrder->effective_output_item_id_snapshot,
                ],
            ),
            $this->check($demand ? 'demand_active' : 'source_valid', $demand ? ((bool) $demand->is_active && ! in_array((string) $demand->requirement_status, ['cancelled', 'closed', 'superseded'], true)) : in_array((string) $workOrder->source_type, ['production_plan', 'trial', 'stock_prebuild'], true), $demand ? 'demand_inactive' : 'source_invalid', $demand ? '来源生产需求必须有效且未关闭。' : '工单来源必须有效。', ['source_type' => $workOrder->source_type, 'demand_status' => $demand?->requirement_status]),
            $this->check('routing_snapshot', is_array($workOrder->routing_snapshot) && ! empty($workOrder->routing_snapshot['operations']), 'routing_snapshot_missing', '未找到该产出物料的默认生效工艺路线，工单不能发布。', ['routing_id' => $workOrder->production_routing_id, 'routing_version' => $workOrder->routing_version_snapshot]),
            $this->check('quantity', (float) $workOrder->target_qty > 0 && (float) $workOrder->target_base_qty > 0, 'quantity_invalid', '工单计划数量和基准数量必须大于 0。', ['target_qty' => (float) $workOrder->target_qty, 'target_base_qty' => (float) $workOrder->target_base_qty]),
            $this->check('responsible_user', ! $workOrder->responsible_user_legacy_id || DB::table('erp_legacy_admin_users')->where('legacy_id', $workOrder->responsible_user_legacy_id)->where('status', 'normal')->exists(), 'responsible_user_invalid', '工单既定负责人不存在或已停用；未指定时将由首个正式接单人担任。', []),
            $this->check('bom_match', $bom !== null && ($match['status'] ?? null) === 'matched', 'bom_not_matched', (string) ($match['block_reason'] ?? '未匹配到唯一有效 BOM。'), ['match_status' => $match['status'] ?? null, 'candidates' => $match['candidates'] ?? []]),
            $this->check('bom_effective', $this->bomEffective($bom), 'bom_not_effective', 'BOM 必须已审核、启用且处于生效期。', $bom ? ['bom_id' => $bom->id, 'status' => $bom->status, 'audit_status' => $bom->audit_status, 'effective_date' => optional($bom->effective_date)->format('Y-m-d'), 'expire_date' => optional($bom->expire_date)->format('Y-m-d')] : []),
            $this->check('bom_complete', $this->bomComplete($bom), 'bom_incomplete', 'BOM 必须至少包含一条用量或固定用量大于 0 的有效物料行。', ['line_count' => $bom?->items?->count() ?? 0]),
            $this->check('custom_documents', filled(data_get($workOrder->technical_snapshot, 'drawing_reference')) || $this->customDocumentsReady($line), 'custom_documents_missing', '定制生产发布前必须由技术岗位确认图纸或技术附件。', ['special_customized' => (bool) ($line->is_special_customized ?? false)]),
            $this->check('technical_confirmation', ! app(WorkOrderTechnicalService::class)->requiresConfirmation($workOrder, $bom) || (
                $workOrder->technical_version > 0
                && (int) data_get($workOrder->technical_snapshot, 'bom_id') === (int) $workOrder->bom_id
                && (int) data_get($workOrder->technical_snapshot, 'production_routing_id') === (int) $workOrder->production_routing_id
            ), 'technical_confirmation_required', '定制成品或定制用料必须先完成工单技术确认。', ['technical_version' => (int) $workOrder->technical_version]),
            $this->check('production_execution_mode', in_array($executionMode, ['unit', 'quantity'], true), 'production_execution_mode_invalid', '产出物料必须配置有效的生产执行模式。', ['production_execution_mode' => $executionMode]),
            $this->check('production_unit_quantity', $unitQuantityValid, 'production_unit_quantity_not_integer', "逐件生产物料的工单基准数量必须为整数，当前基准数量为 {$workOrder->target_base_qty}，请检查订单数量或单位换算。", ['production_execution_mode' => $executionMode, 'target_base_qty' => (string) $workOrder->target_base_qty]),
            $this->check('material_supply_rules', $supplyCoverage['valid'], 'material_supply_rule_incomplete', '工艺路线没有完整配置 BOM 物料的目标工序和供应规则。', $supplyCoverage),
        ];

        // Current eligibility gates new publication; released orders returned above keep their frozen facts.
        $scopeItemIds = collect($workOrder->routing_snapshot['operations'] ?? [])->flatMap(fn ($node) => array_merge(
            ! empty($node['output_item_id']) ? [(int) $node['output_item_id']] : [],
            array_column($node['output_rules'] ?? [], 'item_id'),
            array_column($node['material_supply_rules'] ?? [], 'component_item_id'),
            array_column($node['packaging_materials'] ?? [], 'component_item_id'),
        ))->merge($bom?->items?->pluck('component_item_id') ?? [])
            ->merge([$workOrder->output_item_id, $workOrder->effective_output_item_id_snapshot, $bom?->output_item_id])
            ->filter()->unique()->all();
        $officeItems = Item::query()->whereIn('id', $scopeItemIds)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Item $item): bool => $item->managementScope() !== 'factory');
        if ($officeItems->isNotEmpty()) $checks[] = $this->check('production_management_scope', false,
            'office_item_not_allowed_in_production', '工单、BOM 和工艺路线只能使用工厂物料，不能使用办公用品。',
            ['item_ids' => $officeItems->pluck('id')->all(), 'item_codes' => $officeItems->pluck('item_code')->all()]);

        // Legacy routes retain their established checks and executor. Declared yield rules require
        // exact matching plus explicit compatibility; taking the first output would silently drop
        // by-products, quantities or row-level quality/destination policy from the released plan.
        if ($outputPlan['has_explicit_rules'] || $outputPlan['issues'] !== []) {
            $outputProblems = array_merge($outputPlan['issues'], $outputPlan['execution_blockers']);
            $firstProblem = $outputProblems[0] ?? null;
            $checks[] = $this->check('operation_output_plan', $outputProblems === [],
                $firstProblem['code'] ?? 'operation_output_plan_invalid',
                $firstProblem['message'] ?? '工序产出计划未通过完整预检。',
                ['issues' => $outputPlan['issues'], 'execution_blockers' => $outputPlan['execution_blockers']]);
        }

        $assembly = app(AssemblyProductionApplicationService::class)->releaseCheck($workOrder, $bom);
        if ($assembly !== null) $checks[] = $this->check('assembly_component_plan', $assembly['valid'], $assembly['code'], $assembly['message'], $assembly);
        $preparation = app(WorkOrderPreparationMaterialService::class)->releaseCheck($workOrder);
        if ($preparation !== null) $checks[] = $this->check('material_preparation', $preparation['valid'], $preparation['code'], $preparation['message'], $preparation);

        if ($workOrder->source_type === 'stock_prebuild') {
            try {
                app(StockPrebuildEligibilityService::class)->assertWorkOrder($workOrder);
                $checks[] = $this->check('stock_prebuild_material', true, '', '', []);
            } catch (WorkOrderDomainException $exception) {
                $checks[] = $this->check('stock_prebuild_material', false, $exception->errorCode,
                    $exception->getMessage(), $exception->details);
            }
        }
        if ($workOrder->source_type === 'stock_prebuild' && $workOrder->stocking_purpose === 'reserved_for_work_order') {
            try {
                $auth = app(AuthContextService::class);
                $target = app(StockPrebuildTargetService::class)->forWorkOrder($workOrder, $user,
                    $permissions ?? $auth->permissionCodes($user), $superAdmin ?? $auth->isSuperAdmin($user));
                $checks[] = $this->check('stock_prebuild_reserved_target', true, '', '', $target);
            } catch (WorkOrderDomainException $exception) {
                $checks[] = $this->check('stock_prebuild_reserved_target', false, $exception->errorCode,
                    $exception->getMessage(), ['reserved_for_work_order_id' => $workOrder->reserved_for_work_order_id]);
            }
        }
        $allowed = collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed');
        $result = [
            'allowed' => $allowed,
            'status' => $allowed ? 'passed' : 'blocked',
            'immutable' => false,
            'work_order_id' => (int) $workOrder->id,
            'work_order_version' => (int) $workOrder->business_version,
            'bom' => $this->bomProjection($bom, $match['bom_snapshot'] ?? null),
            'output_plan' => $outputPlan,
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed')),
            'evaluated_at' => now()->toISOString(),
        ];

        if ($persist) $this->persist($workOrder, $checks, $user, $allowed);

        return $result;
    }

    public function materialRequirements(int $workOrderId, array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->assertPermission($permissions, 'production.material.view');
        $workOrder = WorkOrder::find($workOrderId);
        if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
        $this->assertVisible($workOrder, $user, $permissions, $superAdmin);

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return WorkOrderMaterialRequirement::query()
            ->where('work_order_id', $workOrderId)
            ->orderBy('line_no')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function buildMaterialRows(WorkOrder $workOrder, Bom $bom, ?array $configuration = null): array
    {
        if ($workOrder->technical_version > 0) {
            $configuration = app(WorkOrderTechnicalService::class)->effectiveConfiguration($workOrder);
        }
        $resolved = $this->lengthCutRequirements->resolveBomLines($bom, $configuration);

        return $resolved->map(function (array $line, int $index) use ($workOrder, $bom): array {
            $template = $bom->items->firstWhere('id', (int) $line['id']);
            $materialConfiguration = collect(data_get($workOrder->technical_snapshot, 'materials', []))
                ->firstWhere('bom_item_id', (int) $line['id'])['configuration'] ?? null;
            $plannedOutput = (float) $workOrder->target_base_qty;
            $perOutput = (float) $line['qty'];
            $lossRate = (float) $line['loss_rate'];
            $fixedQty = (float) $line['fixed_qty'];
            $required = round($perOutput * $plannedOutput * (1 + $lossRate / 100) + $fixedQty, 8);
            $unit = $template?->unit ?: $template?->componentItem?->unit;
            $baseUnit = $template?->componentItem?->unit ?: $unit;
            $perOutputPieces = $line['piece_qty'] === null ? null : (float) $line['piece_qty'];
            $requiredPieces = $perOutputPieces === null ? null : round($perOutputPieces * $plannedOutput, 8);
            if ($requiredPieces !== null && abs($requiredPieces - round($requiredPieces)) > 0.00000001) {
                $this->fail('cut_piece_quantity_not_integer', "下料需求 {$line['component_item_name']} {$line['cut_length_mm']}mm 的总段数必须是整数。", 422);
            }

            return [
                'work_order_id' => $workOrder->id,
                'line_no' => (int) ($line['line_no'] ?: (($index + 1) * 10)),
                'bom_id' => $bom->id,
                'bom_item_id' => $line['id'],
                'component_item_id' => $line['component_item_id'],
                'configuration_id' => $materialConfiguration['id'] ?? null,
                'configuration_snapshot' => $materialConfiguration ? json_encode($materialConfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                'component_item_code_snapshot' => $line['component_item_code'] ?: $template?->componentItem?->item_code,
                'component_item_name_snapshot' => $line['component_item_name'] ?: $template?->componentItem?->item_name,
                'component_spec_snapshot' => $template?->componentItem?->spec,
                'cut_length_mm_snapshot' => $line['cut_length_mm'],
                'cutting_requirement_snapshot' => $line['cut_length_mm'] === null ? null : json_encode([
                    'length_mm' => $line['cut_length_mm'], 'width_mm' => $line['cut_width_mm'],
                    'thickness_mm' => $line['cut_thickness_mm'], 'allow_rotation' => $line['allow_cut_rotation'],
                    'piece_qty' => $line['piece_qty'], 'source' => $line['cut_requirement_source'],
                ], JSON_THROW_ON_ERROR),
                'per_output_piece_qty' => $perOutputPieces,
                'required_piece_qty' => $requiredPieces,
                'unit_id' => $unit?->id,
                'unit_name_snapshot' => $unit?->unit_name,
                'per_output_qty' => $perOutput,
                'loss_rate' => $lossRate,
                'fixed_qty' => $fixedQty,
                'required_qty' => $required,
                'base_unit_id' => $baseUnit?->id,
                'base_unit_name_snapshot' => $baseUnit?->unit_name,
                'base_required_qty' => $required,
                'issued_qty' => 0,
                'returned_qty' => 0,
                'remaining_qty' => $required,
                'status' => 'OPEN',
                'business_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();
    }

    public function loadMatchedBom(array $gate): Bom
    {
        $bomId = (int) data_get($gate, 'bom.bom_id', 0);
        $bom = Bom::query()->with(['items.componentItem.unit', 'items.unit', 'outputItem.unit'])->lockForUpdate()->find($bomId);
        if (! $bom) $this->fail('bom_missing', '发布时 BOM 已不存在，请重新执行发布 Gate。', 409);
        return $bom;
    }

    private function releasedResult(WorkOrder $workOrder): array
    {
        $version = WorkOrderReleaseGateCheck::query()
            ->where('work_order_id', $workOrder->id)
            ->max('work_order_version');
        $rows = $version === null ? collect() : WorkOrderReleaseGateCheck::query()
            ->where('work_order_id', $workOrder->id)
            ->where('work_order_version', $version)
            ->orderBy('id')
            ->get();
        $checks = $rows->map(fn (WorkOrderReleaseGateCheck $row): array => [
            'key' => $row->check_key,
            'status' => $row->status,
            'reason_code' => $row->reason_code,
            'message' => $row->message,
            'evidence' => $row->evidence ?: [],
        ])->all();

        if ($checks === []) {
            $checks[] = $this->check('release_evidence', false, 'release_evidence_missing', '已发布工单缺少可审计的发布 Gate 记录。', []);
        }
        $allowed = collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed');
        $bomSnapshot = is_array($workOrder->bom_snapshot) ? $workOrder->bom_snapshot : [];

        return [
            'allowed' => $allowed,
            'status' => $allowed ? 'passed' : 'unavailable',
            'immutable' => true,
            'work_order_id' => (int) $workOrder->id,
            'work_order_version' => (int) $workOrder->business_version,
            'evaluated_work_order_version' => $version === null ? null : (int) $version,
            'bom' => $bomSnapshot === [] ? null : [
                'bom_id' => isset($bomSnapshot['bom_id']) ? (int) $bomSnapshot['bom_id'] : (int) $workOrder->bom_id,
                'bom_no' => $bomSnapshot['bom_no'] ?? null,
                'bom_name' => $bomSnapshot['bom_name'] ?? null,
                'version' => $bomSnapshot['version'] ?? $workOrder->bom_version,
                'line_count' => isset($bomSnapshot['material_line_count']) ? (int) $bomSnapshot['material_line_count'] : null,
            ],
            'output_plan' => app(WorkOrderOutputPlanService::class)->projection($workOrder),
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed')),
            'evaluated_at' => optional($rows->first()?->evaluated_at)->toISOString() ?: optional($workOrder->release_gate_checked_at)->toISOString(),
        ];
    }

    private function bomProjection(?Bom $bom, ?array $resolvedSnapshot = null): ?array
    {
        if (! $bom) return null;

        return [
            'bom_id' => (int) $bom->id,
            'bom_no' => $bom->bom_no,
            'bom_name' => $bom->bom_name,
            'version' => $bom->version,
            'line_count' => count($resolvedSnapshot['items'] ?? $bom->items),
            'resolution_source' => $resolvedSnapshot['resolution_source'] ?? 'approved_bom',
            'resolved_items' => $resolvedSnapshot['items'] ?? null,
        ];
    }

    private function persist(WorkOrder $workOrder, array $checks, object $user, bool $allowed): void
    {
        $evaluatedAt = now();
        // 未发布单的当前版本只保留本次适用的检查；旧版本发布证据保持原样。
        WorkOrderReleaseGateCheck::query()->where('work_order_id', $workOrder->id)
            ->where('work_order_version', (int) $workOrder->business_version)
            ->whereNotIn('check_key', array_column($checks, 'key'))->delete();
        foreach ($checks as $check) {
            WorkOrderReleaseGateCheck::query()->updateOrCreate([
                'work_order_id' => $workOrder->id,
                'work_order_version' => (int) $workOrder->business_version,
                'check_key' => $check['key'],
            ], [
                'status' => $check['status'],
                'reason_code' => $check['reason_code'],
                'message' => $check['message'],
                'evidence' => $check['evidence'],
                'evaluated_by_legacy_id' => (int) ($user->legacy_id ?? $user->id ?? 0),
                'evaluated_at' => $evaluatedAt,
            ]);
        }
        $workOrder->release_gate_status = $allowed ? 'passed' : 'blocked';
        $workOrder->release_gate_checked_at = $evaluatedAt;
        $workOrder->save();
    }

    private function check(string $key, bool $passed, string $reasonCode, string $message, array $evidence): array
    {
        return [
            'key' => $key,
            'status' => $passed ? 'passed' : 'blocked',
            'reason_code' => $passed ? null : $reasonCode,
            'message' => $passed ? '通过' : $message,
            'evidence' => $evidence,
        ];
    }

    private function bomEffective(?Bom $bom): bool
    {
        if (! $bom) return false;
        $today = now()->toDateString();
        return $bom->audit_status === 'approved'
            && in_array((string) $bom->status, ['active', 'enabled', 'published'], true)
            && (! $bom->effective_date || $bom->effective_date->format('Y-m-d') <= $today)
            && (! $bom->expire_date || $bom->expire_date->format('Y-m-d') >= $today);
    }

    private function bomComplete(?Bom $bom): bool
    {
        return $bom !== null && $bom->items->isNotEmpty() && $bom->items->every(
            fn ($line): bool => (float) $line->qty > 0 || (float) $line->fixed_qty > 0
        );
    }

    private function customDocumentsReady($line): bool
    {
        if (! $line || ! $line->is_special_customized) return true;
        return ! empty($line->drawing_snapshot) || ! empty($line->technical_attachment_snapshot);
    }

    private function materialSupplyCoverage(WorkOrder $workOrder, ?Bom $bom): array
    {
        if (! Schema::hasTable('erp_routing_operation_material_supply_rules')) {
            return ['valid' => false, 'missing_component_item_ids' => [], 'invalid_component_item_ids' => [],
                'schema_missing' => true];
        }
        $operationRows = collect((array) data_get($workOrder->routing_snapshot, 'operations', []))
            ->filter(fn ($row) => ($row['execution_context'] ?? 'production') === 'production')->sortBy('sequence')->values();
        if ($workOrder->source_type === 'stock_prebuild' && $workOrder->target_routing_operation_id) {
            $target = $operationRows->firstWhere('routing_operation_id', (int) $workOrder->target_routing_operation_id);
            if ($target) $operationRows = $operationRows->where('sequence', '<=', (int) $target['sequence'])->values();
        }
        $operationIds = $operationRows->pluck('routing_operation_id')->filter()->map(fn ($id) => (int) $id)->values();
        if (! $bom || $operationIds->isEmpty()) return ['valid' => false, 'missing_component_item_ids' => [], 'invalid_component_item_ids' => []];

        $rules = $operationRows->flatMap(fn (array $operation) => collect((array) ($operation['material_supply_rules'] ?? []))
            ->map(fn (array $rule) => (object) $rule))
            ->filter(fn (object $rule): bool => $operationIds->contains((int) ($rule->target_routing_operation_id ?? 0)))
            ->groupBy('component_item_id');
        $missing = [];
        $invalid = [];
        foreach ($bom->items as $line) {
            $rows = $rules->get($line->component_item_id, collect());
            if ($rows->isEmpty()) {
                $missing[] = (int) $line->component_item_id;
                continue;
            }
            $ratio = (float) $rows->sum('required_qty_ratio');
            if (abs($ratio - 1.0) > 0.000001) $invalid[] = (int) $line->component_item_id;
        }

        return [
            'valid' => $missing === [] && $invalid === [],
            'missing_component_item_ids' => array_values(array_unique($missing)),
            'invalid_component_item_ids' => array_values(array_unique($invalid)),
        ];
    }

    private function isPositiveIntegerDecimal(string $quantity): bool
    {
        $normalized = trim($quantity);
        if (! preg_match('/^\d+(?:\.0+)?$/', $normalized)) return false;
        return (float) $normalized > 0;
    }

    private function assertVisible(WorkOrder $workOrder, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) {
            $this->fail('data_scope_denied', '当前用户不在该工单的数据范围内。', 403);
        }
    }

    private function assertPermission(array $permissions, string $required): void
    {
        if (! in_array($required, $permissions, true)) {
            $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $required]);
        }
    }

    private function fail(string $code, string $message, int $status = 422, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }
}
