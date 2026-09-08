<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

final class WorkOrderCompletionService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProductionDataScopeResolver $scopeResolver,
    ) {}

    public function preflight(int $workOrderId, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, 'production.completion.create');
        $workOrder = WorkOrder::query()->find($workOrderId);
        if (! $workOrder) $this->fail('work_order_not_found', '工单不存在。', 404);
        $this->visible($workOrder, $user, $permissions, $superAdmin);
        return $this->preflightFor($workOrder);
    }

    public function paginate(int $workOrderId, int $page, int $perPage, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, 'production.completion.view');
        $workOrder = WorkOrder::query()->find($workOrderId);
        if (! $workOrder) $this->fail('work_order_not_found', '工单不存在。', 404);
        $this->visible($workOrder, $user, $permissions, $superAdmin);
        $query = DB::table('erp_work_order_completions')->where('work_order_id', $workOrderId);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get()
            ->map(fn ($row) => $this->completionProjection($row, true, $permissions))->all();
        return ['data' => $rows, 'current_page' => $page, 'per_page' => $perPage, 'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage))];
    }

    public function submit(int $workOrderId, array $payload, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, 'production.completion.create');
        return $this->command('submit_work_order_completion', 'work_order', $workOrderId, $payload, $user,
            function () use ($workOrderId, $payload, $user, $permissions, $superAdmin): array {
                $workOrder = WorkOrder::query()->lockForUpdate()->find($workOrderId);
                if (! $workOrder) $this->fail('work_order_not_found', '工单不存在。', 404);
                $this->visible($workOrder, $user, $permissions, $superAdmin);
                if ((int) $workOrder->business_version !== (int) $payload['expected_version']) {
                    $this->fail('version_conflict', '工单版本已变化，请刷新后重试。', 409, ['current_version' => (int) $workOrder->business_version]);
                }
                if (! in_array($workOrder->status, ['RELEASED', 'IN_PROGRESS'], true)) {
                    $this->fail('work_order_not_completable', '只有已发布或生产中的工单可以提交完工。', 409);
                }
                $preflight = $this->preflightFor($workOrder);
                if (! $preflight['passed']) $this->fail('completion_preflight_failed', '完工前检查未全部通过。', 409, ['checks' => $preflight['checks']]);

                $ids = array_values(array_unique(array_map('intval', $payload['output_record_ids'] ?? [])));
                if ($ids === []) $this->fail('completion_outputs_required', '必须选择至少一条终末工序产出。');
                $outputs = ProductionOutputRecord::query()->where('work_order_id', $workOrderId)->whereIn('id', $ids)->lockForUpdate()->get();
                if ($outputs->count() !== count($ids)) $this->fail('completion_output_not_found', '存在不属于当前工单的产出记录。', 404);
                foreach ($outputs as $output) {
                    if (! $this->isTerminalOutput($output)) $this->fail('completion_output_not_terminal', '只有终末工序产出可以提交工单完工。', 409);
                    if ($output->status !== 'WAIT_COMPLETION') $this->fail('completion_output_not_ready', '终末产出尚未完成工序质检，或已提交完工。', 409);
                    $active = DB::table('erp_work_order_completion_lines as line')
                        ->join('erp_work_order_completions as completion', 'completion.id', '=', 'line.completion_id')
                        ->where('line.output_record_id', $output->id)->whereIn('completion.status', ['PENDING_REVIEW', 'APPROVED'])->exists();
                    if ($active) $this->fail('completion_output_already_submitted', '终末产出已经存在待审核或已审核完工事实。', 409);
                }

                $totals = ['submitted' => 0.0, 'qualified' => 0.0, 'unqualified' => 0.0, 'scrapped' => 0.0];
                foreach ($outputs as $output) {
                    $source = $this->sourceTarget($output);
                    $qualified = (float) $output->output_base_qty;
                    $unqualified = $output->source_target_type === 'quantity_operation' ? (float) ($source->unqualified_base_qty ?? 0) : 0;
                    $scrapped = $output->source_target_type === 'quantity_operation' ? (float) ($source->scrapped_base_qty ?? 0) : 0;
                    $totals['qualified'] += $qualified; $totals['unqualified'] += $unqualified; $totals['scrapped'] += $scrapped;
                    $totals['submitted'] += $qualified + $unqualified + $scrapped;
                }
                $now = now();
                $completionId = DB::table('erp_work_order_completions')->insertGetId([
                    'completion_no' => $this->numbers->next('work_order_completion', 'WOC'),
                    'client_command_id' => $payload['client_command_id'], 'work_order_id' => $workOrder->id,
                    'status' => 'PENDING_REVIEW', 'submitted_base_qty' => $totals['submitted'],
                    'qualified_base_qty' => $totals['qualified'], 'unqualified_base_qty' => $totals['unqualified'],
                    'scrapped_base_qty' => $totals['scrapped'], 'defect_reason' => $payload['defect_reason'] ?? null,
                    'remark' => $payload['remark'] ?? null,
                    'attachment_snapshot' => empty($payload['attachments']) ? null : json_encode(array_values($payload['attachments']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'preflight_snapshot' => json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'submitted_by_legacy_id' => $this->userId($user), 'submitted_at' => $now,
                    'organization_code' => $workOrder->organization_code, 'business_version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($outputs as $output) {
                    $source = $this->sourceTarget($output);
                    $qualified = (float) $output->output_base_qty;
                    $unqualified = $output->source_target_type === 'quantity_operation' ? (float) ($source->unqualified_base_qty ?? 0) : 0;
                    $scrapped = $output->source_target_type === 'quantity_operation' ? (float) ($source->scrapped_base_qty ?? 0) : 0;
                    DB::table('erp_work_order_completion_lines')->insert([
                        'completion_id' => $completionId, 'output_record_id' => $output->id,
                        'source_target_type' => $output->source_target_type, 'source_target_id' => $output->source_target_id,
                        'output_item_id' => $output->output_item_id, 'base_unit_id' => $workOrder->base_unit_id,
                        'submitted_base_qty' => $qualified + $unqualified + $scrapped,
                        'qualified_base_qty' => $qualified, 'unqualified_base_qty' => $unqualified, 'scrapped_base_qty' => $scrapped,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $output->update(['status' => 'PENDING_COMPLETION_REVIEW', 'business_version' => (int) $output->business_version + 1]);
                }
                $workOrder->business_version = (int) $workOrder->business_version + 1;
                $workOrder->save();
                return $this->completionProjection(DB::table('erp_work_order_completions')->where('id', $completionId)->first());
            });
    }

    public function review(int $completionId, array $payload, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, 'production.completion.review');
        return $this->command('review_work_order_completion', 'work_order_completion', $completionId, $payload, $user,
            function () use ($completionId, $payload, $user, $permissions, $superAdmin): array {
                $completion = DB::table('erp_work_order_completions')->where('id', $completionId)->lockForUpdate()->first();
                if (! $completion) $this->fail('completion_not_found', '完工事实不存在。', 404);
                $workOrder = WorkOrder::query()->lockForUpdate()->find($completion->work_order_id);
                $this->visible($workOrder, $user, $permissions, $superAdmin);
                if ((int) $completion->business_version !== (int) $payload['expected_version']) {
                    $this->fail('version_conflict', '完工事实版本已变化，请刷新后重试。', 409, ['current_version' => (int) $completion->business_version]);
                }
                if ($completion->status !== 'PENDING_REVIEW') $this->fail('completion_already_reviewed', '该完工事实已完成审核。', 409);
                $decision = (string) $payload['decision'];
                $reason = trim((string) ($payload['reason'] ?? ''));
                if (! in_array($decision, ['approve', 'reject'], true)) $this->fail('completion_decision_invalid', '完工审核决定无效。');
                if ($decision === 'reject' && $reason === '') $this->fail('completion_reject_reason_required', '驳回完工必须填写原因。');
                $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
                $now = now();
                DB::table('erp_work_order_completions')->where('id', $completionId)->update([
                    'status' => $status, 'reviewed_by_legacy_id' => $this->userId($user), 'reviewed_at' => $now,
                    'review_reason' => $reason ?: null, 'business_version' => (int) $completion->business_version + 1, 'updated_at' => $now,
                ]);
                $lines = DB::table('erp_work_order_completion_lines')->where('completion_id', $completionId)->get();
                foreach ($lines as $line) {
                    $output = ProductionOutputRecord::query()->lockForUpdate()->find($line->output_record_id);
                    if (! $output || $output->status !== 'PENDING_COMPLETION_REVIEW') $this->fail('completion_output_state_changed', '完工关联产出状态已变化。', 409);
                    $next = 'WAIT_COMPLETION';
                    if ($decision === 'approve') {
                        $warehouse = $output->output_mode_snapshot === 'warehouse_required'
                            || ($output->output_mode_snapshot === 'warehouse_optional' && $output->disposition === 'warehouse');
                        $next = $warehouse ? 'WAIT_WAREHOUSE' : 'COMPLETED';
                    }
                    $output->update(['status' => $next, 'business_version' => (int) $output->business_version + 1]);
                }
                if ($decision === 'approve') $this->refreshWorkOrderStatus($workOrder, $user, $completionId, $now);
                return $this->completionProjection(DB::table('erp_work_order_completions')->where('id', $completionId)->first());
            });
    }

    public function approvedLineForOutput(int $outputId): ?object
    {
        return DB::table('erp_work_order_completion_lines as line')
            ->join('erp_work_order_completions as completion', 'completion.id', '=', 'line.completion_id')
            ->where('line.output_record_id', $outputId)->where('completion.status', 'APPROVED')
            ->orderByDesc('completion.id')->first(['line.*', 'completion.status as completion_status']);
    }

    private function preflightFor(WorkOrder $workOrder): array
    {
        $activeLabor = DB::table('erp_production_labor_sessions as labor')->join('erp_production_tasks as task', 'task.id', '=', 'labor.task_id')
            ->where('task.work_order_id', $workOrder->id)->where('labor.status', 'ACTIVE')->count();
        $remainingReports = (float) DB::table('erp_production_quantity_operations')->where('work_order_id', $workOrder->id)->sum('remaining_base_qty');
        $openReturns = DB::table('erp_production_material_returns')->where('work_order_id', $workOrder->id)->whereIn('status', ['SUBMITTED', 'WAIT_QUALITY'])->count();
        $allTerminalOutputs = ProductionOutputRecord::query()->where('work_order_id', $workOrder->id)->get()
            ->filter(fn (ProductionOutputRecord $output) => $this->isTerminalOutput($output));
        $reportedBaseQty = (float) $allTerminalOutputs->sum(function (ProductionOutputRecord $output): float {
            $source = $this->sourceTarget($output);
            $unqualified = $output->source_target_type === 'quantity_operation' ? (float) ($source->unqualified_base_qty ?? 0) : 0.0;
            $scrapped = $output->source_target_type === 'quantity_operation' ? (float) ($source->scrapped_base_qty ?? 0) : 0.0;
            return (float) $output->output_base_qty + $unqualified + $scrapped;
        });
        $terminalOutputs = $allTerminalOutputs
            ->filter(fn (ProductionOutputRecord $output) => $output->status === 'WAIT_COMPLETION')
            ->map(function (ProductionOutputRecord $output): array {
                $source = $this->sourceTarget($output);
                $unqualified = $output->source_target_type === 'quantity_operation' ? (float) ($source->unqualified_base_qty ?? 0) : 0.0;
                $scrapped = $output->source_target_type === 'quantity_operation' ? (float) ($source->scrapped_base_qty ?? 0) : 0.0;
                return ['output_record_id' => (int) $output->id, 'output_no' => $output->output_no,
                    'source_target_type' => $output->source_target_type, 'source_target_id' => (int) $output->source_target_id,
                    'submitted_base_qty' => (float) $output->output_base_qty + $unqualified + $scrapped,
                    'qualified_base_qty' => (float) $output->output_base_qty,
                    'unqualified_base_qty' => $unqualified, 'scrapped_base_qty' => $scrapped,
                    'business_version' => (int) $output->business_version];
            })->values()->all();
        $checks = [
            ['key' => 'labor_ended', 'passed' => $activeLabor === 0, 'value' => $activeLabor],
            ['key' => 'reports_settled', 'passed' => $remainingReports <= 0.00000001, 'value' => $remainingReports],
            ['key' => 'material_returns_settled', 'passed' => $openReturns === 0, 'value' => $openReturns],
            ['key' => 'terminal_outputs_ready', 'passed' => $terminalOutputs !== [], 'value' => count($terminalOutputs)],
        ];
        $workOrder->loadMissing(['outputItem', 'demand.line']);
        $line = $workOrder->demand?->line;
        return ['work_order_id' => (int) $workOrder->id, 'work_order_no' => $workOrder->work_order_no,
            'work_order_business_version' => (int) $workOrder->business_version,
            'status' => $workOrder->status,
            'production_execution_mode' => $workOrder->production_execution_mode_snapshot,
            'product' => ['name' => $line?->product_name ?: $workOrder->outputItem?->item_name,
                'sku' => $line?->sku_name ?: $workOrder->outputItem?->item_code,
                'specification' => data_get($line?->sku_snapshot, 'spec_text') ?: $workOrder->outputItem?->spec,
                'image' => data_get($line?->sku_snapshot, 'image') ?: data_get($line?->product_snapshot, 'image')],
            'plan' => ['planned_date' => optional($workOrder->planned_date)->format('Y-m-d'),
                'production_batch' => $workOrder->production_batch],
            'quantity' => ['planned_base_qty' => (float) $workOrder->target_base_qty,
                'reported_base_qty' => $reportedBaseQty,
                'ready_completion_base_qty' => (float) collect($terminalOutputs)->sum('qualified_base_qty'),
                'base_unit_name' => $workOrder->base_unit_name_snapshot ?: $workOrder->target_unit_name_snapshot],
            'passed' => collect($checks)->every(fn (array $check) => $check['passed']),
            'checks' => $checks, 'terminal_outputs' => $terminalOutputs];
    }

    private function refreshWorkOrderStatus(WorkOrder $workOrder, object $user, int $completionId, $now): void
    {
        $approved = (float) DB::table('erp_work_order_completions')->where('work_order_id', $workOrder->id)->where('status', 'APPROVED')->sum('submitted_base_qty');
        if ($approved + 0.00000001 < (float) $workOrder->target_base_qty) return;
        $beforeStatus = (string) $workOrder->status; $beforeVersion = (int) $workOrder->business_version;
        $workOrder->status = 'COMPLETED'; $workOrder->business_version = $beforeVersion + 1; $workOrder->save();
        DB::table('erp_work_order_status_logs')->insert([
            'work_order_id' => $workOrder->id, 'before_status' => $beforeStatus, 'after_status' => 'COMPLETED',
            'reason' => '完工事实审核通过', 'operator_legacy_id' => $this->userId($user),
            'organization_code' => $workOrder->organization_code, 'before_version' => $beforeVersion,
            'after_version' => (int) $workOrder->business_version,
            'occurred_at' => $now, 'created_at' => $now,
        ]);
    }

    private function completionProjection(object $row, bool $relations = false, array $permissions = []): array
    {
        $data = ['completion_id' => (int) $row->id, 'completion_no' => $row->completion_no,
            'work_order_id' => (int) $row->work_order_id, 'status' => $row->status,
            'submitted_base_qty' => (float) $row->submitted_base_qty, 'qualified_base_qty' => (float) $row->qualified_base_qty,
            'unqualified_base_qty' => (float) $row->unqualified_base_qty, 'scrapped_base_qty' => (float) $row->scrapped_base_qty,
            'defect_reason' => $row->defect_reason, 'remark' => $row->remark,
            'submitted_by_legacy_id' => (int) $row->submitted_by_legacy_id, 'submitted_at' => (string) $row->submitted_at,
            'reviewed_by_legacy_id' => $row->reviewed_by_legacy_id ? (int) $row->reviewed_by_legacy_id : null,
            'reviewed_at' => $row->reviewed_at ? (string) $row->reviewed_at : null, 'review_reason' => $row->review_reason,
            'business_version' => (int) $row->business_version,
            'allowed_actions' => ['review' => $row->status === 'PENDING_REVIEW'
                && in_array('production.completion.review', $permissions, true)]];
        if (! $relations) return $data;

        $data['attachments'] = $row->attachment_snapshot ? json_decode($row->attachment_snapshot, true) ?: [] : [];
        $legacyUsers = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', array_filter([
            $row->submitted_by_legacy_id, $row->reviewed_by_legacy_id,
        ]))->pluck('nickname', 'legacy_id');
        $data['submitted_by_name'] = $legacyUsers[$row->submitted_by_legacy_id] ?? null;
        $data['reviewed_by_name'] = $row->reviewed_by_legacy_id ? ($legacyUsers[$row->reviewed_by_legacy_id] ?? null) : null;
        $lines = DB::table('erp_work_order_completion_lines as line')
            ->join('erp_production_output_records as output', 'output.id', '=', 'line.output_record_id')
            ->leftJoin('erp_items as item', 'item.id', '=', 'line.output_item_id')
            ->where('line.completion_id', $row->id)
            ->select('line.*', 'output.output_no', 'output.status as output_status', 'output.business_version as output_business_version',
                'output.output_mode_snapshot', 'output.disposition', 'item.item_code', 'item.item_name')
            ->orderBy('line.id')->get();
        $postedByLine = DB::table('erp_work_order_finished_goods_receipts')
            ->where('completion_id', $row->id)->where('status', 'POSTED')
            ->select('completion_line_id', DB::raw('SUM(posted_base_qty) as posted_base_qty'))
            ->groupBy('completion_line_id')->pluck('posted_base_qty', 'completion_line_id');
        $data['lines'] = $lines->map(function (object $line) use ($postedByLine): array {
            $requiresWarehouse = $line->output_mode_snapshot === 'warehouse_required'
                || ($line->output_mode_snapshot === 'warehouse_optional' && $line->disposition === 'warehouse');
            $posted = (float) ($postedByLine[$line->id] ?? 0);
            return ['completion_line_id' => (int) $line->id, 'output_record_id' => (int) $line->output_record_id,
                'output_no' => $line->output_no, 'output_status' => $line->output_status,
                'output_business_version' => (int) $line->output_business_version,
                'item_code' => $line->item_code, 'item_name' => $line->item_name,
                'submitted_base_qty' => (float) $line->submitted_base_qty,
                'qualified_base_qty' => (float) $line->qualified_base_qty,
                'unqualified_base_qty' => (float) $line->unqualified_base_qty,
                'scrapped_base_qty' => (float) $line->scrapped_base_qty,
                'requires_warehouse' => $requiresWarehouse, 'posted_base_qty' => $posted,
                'remaining_receivable_base_qty' => $requiresWarehouse ? max(0, (float) $line->qualified_base_qty - $posted) : 0.0];
        })->all();
        $data['receipts'] = DB::table('erp_work_order_finished_goods_receipts as receipt')
            ->leftJoin('erp_warehouses as warehouse', 'warehouse.id', '=', 'receipt.warehouse_id')
            ->leftJoin('erp_locations as location', 'location.id', '=', 'receipt.location_id')
            ->leftJoin('erp_legacy_admin_users as operator', 'operator.legacy_id', '=', 'receipt.posted_by_legacy_id')
            ->where('receipt.completion_id', $row->id)->where('receipt.status', 'POSTED')
            ->orderBy('receipt.id')->get(['receipt.*', 'warehouse.warehouse_name', 'location.location_name', 'operator.nickname as posted_by_name'])
            ->map(fn (object $receipt): array => ['receipt_id' => (int) $receipt->id, 'receipt_no' => $receipt->receipt_no,
                'completion_line_id' => (int) $receipt->completion_line_id, 'output_record_id' => (int) $receipt->output_record_id,
                'posted_base_qty' => (float) $receipt->posted_base_qty, 'warehouse_id' => (int) $receipt->warehouse_id,
                'warehouse_name' => $receipt->warehouse_name, 'location_id' => (int) $receipt->location_id,
                'location_name' => $receipt->location_name, 'batch_no' => $receipt->batch_no,
                'posted_by_legacy_id' => (int) $receipt->posted_by_legacy_id, 'posted_by_name' => $receipt->posted_by_name,
                'posted_at' => (string) $receipt->posted_at, 'inventory_transaction_id' => (int) $receipt->inventory_transaction_id])->all();
        $receivable = (float) collect($data['lines'])->where('requires_warehouse', true)->sum('qualified_base_qty');
        $posted = (float) collect($data['receipts'])->sum('posted_base_qty');
        $data['receipt_progress'] = ['receivable_base_qty' => $receivable, 'posted_base_qty' => $posted,
            'remaining_base_qty' => max(0, $receivable - $posted)];
        return $data;
    }

    private function isTerminalOutput(ProductionOutputRecord $output): bool
    {
        $source = $this->sourceTarget($output); if (! $source) return false;
        $table = $output->source_target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
        $query = DB::table($table)->where('sequence_no_snapshot', '>', $source->sequence_no_snapshot);
        $output->source_target_type === 'unit_operation' ? $query->where('production_unit_id', $source->production_unit_id) : $query->where('work_order_id', $source->work_order_id);
        return ! $query->exists();
    }

    private function sourceTarget(ProductionOutputRecord $output): ?object
    {
        $table = $output->source_target_type === 'unit_operation' ? 'erp_production_unit_operations'
            : ($output->source_target_type === 'quantity_operation' ? 'erp_production_quantity_operations' : null);
        return $table ? DB::table($table)->where('id', $output->source_target_id)->first() : null;
    }

    private function visible(WorkOrder $workOrder, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) $this->fail('data_scope_denied', '当前用户不可访问该工单。', 403);
    }

    private function command(string $type, string $aggregateType, int $aggregateId, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload + ['aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId]; ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return DB::transaction(function () use ($type, $aggregateType, $aggregateId, $payload, $user, $action, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->command_type !== $type || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
                if ($existing->status !== 'succeeded' || ! is_array($existing->response_snapshot)) $this->fail('command_processing', '相同命令正在处理中，请稍后查询结果。', 409);
                return $existing->response_snapshot;
            }
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type,
                'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
            $result = $action();
            $ledger->update(['result_type' => $aggregateType, 'result_id' => $result['completion_id'] ?? $aggregateId,
                'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该完工操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
