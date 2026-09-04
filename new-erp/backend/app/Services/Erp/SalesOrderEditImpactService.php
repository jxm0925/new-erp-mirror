<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\SalesOrderChange;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Models\Erp\SalesOrderChangeCandidateApproval;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLog;
use App\Models\Erp\SalesOrderProductionRequirement;
use App\Models\Erp\SalesOrderVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only write path for a confirmed-order edit.  It deliberately separates
 * candidate facts from official order facts: preview/submit never touches
 * lines, reservations, fulfillment, receivable or production requirements.
 */
class SalesOrderEditImpactService
{
    private const HEADER_FIELDS = [
        'origin_order_no', 'trade_type', 'order_source', 'platform', 'platform2',
        'customer_id', 'customer_contact_id', 'customer_address_id', 'customer_kind',
        'customer_name', 'customer_phone', 'contact_name', 'contact_phone',
        'country_id', 'province_id', 'city_id', 'area_id', 'address', 'full_address',
        'order_time', 'required_delivery_date', 'is_urgent', 'is_delay',
        'delay_date', 'freight_amount', 'currency', 'remark', 'carrier_id',
        'default_carrier_id', 'carrier_fee', 'shipping_snapshot', 'logistics_snapshot',
        'customer_snapshot', 'payment_terms_snapshot', 'funding_policy_id',
        'funding_policy_snapshot', 'sales_channel_id', 'external_order_no', 'transaction_mode',
        'platform_buyer_id',
        'pay_type', 'sales_user_legacy_id',
    ];

    /** Derived snapshots travel with a candidate but are represented by their editable business fields. */
    private const HIDDEN_HEADER_DIFF_FIELDS = [
        'shipping_snapshot', 'logistics_snapshot', 'customer_snapshot',
        'funding_policy_snapshot',
    ];

    private const LABELS = [
        'origin_order_no' => '原始单号', 'remark' => '订单备注', 'customer_name' => '客户',
        'contact_name' => '收货联系人', 'contact_phone' => '联系电话', 'full_address' => '收货地址',
        'platform' => '成交平台', 'carrier_id' => '快递选择', 'default_carrier_id' => '默认承运方',
        'carrier_fee' => '预估快递费', 'freight_amount' => '运费', 'required_delivery_date' => '要求交期',
        'is_urgent' => '是否加急', 'is_delay' => '是否延期', 'delay_date' => '延期发货日期',
        'payment_terms_snapshot' => '付款规则', 'funding_policy_id' => '资金策略',
        'unit_price' => '销售单价', 'discount_rate' => '折扣率', 'tax_rate' => '税率',
        'price_tax_mode' => '含税方式', 'order_qty' => '订单行数量',
        'sku_id' => '订单行 SKU', 'product_id' => '订单行 Product', 'line_removed' => '删除订单行',
        'line_added' => '新增订单行', 'fulfillment_method' => '履约方式',
        'electric' => '电压', 'need_pump' => '原水泵控制', 'is_customized' => '普通定制',
        'is_special_customized' => '特殊定制', 'configuration_snapshot' => '生产关键配置',
    ];

    private const LINE_FIELDS = [
        'product_id', 'sku_id', 'order_qty', 'unit_price', 'discount_rate', 'tax_rate',
        'price_tax_mode', 'fulfillment_method', 'electric', 'need_pump',
        'is_customized', 'is_special_customized', 'configuration_snapshot', 'remark',
    ];

    private const APPROVAL_NEUTRAL_DESCRIPTIONS = [
        'business' => '本次修改未触发业务审核条件。',
        'finance' => '本次修改未触发财务审核条件。',
        'fulfillment' => '本次修改未触发库存/交付履约复核条件。',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SalesOrderLineService $lines,
        private readonly SalesOrderAmountService $amounts,
        private readonly SalesOrderFundingGateService $fundingGates,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function preview(SalesOrder $order, array $payload): array
    {
        $this->assertChangeable($order);
        return $this->analyse($order, $this->candidatePayload($payload, $order));
    }

    /**
     * Uses the same legal boundary as the actual preview and submit paths, so
     * the detail page never advertises a confirmed order as editable when the
     * transaction would reject it later.
     */
    public function eligibility(SalesOrder $order): array
    {
        try {
            $this->assertChangeable($order);
            return ['allowed' => true, 'reason' => null, 'field' => null];
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            return [
                'allowed' => false,
                'field' => array_key_first($errors),
                'reason' => collect($errors)->flatten()->first() ?: '当前订单不能编辑。',
            ];
        }
    }

    public function submit(int $orderId, array $payload, string $operator): array
    {
        return DB::transaction(function () use ($orderId, $payload, $operator) {
            $order = $this->lockedOrder($orderId);
            $this->assertChangeable($order);
            $candidatePayload = $this->candidatePayload($payload, $order);
            $impact = $this->analyse($order, $candidatePayload);
            if ($impact['change_count'] === 0) {
                throw ValidationException::withMessages(['order' => '未检测到需要保存的订单修改。']);
            }

            if (!$impact['requires_approval']) {
                $this->applyOfficial($order, $candidatePayload, $impact, $operator, null, trim((string) ($payload['change_reason'] ?? '')));
                return ['mode' => 'immediate', 'impact' => $impact, 'order' => $order->fresh(['lines.product', 'lines.sku'])];
            }

            if (SalesOrderChangeCandidate::query()->where('sales_order_id', $order->id)
                ->where('candidate_status', 'PENDING_APPROVAL')->exists()) {
                throw ValidationException::withMessages(['order' => '当前订单已有待审核的修改版本，请先完成或拒绝该版本。']);
            }
            $reason = trim((string) ($payload['change_reason'] ?? ''));
            if ($reason === '') throw ValidationException::withMessages(['change_reason' => '提交审核必须填写变更原因。']);
            $submittedAt = now();
            $candidateDiffs = $this->auditDiffs($impact['diffs'], $order, $impact, $operator, false, $submittedAt);
            $candidate = SalesOrderChangeCandidate::create([
                'candidate_no' => $this->numbers->next('sales_order_candidate', 'SOC'),
                'sales_order_id' => $order->id,
                'base_version' => $impact['base_version'],
                'candidate_version' => $impact['candidate_version'],
                'candidate_status' => 'PENDING_APPROVAL',
                'submitted_by' => $operator,
                'submitted_at' => $submittedAt,
                'change_reason' => $reason,
                'candidate_order_snapshot' => $candidatePayload,
                'structured_diffs' => $candidateDiffs,
                'impact_summary' => Arr::except($impact, ['diffs']),
                'approval_requirements' => $impact['required_approval_types'],
                'approval_reasons' => $impact['approval_reasons'],
                'production_impact' => $impact['production_impact'],
            ]);
            foreach ($impact['required_approval_types'] as $type) {
                SalesOrderChangeCandidateApproval::create(['candidate_id' => $candidate->id, 'approval_type' => $type]);
            }
            app(\App\Services\Erp\ApprovalIntegrations\SalesOrderChangeApprovalIntegration::class)->submit($candidate);
            $this->log($order, 'candidate_submitted', 'confirmed', 'confirmed', $operator, [
                'candidate_no' => $candidate->candidate_no, 'candidate_version' => $candidate->candidate_version,
                'impact' => $impact['approval_summary'],
            ], '订单修改已形成候选版本，正式订单及预留保持不变。');
            return ['mode' => 'candidate', 'impact' => $impact, 'candidate' => $candidate->load('approvals')];
        });
    }

    public function decide(int $candidateId, string $type, bool $approved, string $operator, ?string $comment): SalesOrderChangeCandidate
    {
        return DB::transaction(function () use ($candidateId, $type, $approved, $operator, $comment) {
            $candidate = SalesOrderChangeCandidate::query()->with(['approvals', 'order.lines', 'order.shipments', 'order.salesReturns'])
                ->lockForUpdate()->findOrFail($candidateId);
            if ($candidate->candidate_status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['candidate' => '该候选版本已结束，不能再次审核。']);
            }
            $approval = $candidate->approvals->firstWhere('approval_type', $type);
            if (!$approval) throw ValidationException::withMessages(['approval_type' => '当前候选版本不需要此类审核。']);
            if ($approval->approval_status !== 'PENDING') throw ValidationException::withMessages(['approval' => '该审核节点已处理。']);
            if (!$approved && trim((string) $comment) === '') {
                throw ValidationException::withMessages(['comment' => '拒绝候选版本必须填写拒绝原因。']);
            }
            $approval->update([
                'approval_status' => $approved ? 'APPROVED' : 'REJECTED', 'approver' => $operator,
                'comment' => trim((string) $comment) ?: null, 'decided_at' => now(),
            ]);
            if (!$approved) {
                $candidate->update(['candidate_status' => 'REJECTED']);
                $this->log($candidate->order, 'candidate_rejected', 'confirmed', 'confirmed', $operator,
                    ['candidate_no' => $candidate->candidate_no, 'approval_type' => $type, 'reason' => $comment], '候选订单修改已拒绝，正式订单未变更。');
                return $candidate->fresh('approvals');
            }
            $candidate->load('approvals');
            if ($candidate->approvals->every(fn ($row) => $row->approval_status === 'APPROVED')) {
                $order = $this->lockedOrder($candidate->sales_order_id);
                $currentVersion = $this->version($order);
                if ($currentVersion !== (int) $candidate->base_version) {
                    $candidate->update(['candidate_status' => 'CONFLICTED', 'conflict_reason' => '订单正式版本已变化，请基于最新版本重新编辑并提交。']);
                    return $candidate->fresh('approvals');
                }
                $this->applyOfficial($order, (array) $candidate->candidate_order_snapshot, (array) $candidate->impact_summary + [
                    'diffs' => (array) $candidate->structured_diffs,
                    'production_impact' => (array) $candidate->production_impact,
                ], $operator, $candidate);
                $candidate->update(['candidate_status' => 'APPROVED', 'activated_by' => $operator, 'activated_at' => now()]);
            }
            return $candidate->fresh('approvals');
        });
    }

    private function analyse(SalesOrder $order, array $candidate): array
    {
        $diffs = [];
        $facts = $this->operationalFacts($order);
        foreach (self::HEADER_FIELDS as $field) {
            if (in_array($field, self::HIDDEN_HEADER_DIFF_FIELDS, true)) continue;
            $before = $this->headerValue($field, $order->getAttribute($field));
            $after = $this->headerValue($field, $candidate['header'][$field] ?? $order->getAttribute($field));
            if ($before === $after) continue;
            $impacts = $this->headerImpacts($field, $before, $after, $facts);
            $diffs[] = $this->diff('header', null, $field, $before, $after, $impacts);
        }
        $current = $order->lines->keyBy('id');
        $sentIds = [];
        foreach ($candidate['lines'] as $line) {
            $id = (int) ($line['id'] ?? 0);
            if ($id > 0) $sentIds[] = $id;
            $old = $id ? $current->get($id) : null;
            if (!$old) {
                $diffs[] = $this->diff('line', null, 'line_added', '—', $line['sku_name'] ?? $line['sku_id'] ?? '新增 SKU', ['COMMERCIAL', 'FULFILLMENT', 'PRODUCTION']);
                continue;
            }
            foreach (self::LINE_FIELDS as $field) {
                $before = $this->lineValue($field, $old->getAttribute($field), $old);
                $after = $this->lineValue($field, $line[$field] ?? $old->getAttribute($field), $line);
                if ($before === $after) continue;
                $diffs[] = $this->diff('line', $old->id, $field, $before, $after, $this->lineImpacts($field, $old, $before, $after, $facts));
            }
        }
        foreach ($current as $id => $line) {
            if (in_array((int) $id, $candidate['deleted_line_ids'], true) || !in_array((int) $id, $sentIds, true)) {
                $diffs[] = $this->diff('line', $id, 'line_removed', $line->sku_name ?: $line->product_name, '—', ['FULFILLMENT', 'PRODUCTION']);
            }
        }
        $types = collect($diffs)->pluck('impact_types')->flatten()->unique()->values()->all();
        $required = [];
        if (in_array('COMMERCIAL', $types, true) || in_array('PRODUCTION', $types, true)) $required[] = 'business';
        if (in_array('FINANCIAL', $types, true)) $required[] = 'finance';
        if (in_array('FULFILLMENT', $types, true)) $required[] = 'fulfillment';
        $approvalReasons = $this->approvalReasons($diffs, $required);
        $production = [
            'affects_production' => in_array('PRODUCTION', $types, true),
            'affects_bom' => in_array('PRODUCTION', $types, true),
            'affects_routing' => in_array('PRODUCTION', $types, true),
            'affects_quantity' => collect($diffs)->contains('semantic_key', 'order_qty'),
            'affects_delivery' => collect($diffs)->pluck('semantic_key')->intersect(['required_delivery_date', 'is_delay', 'delay_date'])->isNotEmpty(),
        ];
        $base = $this->version($order);
        return [
            'base_version' => $base,
            // Candidate versions are an immutable audit sequence. A rejected
            // Candidate does not advance the official version, but it must not
            // be overwritten or re-used by the next submission.
            'candidate_version' => $this->nextCandidateVersion($order, $base),
            'change_count' => count($diffs), 'diffs' => $diffs,
            'overall_risk_level' => count($required) >= 2 ? 'high' : (count($required) ? 'medium' : 'low'),
            'approval_summary' => ['none' => count($diffs) - count(array_filter($diffs, fn ($diff) => $diff['approval_requirements'])), 'business' => count(array_filter($diffs, fn ($d) => in_array('business', $d['approval_requirements'], true))), 'finance' => count(array_filter($diffs, fn ($d) => in_array('finance', $d['approval_requirements'], true))), 'fulfillment' => count(array_filter($diffs, fn ($d) => in_array('fulfillment', $d['approval_requirements'], true)))],
            'requires_approval' => !empty($required), 'required_approval_types' => $required,
            'approval_reasons' => $approvalReasons,
            'candidate_effect_summary' => empty($required) ? '本次修改保存后立即生效，并记录修改历史。' : '审核通过前，正式订单、预留、履约及应收保持不变。',
            'production_impact' => $production,
            'business_facts' => $facts,
        ];
    }

    private function applyOfficial(SalesOrder $order, array $candidate, array $impact, string $operator, ?SalesOrderChangeCandidate $candidateModel, ?string $changeReason = null): void
    {
        $before = $order->fresh(['lines', 'fulfillments', 'productionRequirements'])->toArray();
        $order->update($candidate['header']);
        $this->lines->sync($order, $candidate['lines'], $candidate['deleted_line_ids'], null, $operator);
        $this->amounts->refresh($order);
        $hasOperationalImpact = collect($impact['diffs'] ?? [])->contains(fn ($diff) => collect($diff['impact_types'] ?? [])->intersect(['FULFILLMENT', 'PRODUCTION'])->isNotEmpty());
        if ($hasOperationalImpact) {
            $this->reservations->releaseForSalesOrder($order, '订单候选版本生效，释放旧履约预留。');
            SalesOrderFulfillment::where('sales_order_id', $order->id)->whereIn('demand_status', ['pending', 'confirmed'])->update(['demand_status' => 'superseded', 'reservation_status' => 'superseded', 'production_requirement_status' => 'superseded']);
            SalesOrderProductionRequirement::where('sales_order_id', $order->id)->where('is_active', true)->whereIn('requirement_status', ['draft', 'blocked', 'ready'])->update(['requirement_status' => 'superseded', 'is_active' => false]);
            $order->update(['change_status' => 'applied', 'fulfillment_status' => 'pending', 'production_confirm_status' => 'pending']);
        }
        $this->fundingGates->refreshProjection($order->fresh());
        $after = $order->fresh(['lines', 'fulfillments', 'productionRequirements'])->toArray();
        $versionNo = $candidateModel?->candidate_version ?: $this->nextCandidateVersion($order, $this->version($order));
        $appliedAt = now();
        $structuredDiffs = $candidateModel
            ? (array) $candidateModel->structured_diffs
            : $this->auditDiffs((array) ($impact['diffs'] ?? []), $order, [
                'base_version' => $this->version($order), 'candidate_version' => $versionNo,
            ], $operator, true, $appliedAt);
        $impactSummary = Arr::except($impact, ['diffs']);
        $change = SalesOrderChange::create([
            'change_no' => $this->numbers->next('sales_order_change', 'SOC'),
            'sales_order_id' => $order->id,
            'candidate_id' => $candidateModel?->id,
            'version_no' => $versionNo,
            'reason' => $candidateModel?->change_reason ?: ($changeReason ?: '普通订单编辑'),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'structured_diffs' => $structuredDiffs,
            'impact_summary' => $impactSummary,
            'approval_requirements' => (array) ($impact['required_approval_types'] ?? []),
            'approval_reasons' => (array) ($impact['approval_reasons'] ?? []),
            'immediate_effect' => $candidateModel === null,
            'operator' => $operator,
            'applied_at' => $appliedAt,
        ]);
        SalesOrderVersion::create([
            'sales_order_id' => $order->id,
            'candidate_id' => $candidateModel?->id,
            'version_no' => $versionNo,
            'change_type' => $candidateModel ? 'candidate_approved' : 'confirmed_order_edit',
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'structured_diffs' => $structuredDiffs,
            'impact_summary' => $impactSummary,
            'approval_reasons' => (array) ($impact['approval_reasons'] ?? []),
            'immediate_effect' => $candidateModel === null,
            'operator' => $operator,
            'remark' => $candidateModel ? '候选版本 '.$candidateModel->candidate_no.' 已生效' : '无须审核的正式订单编辑',
        ]);
        $this->log($order, 'confirmed_order_change_applied', 'confirmed', 'confirmed', $operator, [
            'change_no' => $change->change_no,
            'candidate_no' => $candidateModel?->candidate_no,
            'structured_diff_count' => count($structuredDiffs),
            'operational_effects' => $hasOperationalImpact,
        ], '正式订单变更已生效。');
    }

    private function candidatePayload(array $payload, SalesOrder $order): array
    {
        $lines = array_values($payload['lines'] ?? []);
        $sentIds = collect($lines)->pluck('id')->map(fn ($id) => (int) $id)->filter()->all();
        $omittedIds = $order->lines->pluck('id')->map(fn ($id) => (int) $id)->diff($sentIds)->all();
        $deletedIds = collect($payload['deleted_line_ids'] ?? [])->map(fn ($id) => (int) $id)
            ->merge($omittedIds)->filter()->unique()->values()->all();
        return [
            'header' => Arr::only($payload, self::HEADER_FIELDS),
            'lines' => $lines,
            'deleted_line_ids' => $deletedIds,
        ];
    }
    private function headerImpacts(string $field, mixed $before, mixed $after, array $facts): array
    {
        return match ($field) {
            // Funding rules alter production/shipment gates; payment account
            // labels (`pay_type`) remain informational when the gates do not.
            'payment_terms_snapshot', 'funding_policy_id' => ['FINANCIAL', 'FULFILLMENT'],
            'freight_amount', 'carrier_fee', 'currency', 'transaction_mode' => ['FINANCIAL'],
            'required_delivery_date', 'is_delay', 'delay_date' => $facts['has_fulfillment_facts']
                ? ['FULFILLMENT'] : ['INFO'],
            'carrier_id', 'default_carrier_id' => $facts['has_fulfillment_facts']
                ? ['FULFILLMENT'] : ['INFO'],
            'trade_type' => ['COMMERCIAL', 'FULFILLMENT'],
            'customer_id', 'customer_name', 'platform', 'sales_channel_id' => ['COMMERCIAL'],
            default => ['INFO'],
        };
    }

    private function lineImpacts(string $field, $line, mixed $before, mixed $after, array $facts): array
    {
        return match ($field) {
            'unit_price', 'discount_rate' => ['COMMERCIAL'],
            'tax_rate', 'price_tax_mode' => ['FINANCIAL'],
            'sku_id', 'product_id', 'electric', 'need_pump', 'is_customized',
            'is_special_customized', 'configuration_snapshot' => ['FULFILLMENT', 'PRODUCTION'],
            'fulfillment_method' => in_array('production', [$before, $after], true)
                ? ['FULFILLMENT', 'PRODUCTION'] : ['FULFILLMENT'],
            'order_qty' => $this->quantityImpacts($line, $facts),
            default => ['INFO'],
        };
    }

    private function quantityImpacts($line, array $facts): array
    {
        if (!$facts['has_operational_facts']) return ['COMMERCIAL'];
        $types = ['FULFILLMENT'];
        if ($line->line_type === 'physical' && $facts['has_production_facts']) $types[] = 'PRODUCTION';
        return $types;
    }
    private function diff(string $scope, ?int $lineId, string $key, mixed $before, mixed $after, array $types): array {
        $requirements = array_values(array_unique(array_filter([
            in_array('COMMERCIAL', $types, true) || in_array('PRODUCTION', $types, true) ? 'business' : null,
            in_array('FINANCIAL', $types, true) ? 'finance' : null,
            in_array('FULFILLMENT', $types, true) ? 'fulfillment' : null,
        ])));
        return ['scope' => $scope, 'line_id' => $lineId, 'semantic_key' => $key, 'label' => self::LABELS[$key] ?? $key, 'before' => $before, 'after' => $after, 'impact_types' => $types, 'business_impact_text' => $this->impactText($types), 'approval_requirements' => $requirements];
    }
    private function impactText(array $types): string { return implode('、', array_map(fn ($t) => ['INFO'=>'无业务影响','COMMERCIAL'=>'商业信息影响','FINANCIAL'=>'财务结算影响','FULFILLMENT'=>'履约与库存影响','PRODUCTION'=>'生产需求影响'][$t], $types)); }
    private function approvalReasons(array $diffs, array $requiredTypes): array
    {
        $result = [];
        foreach (['business', 'finance', 'fulfillment'] as $type) {
            $required = in_array($type, $requiredTypes, true);
            $reasons = $required
                ? collect($diffs)
                    ->filter(fn (array $diff) => in_array($type, (array) ($diff['approval_requirements'] ?? []), true))
                    ->map(fn (array $diff) => $this->approvalReasonForDiff($diff, $type))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
                : [];
            $result[$type] = [
                'required' => $required,
                'reasons' => $reasons,
                'description' => $required
                    ? $this->approvalReasonDescription($reasons)
                    : self::APPROVAL_NEUTRAL_DESCRIPTIONS[$type],
            ];
        }
        return $result;
    }

    private function approvalReasonDescription(array $reasons): string
    {
        $visible = array_map(
            fn (string $reason) => rtrim($reason, '。； '),
            array_slice($reasons, 0, 3)
        );
        $description = implode('；', $visible);
        $remaining = count($reasons) - count($visible);
        if ($remaining > 0) $description .= '；另有 '.$remaining.' 项影响。';
        return $description !== '' ? rtrim($description, '。').'。' : '本次修改触发审核条件。';
    }

    private function approvalReasonForDiff(array $diff, string $type): ?string
    {
        $key = (string) ($diff['semantic_key'] ?? '');
        $label = (string) ($diff['label'] ?? $key);

        return match ($type) {
            'business' => match ($key) {
                'unit_price' => '销售单价发生调整，形成商业条件变化，需要业务审核。',
                'discount_rate' => '订单折扣发生调整，形成商业条件变化，需要业务审核。',
                'sku_id' => 'SKU 身份发生变化，需要重新核对产品定义和生产影响。',
                'product_id' => 'Product 身份发生变化，需要重新核对产品定义和生产影响。',
                'line_added' => '新增订单行改变了订单商品和生产需求范围，需要业务审核。',
                'line_removed' => '删除订单行改变了订单商品和生产需求范围，需要业务审核。',
                'order_qty' => in_array('PRODUCTION', (array) ($diff['impact_types'] ?? []), true)
                    ? '订单行数量发生调整，影响生产需求数量，需要业务审核。'
                    : '订单行数量发生调整，形成商业数量变化，需要业务审核。',
                'electric', 'need_pump', 'is_customized', 'is_special_customized', 'configuration_snapshot' => '生产关键配置发生变化，需要重新核对生产定义。',
                'fulfillment_method' => '订单行履约方式切换涉及生产定义，需要业务审核。',
                'trade_type' => '贸易类型发生变化，影响订单商业口径，需要业务审核。',
                'customer_id', 'customer_name' => '订单客户发生变化，需要业务审核。',
                'platform', 'sales_channel_id' => '成交平台或销售渠道发生变化，需要业务审核。',
                default => $label.'发生变化，触发商业或生产定义审核条件。',
            },
            'finance' => match ($key) {
                'payment_terms_snapshot' => '付款规则发生变化，影响资金门禁和应收口径，需要财务审核。',
                'funding_policy_id' => '资金策略发生变化，影响资金门禁和应收口径，需要财务审核。',
                'freight_amount', 'carrier_fee' => '订单费用发生变化，影响应收和结算金额，需要财务审核。',
                'currency' => '订单币种发生变化，影响结算和本位币金额，需要财务审核。',
                'transaction_mode' => '交易方式发生变化，影响财务结算口径，需要财务审核。',
                'tax_rate', 'price_tax_mode' => '税率或含税方式发生变化，影响税额和结算金额，需要财务审核。',
                default => $label.'发生变化，触发财务审核条件。',
            },
            'fulfillment' => match ($key) {
                'payment_terms_snapshot' => '付款规则发生变化，影响生产或发货资金门槛，需要履约复核。',
                'funding_policy_id' => '资金策略发生变化，影响生产或发货资金门槛，需要履约复核。',
                'required_delivery_date', 'is_delay', 'delay_date' => '要求交期发生调整，影响现有库存或交付履约计划，需要履约复核。',
                'carrier_id', 'default_carrier_id' => '承运方式发生变化，影响现有交付履约安排，需要履约复核。',
                'sku_id' => 'SKU 身份发生变化，需要重新核对库存和交付履约。',
                'product_id' => 'Product 身份发生变化，需要重新核对库存和交付履约。',
                'line_added' => '新增订单行改变了库存和交付履约范围，需要履约复核。',
                'line_removed' => '删除订单行改变了库存和交付履约范围，需要履约复核。',
                'order_qty' => '订单行数量发生调整，影响既有库存预留或交付数量，需要履约复核。',
                'fulfillment_method' => '订单行履约方式发生变化，需要重新核对库存和交付安排。',
                'electric', 'need_pump', 'is_customized', 'is_special_customized', 'configuration_snapshot' => '生产关键配置发生变化，需要重新核对履约配置。',
                'trade_type' => '贸易类型发生变化，影响交付履约口径，需要履约复核。',
                default => $label.'发生变化，触发库存或交付履约复核条件。',
            },
            default => null,
        };
    }

    private function headerValue(string $field, mixed $value): string
    {
        if ($field === 'trade_type' && ($value === null || $value === '')) return 'domestic';
        if ($field === 'customer_kind' && ($value === null || $value === '')) return 'individual';
        if ($value === null || $value === '') return '';
        if (in_array($field, ['freight_amount', 'carrier_fee'], true)) return $this->decimal($value);
        if (in_array($field, ['required_delivery_date', 'delay_date'], true)) {
            return $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : substr((string) $value, 0, 10);
        }
        if (in_array($field, ['order_time'], true)) {
            try {
                return $value instanceof \DateTimeInterface
                    ? $value->format('Y-m-d H:i:s')
                    : Carbon::parse((string) $value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return (string) $value;
            }
        }
        return $this->scalar($value);
    }

    private function lineValue(string $field, mixed $value, mixed $source): string
    {
        if ($field === 'sku_id') {
            if (is_array($source)) return (string) ($source['sku_name'] ?? $value ?? '');
            return (string) ($source->sku_name ?: $value ?: '');
        }
        if ($field === 'product_id') {
            if (is_array($source)) return (string) ($source['product_name'] ?? $value ?? '');
            return (string) ($source->product_name ?: $value ?: '');
        }
        if (in_array($field, ['order_qty', 'unit_price', 'discount_rate', 'tax_rate'], true)) return $this->decimal($value);
        if (in_array($field, ['configuration_snapshot'], true)) return $this->canonicalJson($value);
        if (in_array($field, ['need_pump', 'is_customized', 'is_special_customized'], true)) return $value === null ? '' : ((bool) $value ? '是' : '否');
        return $this->scalar($value);
    }

    private function decimal(mixed $value): string
    {
        if (!is_numeric($value)) return $this->scalar($value);
        $normalized = rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');
        return $normalized === '-0' || $normalized === '' ? '0' : $normalized;
    }

    private function scalar(mixed $value): string { return is_array($value) ? $this->canonicalJson($value) : (string)($value ?? ''); }

    private function canonicalJson(mixed $value): string
    {
        if (!is_array($value)) return (string) ($value ?? '');
        $sort = function (array $input) use (&$sort): array {
            foreach ($input as $key => $item) if (is_array($item)) $input[$key] = $sort($item);
            if (!array_is_list($input)) ksort($input);
            return $input;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function operationalFacts(SalesOrder $order): array
    {
        $hasReservations = InventoryReservation::query()
            ->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
            ->where('source_order_id', $order->id)
            ->whereIn('reservation_status', ['active', 'partially_released'])
            ->exists();
        $hasFulfillment = SalesOrderFulfillment::query()
            ->where('sales_order_id', $order->id)
            ->whereNotIn('demand_status', ['superseded', 'cancelled'])
            ->exists();
        $hasProduction = SalesOrderProductionRequirement::query()
            ->where('sales_order_id', $order->id)
            ->where('is_active', true)
            ->whereNotIn('requirement_status', ['superseded', 'cancelled'])
            ->exists();
        return [
            'has_inventory_reservations' => $hasReservations,
            'has_fulfillment_facts' => $hasReservations || $hasFulfillment || $hasProduction,
            'has_production_facts' => $hasProduction,
            'has_operational_facts' => $hasReservations || $hasFulfillment || $hasProduction,
        ];
    }

    private function auditDiffs(array $diffs, SalesOrder $order, array $impact, string $operator, bool $immediate, Carbon $at): array
    {
        return array_map(fn (array $diff): array => $diff + [
            'sales_order_id' => $order->id,
            'base_version' => (int) ($impact['base_version'] ?? $this->version($order)),
            'target_version' => (int) ($impact['candidate_version'] ?? 0),
            'edited_by' => $operator,
            'edited_at' => $at->toDateTimeString(),
            'immediate_effect' => $immediate,
        ], $diffs);
    }
    private function version(SalesOrder $order): int { return (int) SalesOrderVersion::where('sales_order_id', $order->id)->max('version_no'); }
    private function nextCandidateVersion(SalesOrder $order, int $baseVersion): int
    {
        $lastCandidate = (int) SalesOrderChangeCandidate::query()
            ->where('sales_order_id', $order->id)
            ->max('candidate_version');
        return max($baseVersion, $lastCandidate) + 1;
    }
    private function lockedOrder(int $id): SalesOrder { return SalesOrder::query()->with(['lines', 'shipments', 'salesReturns'])->lockForUpdate()->findOrFail($id); }
    private function assertChangeable(SalesOrder $order): void {
        if ($order->order_status !== 'confirmed' || $order->confirm_status !== 'confirmed') throw ValidationException::withMessages(['order_status' => '只有已正式确认的订单可编辑；草稿订单请直接保存。']);
        if ($order->shipment_status !== 'not_shipped' || $order->lines->sum('shipped_qty') > 0 || $order->shipments->isNotEmpty()) throw ValidationException::withMessages(['shipment_status' => '已有发货事实的订单不能直接编辑，请走售后流程。']);
        if ($order->salesReturns->whereNotIn('return_status', ['cancelled'])->isNotEmpty()) throw ValidationException::withMessages(['sales_return' => '订单已进入销售退货流程，不能直接编辑。']);
        $consumedProduction = SalesOrderProductionRequirement::where('sales_order_id', $order->id)
            ->where(function ($query): void {
                $query->where('consumed_qty', '>', 0)
                    ->orWhereIn('requirement_status', ['partially_consumed', 'consumed', 'closed']);
            })->exists();
        if ($consumedProduction) {
            throw ValidationException::withMessages(['production_requirement' => '生产需求已被工单或完工事实消耗，不能直接编辑订单。']);
        }
    }
    private function log(SalesOrder $order, string $action, string $before, string $after, string $operator, array $payload, string $content): void { SalesOrderLog::create(['sales_order_id'=>$order->id,'action'=>$action,'before_status'=>$before,'after_status'=>$after,'payload'=>$payload,'operator'=>$operator,'content'=>$content]); }
}
