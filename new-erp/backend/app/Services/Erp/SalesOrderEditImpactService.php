<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;
use App\Models\Erp\SalesChannel;
use App\Models\Erp\SalesFundingPolicy;
use App\Models\Erp\SalesOrderAttachment;
use App\Models\Erp\Sku;
use App\Models\Erp\SalesOrderChange;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Models\Erp\SalesOrderChangeCandidateApproval;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLog;
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
    public const APPROVAL_WRITABLE_FIELDS = [
        'remark' => ['label' => '订单备注', 'type' => 'text'],
        'origin_order_no' => ['label' => '原始单号', 'type' => 'text'],
        'external_order_no' => ['label' => '外部订单号', 'type' => 'text'],
        'platform_buyer_id' => ['label' => '平台买家账号', 'type' => 'text'],
        'is_urgent' => ['label' => '是否加急', 'type' => 'boolean'],
    ];

    private const HEADER_FIELDS = [
        'origin_order_no', 'trade_type', 'order_source', 'platform', 'platform2',
        'customer_id', 'customer_contact_id', 'customer_address_id', 'customer_kind',
        'customer_name', 'customer_phone', 'contact_name', 'contact_phone',
        'country_id', 'province_id', 'city_id', 'area_id', 'address', 'full_address',
        'order_time', 'required_delivery_date', 'is_urgent', 'is_delay',
        'delay_date', 'freight_amount', 'currency', 'remark', 'carrier_id',
        'default_carrier_id', 'shipping_snapshot', 'logistics_snapshot',
        'customer_snapshot', 'payment_terms_snapshot', 'funding_policy_id',
        'funding_policy_snapshot', 'sales_channel_id', 'channel_type_snapshot', 'external_order_no', 'transaction_mode',
        'platform_buyer_id', 'payment_method_id', 'logistics_requirement', 'is_share', 'share_user',
        'pay_type', 'sales_user_legacy_id', 'agreed_deposit_amount',
    ];

    /** Derived snapshots travel with a candidate but are represented by their editable business fields. */
    private const HIDDEN_HEADER_DIFF_FIELDS = [
        'shipping_snapshot', 'logistics_snapshot', 'customer_snapshot',
        'funding_policy_snapshot', 'payment_terms_snapshot', 'customer_phone', 'pay_type',
    ];

    /** 快照里允许销售编辑的子字段：只合并这些键，发货事实等其余键沿用正式订单。 */
    private const SNAPSHOT_EDIT_FIELDS = [
        'shipping_snapshot' => ['is_self_pickup' => '是否自提', 'customer_logistics_note' => null],
        'logistics_snapshot' => [
            'pcs' => '件数（PCS）', 'gw' => '毛重（KG）', 'vol' => '体积（CBM）',
            'si_date' => '截单时间（SI）', 'cy_date' => '截关时间（CY）', 'cargo_ready_date' => '货好时间',
        ],
    ];

    /** 订单行配置里真正影响生产的键；报价有效期等其他键只作信息记录。 */
    private const PRODUCTION_CONFIG_KEYS = [
        'need_pump', 'electric', 'is_customized', 'is_special_customized', 'special_custom_description', 'cut_requirements',
    ];

    private const LABELS = [
        'origin_order_no' => '原始单号', 'remark' => '订单备注', 'customer_name' => '客户名称',
        'customer_id' => '客户档案', 'customer_contact_id' => '联系人档案', 'customer_address_id' => '收货地址档案',
        'contact_name' => '收货联系人', 'contact_phone' => '联系电话', 'full_address' => '收货地址',
        'platform' => '成交平台', 'carrier_id' => '客户指定物流', 'default_carrier_id' => '客户指定物流',
        'carrier_fee' => '预估快递费', 'freight_amount' => '运费', 'required_delivery_date' => '要求交期',
        'is_urgent' => '是否加急', 'is_delay' => '是否延期', 'delay_date' => '延期发货日期',
        'payment_terms_snapshot' => '付款规则', 'funding_policy_id' => '资金策略',
        'unit_price' => '销售单价', 'discount_rate' => '折扣率', 'tax_rate' => '税率',
        'price_tax_mode' => '含税方式', 'order_qty' => '订单行数量',
        'sku_id' => '订单行 SKU', 'product_id' => '订单行 Product', 'line_removed' => '删除订单行',
        'line_added' => '新增订单行', 'fulfillment_method' => '备货方式',
        'electric' => '电压', 'need_pump' => '原水泵控制', 'is_customized' => '普通定制',
        'is_special_customized' => '特殊定制', 'configuration_snapshot' => '生产关键配置',
        'customer_kind' => '客户类型', 'platform_buyer_id' => '平台买家ID', 'payment_method_id' => '付款方式',
        'logistics_requirement' => '客户物流备注', 'is_share' => '共享订单', 'share_user' => '共享人员',
        'valid_days' => '报价有效期', 'trade_type' => '贸易类型',
        'external_order_no' => '外部订单号', 'sales_channel_id' => '销售渠道', 'channel_type_snapshot' => '成交平台收款类型', 'currency' => '币种',
        'transaction_mode' => '交易方式', 'sales_user_legacy_id' => '销售人员', 'order_time' => '下单时间',
        'agreed_deposit_amount' => '约定定金',
        'platform2' => '子平台', 'order_source' => '订单来源',
    ];

    private const LINE_FIELDS = [
        'product_id', 'sku_id', 'order_qty', 'unit_price', 'discount_rate', 'tax_rate',
        'price_tax_mode', 'fulfillment_method', 'electric', 'need_pump',
        'is_customized', 'is_special_customized', 'configuration_snapshot', 'remark',
    ];

    private const APPROVAL_NEUTRAL_DESCRIPTIONS = [
        'business' => '本次修改未触发业务审核条件。',
        'finance' => '本次修改未触发财务审核条件。',
        'fulfillment' => '本次修改未触发库存交付审核条件。',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SalesOrderLineService $lines,
        private readonly SalesOrderAmountService $amounts,
        private readonly SalesOrderStateCoordinator $orderStates,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function preview(SalesOrder $order, array $payload): array
    {
        $this->assertChangeable($order);
        return $this->analyse($order, $this->candidatePayload($payload, $order));
    }

    public function updateApprovalFields(int $orderId, array $updates, string $operator, int $taskId): void
    {
        DB::transaction(function () use ($orderId, $updates, $operator, $taskId) {
            if (!$updates || array_diff(array_keys($updates), array_keys(self::APPROVAL_WRITABLE_FIELDS))) {
                throw ValidationException::withMessages(['completion_action' => '订单字段不允许由审批完成动作直接更新。']);
            }
            $order = $this->lockedOrder($orderId);
            $this->assertChangeable($order);
            if (array_key_exists('origin_order_no', $updates) || array_key_exists('external_order_no', $updates)) {
                $updates = array_replace($updates, SalesTradePlatformService::originalNumberFields($updates, $order, $order->channel_type_snapshot === 'online_platform'));
            }
            // 复用正式订单变更与版本记录；完整保留明细，避免局部字段更新被解释为删除订单行。
            $candidate = ['header' => $updates, 'lines' => $order->lines->toArray(), 'deleted_line_ids' => []];
            $impact = $this->analyse($order, $candidate);
            if (!$impact['change_count']) return;
            if (collect($impact['diffs'])->contains(fn ($diff) => array_diff($diff['impact_types'], ['INFO']))) {
                throw ValidationException::withMessages(['completion_action' => '该字段变化涉及业务审核，不能作为附加字段动作执行。']);
            }
            if (array_key_exists('remark', $updates)) $candidate['header']['order_remark'] = $updates['remark'];
            if (array_key_exists('is_urgent', $updates)) $candidate['header']['quickly'] = $updates['is_urgent'];
            $this->applyOfficial($order, $candidate, $impact, $operator, null, '审批任务 '.$taskId.' 的字段更新');
        });
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

    /** 首次生产确认后即进入审核阶段；重新确认前也不能退回直接编辑。保留原接口标识。 */
    public function productionReleased(SalesOrder $order): bool
    {
        return $order->production_confirm_status === 'confirmed'
            || $order->logs()->where('action', 'production_confirm')->exists()
            || app(\App\Services\Erp\Manufacturing\WorkOrderService::class)->salesOrderHasStartedWork((int) $order->id)
            || $order->shipments()->whereIn('shipment_status', ['draft', 'pending_outbound', 'prepared'])->exists()
            || SalesOrderFulfillmentPolicy::hasShipmentFacts($order);
    }

    public function submit(int $orderId, array $payload, string $operator, ?object $actor = null): array
    {
        return DB::transaction(function () use ($orderId, $payload, $operator, $actor) {
            $order = $this->lockedOrder($orderId);
            $this->assertChangeable($order);
            $candidatePayload = $this->candidatePayload($payload, $order);
            $impact = $this->analyse($order, $candidatePayload, true);
            if (!($impact['can_submit'] ?? true)) throw ValidationException::withMessages(['production_unit_selections' =>
                implode('；', $impact['production_selection_errors'] ?? []) ?: '请先选择本次要调整的主机和半成品编号，再重新预览确认。']);
            if ($impact['change_count'] === 0) {
                throw ValidationException::withMessages(['order' => '未检测到需要保存的订单修改。']);
            }

            if (SalesOrderChangeCandidate::query()->where('sales_order_id', $order->id)->where('candidate_status', 'PENDING_APPROVAL')->exists()) {
                throw ValidationException::withMessages(['order' => '当前订单已有待审核的修改版本，请先完成或拒绝该版本。']);
            }

            if (!$impact['requires_approval']) {
                $this->applyOfficial($order, $candidatePayload, $impact, $operator, null, trim((string) ($payload['change_reason'] ?? '')), $actor);
                return ['mode' => 'immediate', 'impact' => $impact, 'order' => $order->fresh(['lines.product', 'lines.sku'])];
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

    public function decide(int $candidateId, string $type, bool $approved, string $operator, ?string $comment, ?object $actor = null): SalesOrderChangeCandidate
    {
        return DB::transaction(function () use ($candidateId, $type, $approved, $operator, $comment, $actor) {
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
            if ($approved) {
                // 旧候选不能沿用缺失财务等审核的旧规则直接生效，必须保留原记录并重新提交。
                try {
                    $currentImpact = $this->analyse($this->lockedOrder($candidate->sales_order_id), (array) $candidate->candidate_order_snapshot);
                } catch (ValidationException $exception) {
                    $candidate->update(['candidate_status' => 'CONFLICTED', 'conflict_reason' => collect($exception->errors())->flatten()->first()]);
                    return $candidate->fresh('approvals');
                }
                if (!($currentImpact['can_submit'] ?? true)) {
                    $candidate->update(['candidate_status' => 'CONFLICTED', 'conflict_reason' => implode('；', $currentImpact['production_selection_errors'] ?? []) ?: '所选设备的归属或状态已变化，请重新选择编号。']);
                    return $candidate->fresh('approvals');
                }
                if (array_diff($currentImpact['required_approval_types'], $candidate->approvals->pluck('approval_type')->all())) {
                    $candidate->update(['candidate_status' => 'CONFLICTED', 'conflict_reason' => '该修改版本缺少当前必需的审核类别，请基于最新订单重新编辑并提交。']);
                    return $candidate->fresh('approvals');
                }
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
                $eligibility = $this->eligibility($order);
                if (! $eligibility['allowed']) {
                    $candidate->update(['candidate_status' => 'CONFLICTED', 'conflict_reason' => $eligibility['reason']]);
                    return $candidate->fresh('approvals');
                }
                $this->applyOfficial($order, (array) $candidate->candidate_order_snapshot, $currentImpact, $operator, $candidate, null, $actor);
                $candidate->update(['candidate_status' => 'APPROVED', 'activated_by' => $operator,
                    'activated_at' => SalesOrderChange::where('candidate_id', $candidate->id)->value('applied_at')]);
            }
            return $candidate->fresh('approvals');
        });
    }

    private function analyse(SalesOrder $order, array $candidate, bool $checkSelectionVersions = false): array
    {
        $this->assertProtectedLines($order, $candidate);
        $diffs = [];
        $visibility = app(SalesCostVisibilityService::class);
        $facts = $this->operationalFacts($order);
        foreach (self::HEADER_FIELDS as $field) {
            if (in_array($field, self::HIDDEN_HEADER_DIFF_FIELDS, true)) continue;
            $stored = $order->getAttribute($field);
            if ($field === 'agreed_deposit_amount') $stored = data_get($order->funding_policy_snapshot, 'agreed_deposit_amount');
            // 旧订单的客户物流备注只存在发货快照里，按同一口径比较，避免未修改也出差异。
            if ($field === 'logistics_requirement' && blank($stored)) $stored = data_get((array) $order->shipping_snapshot, 'customer_logistics_note');
            $before = $this->headerValue($field, $stored);
            $submitted = array_key_exists($field, $candidate['header']) ? $candidate['header'][$field] : $stored;
            $after = $this->headerValue($field, $submitted);
            if ($field === 'share_user') {
                // 授权按员工身份比较，同名员工替换也必须形成变更。
                $beforeIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $stored)))));
                $afterIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $submitted)))));
                sort($beforeIds);
                sort($afterIds);
                if ($beforeIds === $afterIds) continue;
            } elseif ($before === $after) continue;
            $impacts = $this->headerImpacts($field, $before, $after, $facts);
            $diffs[] = $this->diff('header', null, $field, $before, $after, $impacts);
        }
        foreach (self::SNAPSHOT_EDIT_FIELDS as $snapshotField => $keys) {
            if (!array_key_exists($snapshotField, $candidate['header'])) continue;
            foreach ($keys as $key => $label) {
                if ($label === null) continue;
                $before = $this->snapshotValue($key, data_get((array) $order->getAttribute($snapshotField), $key));
                $after = $this->snapshotValue($key, data_get((array) $candidate['header'][$snapshotField], $key));
                if ($before === $after) continue;
                $impacts = $key === 'is_self_pickup' && $facts['has_fulfillment_facts'] ? ['FULFILLMENT'] : ['INFO'];
                $diffs[] = array_replace($this->diff('header', null, $key, $before, $after, $impacts), ['label' => $label]);
            }
        }
        $current = $order->lines->keyBy('id');
        $sentIds = [];
        foreach ($candidate['lines'] as $index => $line) {
            $id = (int) ($line['id'] ?? 0);
            if ($id > 0) $sentIds[] = $id;
            $old = $id ? $current->get($id) : null;
            if (!$old) {
                $summary = ($line['sku_name'] ?? $line['sku_id'] ?? '新增 SKU').'；数量 '.$this->decimal($line['order_qty'] ?? 0)
                    .'；单价 '.$this->decimal($line['unit_price'] ?? 0).'；折扣率 '.$this->decimal($line['discount_rate'] ?? 1)
                    .'；税率 '.$this->decimal($line['tax_rate'] ?? 0);
                $config = $this->lines->configurationSnapshot($line);
                if (filled($config['electric'])) $summary .= '；电压 '.$config['electric'];
                if ($config['need_pump'] !== null) $summary .= '；原水泵控制 '.($config['need_pump'] ? '需要' : '不需要');
                if ($config['is_customized']) $summary .= '；普通定制';
                if ($config['is_special_customized']) $summary .= '；特殊定制 '.($config['special_custom_description'] ?? '');
                $diffs[] = $this->diff('line', null, 'line_added', '—', $summary, ['COMMERCIAL', 'FINANCIAL', 'FULFILLMENT', 'PRODUCTION'], $this->lineLabel($line, $index + 1));
                continue;
            }
            foreach (self::LINE_FIELDS as $field) {
                $beforeValue = $visibility->redact($old->getAttribute($field));
                $afterValue = $visibility->redact(array_key_exists($field, $line) ? $line[$field] : $old->getAttribute($field));
                if ($field === 'configuration_snapshot') {
                    $beforeValue = $this->lines->configurationSnapshot(array_replace($old->attributesToArray(), ['configuration_snapshot' => $beforeValue]));
                    $afterValue = $this->lines->configurationSnapshot($line, $beforeValue);
                    $validBefore = trim((string) ($beforeValue['valid_days'] ?? ''));
                    $validAfter = trim((string) ($afterValue['valid_days'] ?? ''));
                    if ($validBefore !== $validAfter) {
                        $diffs[] = $this->diff('line', $old->id, 'valid_days', $validBefore, $validAfter, ['INFO'], $this->lineLabel($old, $index + 1));
                    }
                    // 只比较生产关键键；报价有效期等信息字段不能触发生产审核和备货重置。
                    $beforeValue = Arr::only($beforeValue, self::PRODUCTION_CONFIG_KEYS);
                    $afterValue = Arr::only($afterValue, self::PRODUCTION_CONFIG_KEYS);
                }
                $before = $this->lineValue($field, $beforeValue, $old);
                $after = $this->lineValue($field, $afterValue, $line);
                // 同名商品或SKU仍可能是不同身份，不能仅凭页面名称把替换判断成未修改。
                if (in_array($field, ['product_id', 'sku_id'], true)
                    ? (int) $beforeValue === (int) $afterValue
                    : $before === $after) continue;
                $diffs[] = $this->diff('line', $old->id, $field, $before, $after, $this->lineImpacts($field, $old, $before, $after, $facts), $this->lineLabel($old, $index + 1));
            }
        }
        foreach ($current->values() as $index => $line) {
            $id = (int) $line->id;
            if (in_array((int) $id, $candidate['deleted_line_ids'], true) || !in_array((int) $id, $sentIds, true)) {
                $diffs[] = $this->diff('line', $id, 'line_removed', $line->sku_name ?: $line->product_name, '—', ['FINANCIAL', 'FULFILLMENT', 'PRODUCTION'], $this->lineLabel($line, $index + 1));
            }
        }
        $types = collect($diffs)->pluck('impact_types')->flatten()->unique()->values()->all();
        // 生产确认不代表已经劳动；仅失效旧链的实际工时触发生产审核，金额仍独立审核。
        $released = $this->productionReleased($order);
        $resetLineIds = $this->resetLineIds($diffs);
        $selection = app(\App\Services\Erp\Manufacturing\OrderChangeUnitSelectionService::class)->analyse($order, $candidate, $diffs, $resetLineIds, $checkSelectionVersions);
        $productionReview = $selection['has_actual_work'];
        $deliveryReview = $released && ($facts['has_inventory_reservations'] || $facts['has_pending_shipments']);
        $required = [];
        if ($productionReview) $required[] = 'business';
        if (in_array('FINANCIAL', $types, true)) $required[] = 'finance';
        if (($deliveryReview || ($released && collect($diffs)->pluck('semantic_key')->intersect(['funding_policy_id', 'payment_terms_snapshot', 'agreed_deposit_amount'])->isNotEmpty())) && in_array('FULFILLMENT', $types, true)) $required[] = 'fulfillment';
        $diffs = array_map(function (array $diff) use ($productionReview, $selection, $required): array {
            $requirements = array_values(array_diff(array_intersect($diff['approval_requirements'], $required), ['business']));
            if ($productionReview && collect($selection['production_selection_groups'])->contains('line_id', (int) ($diff['line_id'] ?? 0))
                && in_array('PRODUCTION', $diff['impact_types'], true)) $requirements[] = 'business';
            return array_replace($diff, ['approval_requirements' => array_values(array_unique($requirements))]);
        }, $diffs);
        $approvalReasons = $this->approvalReasons($diffs, $required);
        $production = [
            'affects_production' => in_array('PRODUCTION', $types, true),
            'affects_bom' => in_array('PRODUCTION', $types, true),
            'affects_routing' => in_array('PRODUCTION', $types, true),
            'affects_quantity' => collect($diffs)->contains('semantic_key', 'order_qty'),
            'affects_delivery' => collect($diffs)->pluck('semantic_key')->intersect(['required_delivery_date', 'is_delay', 'delay_date'])->isNotEmpty(),
            'has_actual_work' => (bool) $productionReview,
            'affected_work_order_ids' => array_values(array_unique(array_column($selection['production_selection_groups'], 'work_order_id'))),
        ];
        $base = $this->version($order);
        return [
            'can_submit' => $selection['can_submit'],
            'production_selection_groups' => $selection['production_selection_groups'],
            'production_selection_errors' => $selection['production_selection_errors'],
            'production_unit_selections' => $selection['selections'],
            'quantity_adjustments' => $selection['quantity_adjustments'],
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
            'production_released' => $released,
            'edit_stage' => $required ? 'review_required' : 'free',
            'candidate_effect_summary' => (!empty($candidate['protected_line_splits']) ? '已发部分保留原商品、配置、单价及关联记录；本次新条件只用于拆出的未发余额。' : '').($required
                ? ('审核通过前，正式订单、库存预留、生产和交付安排及应收保持不变。'
                    .($facts['has_pending_shipments'] && $this->resetLineIds($diffs)
                        ? '仅撤销涉及变更商品的待发安排；正常设备退回原库位，返修设备保留返修。未变更商品的生产备货记录保留。正式生效时暂停旧任务计时，由各工单接单人选择去向。'
                        : (array_intersect($types, ['FULFILLMENT', 'PRODUCTION']) ? '仅调整发生变化的商品备货，未变更商品的生产和发货进度保留；新增商品或增加的数量单独确认备货。' : '')))
                : ($facts['has_operational_facts'] && collect($diffs)->contains(fn ($d) => collect($d['impact_types'])->intersect(['FULFILLMENT', 'PRODUCTION'])->isNotEmpty())
                    ? '本次修改保存后立即生效；仅调整发生变化的商品备货，其他商品的生产与库存记录保留。'
                    : '本次修改保存后立即生效，并记录修改历史。')),
            'production_impact' => $production,
            'business_facts' => $facts,
        ];
    }

    private function applyOfficial(SalesOrder $order, array $candidate, array $impact, string $operator, ?SalesOrderChangeCandidate $candidateModel, ?string $changeReason = null, ?object $actor = null): void
    {
        $order->load(['lines', 'shipments', 'salesReturns']);
        $this->assertChangeable($order);
        // 待审期间仍可发货；生效必须按当前已发数量和逐台归属重算，不能重放提交时的释放范围。
        $impact = $this->analyse($order, $candidate);
        // 审核期间可能新增了收款核销或附件被删，生效前按最新事实再校验一次。
        if (array_key_exists('customer_id', $candidate['header'])) $this->assertCustomerChangeAllowed($order, $candidate['header']['customer_id']);
        $this->validateCustomerIdentity($order, $candidate['header'], true, $actor);
        $this->assertSpecialCustomRequirements($order, $candidate);
        if (!empty($candidate['header']['is_share'])) {
            $candidate['header']['share_user'] = app(SalesOrderVisibilityService::class)->normalizeShareUsers($candidate['header']['share_user'] ?? null);
        }
        $candidate['header'] = $this->preserveAgreedDeposit($candidate['header'], $order);
        unset($candidate['header']['agreed_deposit_amount']);
        if (array_key_exists('remark', $candidate['header'])) $candidate['header']['order_remark'] = $candidate['header']['remark'];
        if (array_key_exists('is_urgent', $candidate['header'])) $candidate['header']['quickly'] = $candidate['header']['is_urgent'];
        if (array_key_exists('is_delay', $candidate['header'])) $candidate['header']['delay'] = $candidate['header']['is_delay'];
        $before = $order->fresh(['lines', 'fulfillments'])->toArray();
        $hasOperationalImpact = collect($impact['diffs'] ?? [])->contains(fn ($diff) => collect($diff['impact_types'] ?? [])->intersect(['FULFILLMENT', 'PRODUCTION'])->isNotEmpty());
        $resetLineIds = $this->resetLineIds((array) ($impact['diffs'] ?? []));
        $selection = app(\App\Services\Erp\Manufacturing\OrderChangeUnitSelectionService::class)->analyse($order, $candidate, (array) ($impact['diffs'] ?? []), $resetLineIds);
        if (!$selection['can_submit']) throw ValidationException::withMessages(['production_unit_selections' => implode('；', $selection['production_selection_errors']) ?: '请重新确认本次变更的设备编号。']);
        $appliedAt = now()->startOfSecond();
        foreach ($this->quantityReductions((array) ($impact['diffs'] ?? []), $resetLineIds) as $diff) {
            $line = $order->lines->firstWhere('id', (int) $diff['line_id']);
            if ($line && $line->line_type === 'physical') app(\App\Services\Erp\Manufacturing\WorkOrderService::class)->reduceSelectedSalesQuantity(
                $order, $line, (float) $diff['after'], $selection['selections'], $actor, $selection['quantity_adjustments']);
        }
        $hasLineOperationalImpact = collect($impact['diffs'] ?? [])->contains(fn ($diff) => ($diff['scope'] ?? null) === 'line'
            && collect($diff['impact_types'] ?? [])->intersect(['FULFILLMENT', 'PRODUCTION'])->isNotEmpty());
        if ($resetLineIds) app(SalesShipmentApplicationService::class)->cancelForOrderChange($order, $operator, $actor, $resetLineIds);
        // Approval can happen after internal costs change. Restore current
        // locked facts instead of replaying cost values from an old candidate.
        $order->update(app(SalesCostVisibilityService::class)->preserveStoredCosts($candidate['header'], $order->attributesToArray()));
        $changedHeaders = collect($impact['diffs'] ?? [])->where('scope', 'header')->pluck('semantic_key')->all();
        if (array_intersect($changedHeaders, ['customer_id', 'customer_name', 'customer_contact_id', 'customer_address_id', 'contact_name', 'contact_phone', 'full_address', 'address', 'customer_kind', 'platform_buyer_id'])) {
            $addressText = array_key_exists('full_address', $candidate['header']) ? $order->full_address
                : (array_key_exists('address', $candidate['header']) ? $order->address : $order->full_address);
            $address = array_replace((array) $order->shipping_address_snapshot, [
                'address_id' => $order->customer_address_id, 'receiver_name' => $order->contact_name,
                'receiver_phone' => $order->contact_phone, 'detail_address' => $addressText, 'full_address' => $addressText,
            ]);
            if (array_intersect($changedHeaders, ['full_address', 'address', 'customer_address_id'])) {
                // 手工地址不能沿用旧快照中的省市区，避免发货仍读取旧地区。
                $address = array_replace($address, ['province' => null, 'city' => null, 'district' => null]);
            }
            $order->update([
                'address' => $addressText, 'full_address' => $addressText,
                'customer_name_snapshot' => $order->customer_name,
                'contact_name_snapshot' => $order->contact_name, 'contact_phone_snapshot' => $order->contact_phone, 'customer_phone' => $order->contact_phone,
                'shipping_address_snapshot' => $address,
                'customer_snapshot' => $this->customerSnapshot($order, $address, in_array('customer_id', $changedHeaders, true)),
            ]);
            app(SalesShipmentApplicationService::class)->syncReceiverForOrderChange($order, $operator);
        }
        if (array_intersect($changedHeaders, ['carrier_id', 'default_carrier_id'])) {
            $order->update(['default_carrier_name_snapshot' => DB::table('erp_sales_order_deliveries')
                ->where('legacy_id', $order->default_carrier_id ?: $order->carrier_id)->value('name')]);
        }
        if ($hasLineOperationalImpact) $this->lines->sync($order, $candidate['lines'], $candidate['deleted_line_ids'], $candidate['draft_token'] ?? null, $operator,
            $order->lines->pluck('id')->map(fn ($id) => (int) $id)->diff($resetLineIds)->values()->all(), $candidate['protected_line_splits'] ?? []);
        else $this->saveCommercialLinesLocked($order, $candidate['lines']);
        $this->saveLineInfoFields($order, $candidate['lines'], (array) ($impact['diffs'] ?? []));
        $this->amounts->refresh($order);
        if (in_array('funding_policy_id', $changedHeaders, true)) {
            // 换付款条件后按变更后的应收重新冻结，资金门禁以新条件计算。
            $fresh = $order->fresh();
            $receivable = (string) ($fresh->final_receivable_amount ?: $fresh->total_amount);
            $snapshot = array_replace((array) $fresh->funding_policy_snapshot, ['receivable_amount' => $receivable, 'frozen_at' => now()->toISOString()]);
            $order->update(['funding_policy_snapshot' => $snapshot, 'payment_terms_snapshot' => $snapshot]);
        }
        if ($resetLineIds) {
            $this->reservations->releaseForSalesOrder($order, '订单商品变更，释放该行旧库存预留。', $resetLineIds);
            SalesOrderFulfillment::where('sales_order_id', $order->id)->whereIn('sales_order_line_id', $resetLineIds)
                ->whereIn('demand_status', ['pending', 'confirmed'])->update(['demand_status' => 'superseded', 'reservation_status' => 'superseded', 'production_requirement_status' => 'superseded']);
        }
        if ($hasLineOperationalImpact) {
            $requiresConfirmation = collect($candidate['lines'])->contains(fn ($line) => empty($line['id']) || in_array((int) $line['id'], $resetLineIds, true))
                || collect($impact['diffs'] ?? [])->contains(fn ($diff) => ($diff['semantic_key'] ?? null) === 'order_qty' && (float) $diff['after'] > (float) $diff['before']);
            $order->update(['change_status' => 'applied',
                'production_confirm_status' => $requiresConfirmation ? 'pending' : $order->production_confirm_status,
                'inventory_lock_status' => app(SalesOrderInventoryLockService::class)->lockStatus($order->fresh(['lines', 'fulfillments']))]);
        }
        $order->update(['business_version' => (int) $order->business_version + 1,
            'shipment_status' => SalesOrderFulfillmentPolicy::shipmentStatus($order->fresh('lines')->lines->map(fn ($line) => $line->getAttributes())->all())]);
        $after = $order->fresh(['lines', 'fulfillments'])->toArray();
        $versionNo = $candidateModel?->candidate_version ?: $this->nextCandidateVersion($order, $this->version($order));
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
        if ($resetLineIds || $selection['unit_ids']) app(\App\Services\Erp\Manufacturing\WorkOrderService::class)->cancelForSalesOrder(
            $order, '订单商品变更，处理本次指定编号', $actor,
            array_values(array_unique([...$resetLineIds, ...array_column($selection['selections'], 'line_id')])),
            (int) $change->id, $appliedAt, $selection['selections'], $selection['unnumbered_work_order_ids']);
        $this->orderStates->handle(SalesOrderStateCoordinator::ORDER_TERMS_CHANGED, $order->fresh());
        $after = $order->fresh(['lines', 'fulfillments'])->toArray();
        $change->update(['after_snapshot' => $after]);
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
        \App\Events\AssemblyDisplayChanged::afterCommit('order-change:'.$change->id, 'order.changed',
            \App\Models\Erp\MfgWorkOrder::query()->where('sales_order_id', $order->id)->pluck('id')->all(), (int) $order->id, (int) $change->id);
    }

    private function saveCommercialLinesLocked(SalesOrder $order, array $rows): void
    {
        $existing = $order->lines->keyBy('id');
        $commercial = app(SalesOrderChangeApplicationService::class);
        foreach ($rows as $row) {
            $line = $existing->get((int) ($row['id'] ?? 0));
            if (! $line) throw ValidationException::withMessages(['lines' => '订单行身份已变化，请按实际库存与生产影响重新提交。']);
            if (isset($row['commercial_role']) && $row['commercial_role'] !== $line->commercial_role) {
                throw ValidationException::withMessages(['lines' => '正式订单行的商业角色不能随普通金额编辑变更。']);
            }
            // Only approved commercial facts are written. The original Item, unit,
            // supply strategy and technical snapshots survive later master changes.
            $changes = $commercial->normalizeLine($line, Arr::only($row, ['order_qty', 'unit_price', 'discount_rate', 'tax_rate', 'price_tax_mode']));
            if (array_key_exists('remark', $row)) $changes['remark'] = $row['remark'];
            $line->update($changes);
        }
    }

    private function resetLineIds(array $diffs): array
    {
        // 数量增减按差额处理，不能把仍有效的设备与需求整行作废。
        return collect($diffs)->filter(fn ($diff) => ($diff['scope'] ?? null) === 'line' && !empty($diff['line_id'])
            && collect($diff['impact_types'] ?? [])->intersect(['FULFILLMENT', 'PRODUCTION'])->isNotEmpty()
            && ($diff['semantic_key'] ?? null) !== 'order_qty')
            ->pluck('line_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function quantityReductions(array $diffs, array $resetLineIds): array
    {
        return collect($diffs)->filter(fn ($diff) => ($diff['scope'] ?? null) === 'line' && ($diff['semantic_key'] ?? null) === 'order_qty'
            && (float) $diff['after'] < (float) $diff['before']
            && !in_array((int) ($diff['line_id'] ?? 0), $resetLineIds, true))->values()->all();
    }

    private function candidatePayload(array $payload, SalesOrder $order): array
    {
        if (isset($payload['expected_version']) && (int) $payload['expected_version'] !== (int) $order->business_version) {
            throw ValidationException::withMessages(['expected_version' => '订单已被修改，请刷新后按最新内容重新编辑。']);
        }
        $payload = app(SalesCostVisibilityService::class)->redact($payload);
        $lines = array_values($payload['lines'] ?? []);
        $sentIds = collect($lines)->pluck('id')->map(fn ($id) => (int) $id)->filter()->all();
        $currentIds = $order->lines->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($sentIds) !== count(array_unique($sentIds)) || array_diff($sentIds, $currentIds)) {
            throw ValidationException::withMessages(['lines' => '订单行重复或不属于当前有效订单，请刷新后重新编辑。']);
        }
        $currentLines = $order->lines->keyBy('id');
        foreach ($lines as $index => $line) {
            $existing = $currentLines->get((int) ($line['id'] ?? 0));
            if ($existing && (int) ($line['sku_id'] ?? $existing->sku_id) === (int) $existing->sku_id && array_key_exists('unit_price', $line)) {
                // 扣减项页面填正数；候选使用正式保存的负价，避免未修改的红包产生伪差异。
                $normalized = app(SalesOrderChangeApplicationService::class)->normalizeLine($existing, ['unit_price' => $line['unit_price']]);
                $lines[$index]['unit_price'] = $normalized['unit_price'];
            }
        }
        $omittedIds = $order->lines->pluck('id')->map(fn ($id) => (int) $id)->diff($sentIds)->all();
        $deletedIds = collect($payload['deleted_line_ids'] ?? [])->map(fn ($id) => (int) $id)
            ->merge($omittedIds)->filter()->unique()->values()->all();
        if (array_diff($deletedIds, $currentIds) || array_intersect($deletedIds, $sentIds)) {
            throw ValidationException::withMessages(['deleted_line_ids' => '删除行与保留行冲突或不属于当前订单，请刷新后重新编辑。']);
        }
        $candidate = [
            'header' => $this->preserveAgreedDeposit($this->normalizeHeader(Arr::only($payload, self::HEADER_FIELDS), $order), $order),
            'lines' => $lines,
            'deleted_line_ids' => $deletedIds,
            'draft_token' => $payload['draft_token'] ?? null,
            'production_unit_selections' => array_values($payload['production_unit_selections'] ?? []),
        ];
        $candidate = $this->splitProtectedLines($order, $candidate);
        $this->assertSpecialCustomRequirements($order, $candidate);
        return $candidate;
    }

    /** 同一行的新条件只作用于未发余额，原行继续承载已发及售后外键和原商业事实。 */
    private function splitProtectedLines(SalesOrder $order, array $candidate): array
    {
        $protected = SalesOrderFulfillmentPolicy::protectedLineQuantities($order);
        $rows = collect($candidate['lines'])->keyBy('id');
        $replacements = []; $deleted = $candidate['deleted_line_ids']; $splits = [];
        foreach ($order->lines as $old) {
            if (($protected[$old->id] ?? 0) <= 0.00000001) continue;
            $floor = round($protected[$old->id] + (float) $old->cancelled_qty, 8);
            $row = $rows->get($old->id);
            if (!$row || in_array((int) $old->id, $deleted, true)) {
                $replacements[(int) $old->id] = [array_replace($old->toArray(), ['order_qty' => $floor])];
                $deleted = array_values(array_diff($deleted, [(int) $old->id]));
                continue;
            }
            $qty = (float) ($row['order_qty'] ?? $old->order_qty);
            if ($qty + 0.00000001 < $floor) throw ValidationException::withMessages(['lines' => '第 '.$old->line_no.' 行数量不能少于已发及已取消数量 '.$floor.'；已发设备须通过售后处理。']);
            if (!$this->protectedTermsChanged($old, $row)) continue;
            if ($qty <= $floor + 0.00000001) throw ValidationException::withMessages(['lines' => '第 '.$old->line_no.' 行没有可修改的未发余额；新增需求请另加订单行。']);
            $new = Arr::except($row, ['id', 'line_uuid']);
            $new['line_uuid'] = (string) \Illuminate\Support\Str::uuid();
            $new['order_qty'] = round($qty - $floor, 8);
            $replacements[(int) $old->id] = [array_replace($old->toArray(), ['order_qty' => $floor]), $new];
            $splits[(int) $old->id] = ['line_uuid' => $new['line_uuid'], 'draft_line_uuid' => $row['line_uuid'] ?? $old->line_uuid];
        }
        $lines = [];
        foreach ($candidate['lines'] as $row) {
            $id = (int) ($row['id'] ?? 0);
            foreach ($replacements[$id] ?? [$row] as $replacement) $lines[] = $replacement;
            unset($replacements[$id]);
        }
        foreach ($replacements as $remaining) foreach ($remaining as $row) $lines[] = $row;
        $candidate['lines'] = $lines;
        $candidate['deleted_line_ids'] = $deleted;
        $candidate['protected_line_splits'] = $splits;
        return $candidate;
    }

    private function protectedTermsChanged($old, array $row): bool
    {
        foreach (array_diff(self::LINE_FIELDS, ['order_qty', 'remark']) as $field) {
            $before = $old->getAttribute($field); $after = $row[$field] ?? $before;
            if ($field === 'configuration_snapshot') {
                $before = $this->lines->configurationSnapshot($old->toArray());
                $after = $this->lines->configurationSnapshot($row, $before);
                $before = Arr::only($before, self::PRODUCTION_CONFIG_KEYS);
                $after = Arr::only($after, self::PRODUCTION_CONFIG_KEYS);
            }
            if ($this->scalar($before) !== $this->scalar($after)
                && (!is_numeric($before) || !is_numeric($after) || abs((float) $before - (float) $after) > 0.00000001)) return true;
        }
        return false;
    }

    private function assertProtectedLines(SalesOrder $order, array $candidate): void
    {
        $rows = collect($candidate['lines'])->filter(fn ($row) => !empty($row['id']))->keyBy('id');
        $protected = SalesOrderFulfillmentPolicy::protectedLineQuantities($order);
        if (array_sum($protected) > 0.00000001) foreach (['customer_id' => '客户主体', 'currency' => '币种', 'business_exchange_rate' => '业务汇率'] as $field => $label) {
            if (!array_key_exists($field, $candidate['header'])) continue;
            $before = $order->getAttribute($field); $after = $candidate['header'][$field];
            if ((string) $before !== (string) $after && (!is_numeric($before) || !is_numeric($after) || abs((float) $before - (float) $after) > 0.00000001))
                throw ValidationException::withMessages([$field => '已有实际发货的订单须保留原'.$label.'，其他'.$label.'的新增需求请另建订单。']);
        }
        foreach ($protected as $id => $qty) {
            if ($qty <= 0.00000001) continue;
            $old = $order->lines->firstWhere('id', $id); $row = $rows->get($id);
            if (!$row || in_array($id, $candidate['deleted_line_ids'] ?? [], true) || $this->protectedTermsChanged($old, $row)
                || (float) $row['order_qty'] + 0.00000001 < $qty + (float) $old->cancelled_qty) {
                throw ValidationException::withMessages(['lines' => '第 '.$old->line_no.' 行的已发货或退货范围已变化，原已发商品、配置及价格必须保留，请刷新后重新编辑剩余未发部分。']);
            }
        }
    }

    /** 已有收款核销的订单不能直接换客户，否则收款单客户与订单客户不一致。 */
    public function assertCustomerChangeAllowed(SalesOrder $order, mixed $customerId): void
    {
        if (blank($customerId) || (int) $customerId === (int) $order->customer_id) return;
        if (array_sum(SalesOrderFulfillmentPolicy::protectedLineQuantities($order)) > 0.00000001)
            throw ValidationException::withMessages(['customer_id' => '该订单已有实际发货，须保留原客户主体；其他客户的新增需求请另建订单。']);
        $received = app(SalesFinanceSettlementService::class)->status($order)['received_amount'];
        if (\App\Domain\Finance\Money::compare((string) $received, '0') > 0) {
            throw ValidationException::withMessages(['customer_id' => '这个订单已有收款核销，不能直接换客户。请先由财务撤销该订单的收款核销后再换。']);
        }
    }

    /**
     * 把页面提交的表头整理成正式口径：快照只合并允许编辑的键，付款方式和付款条件由服务端重新冻结，
     * 客户快照按订单客户信息重建，避免旧页面带回的快照覆盖正式事实。
     */
    private function normalizeHeader(array $header, SalesOrder $order): array
    {
        unset($header['customer_snapshot'], $header['pay_type'], $header['channel_type_snapshot']);
        $channelId = (int) ($header['sales_channel_id'] ?? $order->sales_channel_id);
        $externalOrderNo = array_key_exists('external_order_no', $header) ? $header['external_order_no'] : $order->external_order_no;
        if ($channelId !== (int) $order->sales_channel_id) {
            $channel = SalesChannel::query()->where('status', 'enabled')->find($channelId);
            if (!$channel) throw ValidationException::withMessages(['sales_channel_id' => '原销售渠道已停用，请刷新订单后重试。']);
            $header = array_replace($header, app(SalesOrderSnapshotService::class)->channelFields($channel, $externalOrderNo));
        } else {
            unset($header['sales_channel_id']);
            if (array_key_exists('external_order_no', $header)) {
                $number = trim((string) $externalOrderNo);
                $header['external_order_no'] = $number === '' ? null : $number;
            }
        }
        $header = array_replace($header, app(SalesTradePlatformService::class)->orderPaymentFields($header, $order));
        $customerId = array_key_exists('customer_id', $header) && filled($header['customer_id']) ? (int) $header['customer_id'] : (int) $order->customer_id;
        if (array_key_exists('customer_id', $header)) {
            if (blank($header['customer_id'])) unset($header['customer_id']);
            else $header['customer_id'] = $customerId;
        }
        if ($customerId !== (int) $order->customer_id) {
            $this->assertCustomerChangeAllowed($order, $customerId);
            $customer = SalesCustomer::query()->find($customerId);
            if (!$customer || in_array($customer->status, ['disabled', 'blacklisted'], true)) {
                throw ValidationException::withMessages(['customer_id' => '所选客户已停用或在黑名单，不能用于订单。']);
            }
            // 改单换客户与新建订单共用客户可见范围：其他部门负责的客户需先申请协作。
            $actor = request() ? app(AuthContextService::class)->currentUser(request()) : null;
            if ($actor && app(\App\Services\Erp\Crm\CrmCustomerAccessService::class)->relation($customer, $actor) === null) {
                throw ValidationException::withMessages(['customer_id' => '该客户由其他部门负责，不在你的可见范围内，请先申请协作。']);
            }
            $header['customer_name'] = $customer->customer_name;
        }
        if (filled($header['customer_contact_id'] ?? null) && (int) $header['customer_contact_id'] !== (int) $order->customer_contact_id
            && !SalesCustomerContact::query()->where('customer_id', $customerId)->whereKey($header['customer_contact_id'])->exists()) {
            throw ValidationException::withMessages(['customer_contact_id' => '所选联系人不属于当前客户，请重新选择客户。']);
        }
        if (filled($header['customer_address_id'] ?? null) && (int) $header['customer_address_id'] !== (int) $order->customer_address_id
            && !SalesCustomerAddress::query()->where('customer_id', $customerId)->whereKey($header['customer_address_id'])->exists()) {
            throw ValidationException::withMessages(['customer_address_id' => '所选收货地址不属于当前客户，请重新选择客户。']);
        }
        // 页面“联系电话”绑定 customer_phone；两者是同一个电话，改了哪个就以哪个为准。
        $customerPhone = array_key_exists('customer_phone', $header) ? trim((string) $header['customer_phone']) : null;
        $contactPhone = array_key_exists('contact_phone', $header) ? trim((string) $header['contact_phone']) : null;
        if ($customerPhone !== null && $customerPhone !== trim((string) $order->customer_phone)) $header['contact_phone'] = $customerPhone ?: null;
        elseif ($contactPhone !== null && $contactPhone !== trim((string) $order->contact_phone)) $header['customer_phone'] = $contactPhone ?: null;
        elseif ($customerPhone !== null || $contactPhone !== null) {
            $header['customer_phone'] = $order->customer_phone;
            $header['contact_phone'] = $order->contact_phone;
        }

        unset($header['funding_policy_snapshot'], $header['payment_terms_snapshot']);
        if (array_key_exists('funding_policy_id', $header)) {
            if (blank($header['funding_policy_id']) || (int) $header['funding_policy_id'] === (int) $order->funding_policy_id) {
                unset($header['funding_policy_id']);
            } else {
                $policy = SalesFundingPolicy::query()->where('status', 'enabled')->find((int) $header['funding_policy_id']);
                if (!$policy) throw ValidationException::withMessages(['funding_policy_id' => '所选付款条件已停用，请重新选择。']);
                $snapshot = [
                    'id' => $policy->id,
                    'policy_code' => $policy->policy_code,
                    'policy_name' => $policy->policy_name,
                    'policy_type' => $policy->policy_type,
                    'production_threshold_type' => $policy->production_threshold_type,
                    'production_threshold_value' => (string) $policy->production_threshold_value,
                    'shipment_requires_full_payment' => (bool) $policy->shipment_requires_full_payment,
                    'receivable_amount' => (string) ($order->final_receivable_amount ?: $order->total_amount),
                    'frozen_at' => now()->toISOString(),
                ];
                $header['funding_policy_id'] = $policy->id;
                $header['funding_policy_snapshot'] = $snapshot;
                $header['payment_terms_snapshot'] = $snapshot;
            }
        }

        if (array_key_exists('payment_method_id', $header)) {
            if (blank($header['payment_method_id']) || (int) $header['payment_method_id'] === (int) $order->payment_method_id) {
                unset($header['payment_method_id']);
            } else {
                $paymentMethods = app(PaymentMethodApplicationService::class);
                try {
                    $method = $paymentMethods->resolveForSales((int) $header['payment_method_id']);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['payment_method_id' => '所选付款方式已停用或不可用于销售，请重新选择。']);
                }
                $header['payment_method_id'] = $method->id;
                $header['payment_method_snapshot'] = $paymentMethods->snapshot($method);
                $header['pay_type'] = $method->method_code;
            }
        }

        if (array_key_exists('is_share', $header) || array_key_exists('share_user', $header)) {
            $isShare = array_key_exists('is_share', $header) ? (bool) $header['is_share'] : (bool) $order->is_share;
            $header['is_share'] = $isShare;
            $header['share_user'] = $isShare
                ? app(SalesOrderVisibilityService::class)->normalizeShareUsers($header['share_user'] ?? $order->share_user) : null;
        }

        foreach (self::SNAPSHOT_EDIT_FIELDS as $snapshotField => $keys) {
            if (!array_key_exists($snapshotField, $header)) continue;
            $submitted = (array) $header[$snapshotField];
            $merged = (array) $order->getAttribute($snapshotField);
            foreach (array_keys($keys) as $key) {
                if (array_key_exists($key, $submitted)) $merged[$key] = $submitted[$key];
            }
            $header[$snapshotField] = $merged;
        }
        if (array_key_exists('logistics_requirement', $header)) {
            $note = trim((string) $header['logistics_requirement']);
            $header['logistics_requirement'] = $note === '' ? null : $note;
            $shipping = (array) ($header['shipping_snapshot'] ?? $order->shipping_snapshot);
            $shipping['customer_logistics_note'] = $header['logistics_requirement'];
            $header['shipping_snapshot'] = $shipping;
        }
        if (array_key_exists('carrier_id', $header) || array_key_exists('default_carrier_id', $header)) {
            $carrierId = ($header['default_carrier_id'] ?? null) ?: ($header['carrier_id'] ?? null);
            $shipping = (array) ($header['shipping_snapshot'] ?? $order->shipping_snapshot);
            $shipping['default_carrier_id'] = $carrierId ?: null;
            $shipping['default_carrier_name'] = $carrierId
                ? DB::table('erp_sales_order_deliveries')->where('legacy_id', $carrierId)->value('name') : null;
            $header['shipping_snapshot'] = $shipping;
        }
        $this->validateCustomerIdentity($order, $header);
        return $header;
    }

    private function validateCustomerIdentity(SalesOrder $order, array $header, bool $bind = false, ?object $actor = null): void
    {
        $changed = false;
        foreach (['customer_id', 'platform', 'platform_buyer_id'] as $field) {
            if (array_key_exists($field, $header) && trim((string) $header[$field]) !== trim((string) $order->getAttribute($field))) $changed = true;
        }
        if (!$changed) return;
        $customerId = (int) ($header['customer_id'] ?? $order->customer_id);
        $platform = (int) ($header['platform'] ?? $order->platform);
        $buyerId = trim((string) (array_key_exists('platform_buyer_id', $header) ? $header['platform_buyer_id'] : $order->platform_buyer_id));
        $identities = app(\App\Services\Erp\Crm\CrmPlatformIdentityService::class);
        $resolved = $identities->assertCustomer($customerId, $platform ?: null, $buyerId, $bind);
        // 预览只检查，审批生效时重新加锁检查并登记；回滚订单时身份一并回滚。
        if ($bind && $resolved['key']) {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
            abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或在黑名单，不能登记订单平台身份。');
            $identities->bind($customer, $platform, $buyerId, 'order', $actor, '订单变更生效时登记');
        }
    }

    /** 特殊定制行（新增、换SKU或新开特殊定制）必须带技术协议和定制说明，与正式确认口径一致。 */
    private function assertSpecialCustomRequirements(SalesOrder $order, array $candidate): void
    {
        $current = $order->lines->keyBy('id');
        $draftToken = $candidate['draft_token'] ?? null;
        foreach (array_values($candidate['lines'] ?? []) as $index => $line) {
            $old = $current->get((int) ($line['id'] ?? 0));
            $draftLineUuid = $line['line_uuid'] ?? null;
            foreach ($candidate['protected_line_splits'] ?? [] as $sourceId => $split) {
                if (($line['line_uuid'] ?? null) === $split['line_uuid']) {
                    $old = $current->get((int) $sourceId); $draftLineUuid = $split['draft_line_uuid']; break;
                }
            }
            $special = $line['is_special_customized'] ?? data_get($line, 'configuration_snapshot.is_special_customized') ?? $old?->is_special_customized;
            if (!$special) continue;
            $skuId = (int) ($line['sku_id'] ?? $old?->sku_id);
            $sku = $skuId ? Sku::query()->find($skuId) : null;
            if (!$sku) continue;
            $number = $index + 1;
            $newlySpecial = !$old || (int) $old->sku_id !== $skuId || !$old->is_special_customized;
            $config = $this->lines->configurationSnapshot($line, $old ? (array) $old->configuration_snapshot : null);
            $description = trim((string) ($config['special_custom_description'] ?? ''));
            $descriptionChanged = !$old || $description !== trim((string) ($old->customization_description ?: data_get($old->configuration_snapshot, 'special_custom_description')));
            if ($sku->special_custom_description_required && $description === '' && ($newlySpecial || $descriptionChanged)) {
                throw ValidationException::withMessages(['lines' => "第 {$number} 行是特殊定制，请填写特殊定制说明。"]);
            }
            if (!$newlySpecial || !$sku->special_custom_agreement_required) continue;
            $agreements = SalesOrderAttachment::query()->where('status', 'active')->where('attachment_type', 'technical_agreement');
            $has = $old && (clone $agreements)->where('sales_order_line_id', $old->id)->exists();
            if (!$has && $draftToken && filled($draftLineUuid)) {
                $has = (clone $agreements)->whereNull('sales_order_line_id')
                    ->where('draft_token', $draftToken)->where('line_uuid', $draftLineUuid)->exists();
            }
            if (!$has) {
                throw ValidationException::withMessages(['lines' => "第 {$number} 行是特殊定制，缺少技术协议。请先在该行附件里上传技术协议再保存。"]);
            }
        }
    }

    /** 报价有效期、行备注只是信息字段，任何保存路径都要落库，不能因其他行重排备货而丢失。 */
    private function saveLineInfoFields(SalesOrder $order, array $rows, array $diffs): void
    {
        $changed = collect($diffs)->filter(fn ($diff) => ($diff['scope'] ?? null) === 'line' && !empty($diff['line_id'])
            && in_array($diff['semantic_key'] ?? null, ['valid_days', 'remark'], true))->groupBy('line_id');
        if ($changed->isEmpty()) return;
        $rowsById = collect($rows)->keyBy(fn ($row) => (int) ($row['id'] ?? 0));
        foreach ($changed as $lineId => $lineDiffs) {
            $line = \App\Models\Erp\SalesOrderLine::query()->where('sales_order_id', $order->id)->find((int) $lineId);
            $row = $rowsById->get((int) $lineId);
            if (!$line || !$row) continue;
            $updates = [];
            if ($lineDiffs->contains('semantic_key', 'remark')) $updates['remark'] = $row['remark'] ?? null;
            if ($lineDiffs->contains('semantic_key', 'valid_days')) {
                $config = (array) $line->configuration_snapshot;
                $value = trim((string) data_get($row, 'configuration_snapshot.valid_days', ''));
                $config['valid_days'] = $value === '' ? null : $value;
                $updates['configuration_snapshot'] = $config;
            }
            if ($updates) $line->update($updates);
        }
    }

    /** 订单客户快照：保留页面专用键（客户备注等），换客户时按客户主档刷新编码和来源。 */
    private function customerSnapshot(SalesOrder $order, array $address, bool $customerChanged): array
    {
        $snapshot = (array) $order->customer_snapshot;
        if ($customerChanged && ($customer = SalesCustomer::query()->find($order->customer_id))) {
            $snapshot = array_replace($snapshot, [
                'customer_code' => $customer->customer_code,
                'source_platform' => $customer->source_platform,
            ]);
        }
        return array_replace($snapshot, [
            'id' => $order->customer_id, 'name' => $order->customer_name,
            'customer_kind' => $order->customer_kind, 'platform_buyer_id' => $order->platform_buyer_id,
            'contact' => ['id' => $order->customer_contact_id, 'name' => $order->contact_name, 'phone' => $order->contact_phone],
            'address' => $address,
        ]);
    }

    private function snapshotValue(string $key, mixed $value): string
    {
        if ($key === 'is_self_pickup') return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '是' : '否';
        return trim($this->scalar($value));
    }
    private function preserveAgreedDeposit(array $header, SalesOrder $order): array
    {
        // 显式修改定金随候选审核；未提交此字段的旧页面仍保留最新约定。
        $changed = array_key_exists('agreed_deposit_amount', $header);
        foreach (['funding_policy_snapshot', 'payment_terms_snapshot'] as $field) {
            if (!array_key_exists($field, $header) && !$changed) continue;
            $current = (array) $order->getAttribute($field);
            $snapshot = (array) ($header[$field] ?? $current);
            unset($snapshot['agreed_deposit_amount']);
            $policyType = $snapshot['policy_type'] ?? $current['policy_type'] ?? null;
            $amount = $changed ? $header['agreed_deposit_amount'] : ($current['agreed_deposit_amount'] ?? null);
            if ($changed && $amount !== null && !SalesOrderFundingGateService::supportsAgreedDeposit($policyType)) {
                throw ValidationException::withMessages(['agreed_deposit_amount' => '当前付款条件不支持约定定金。']);
            }
            if ($amount !== null && SalesOrderFundingGateService::supportsAgreedDeposit($policyType)) {
                $snapshot['agreed_deposit_amount'] = SalesOrderFundingGateService::normalizeAgreedDeposit($amount);
            }
            $header[$field] = $snapshot;
        }
        return $header;
    }
    private function headerImpacts(string $field, mixed $before, mixed $after, array $facts): array
    {
        return match ($field) {
            // 付款条款仍需审核，付款方式的派生名称沿用对应主档。
            'payment_terms_snapshot', 'funding_policy_id', 'agreed_deposit_amount' => ['FINANCIAL', 'FULFILLMENT'],
            'freight_amount', 'carrier_fee', 'currency', 'transaction_mode' => ['FINANCIAL'],
            'required_delivery_date', 'is_delay', 'delay_date' => $facts['has_fulfillment_facts']
                ? ['FULFILLMENT'] : ['INFO'],
            // 客户指定物流只是发货时快递公司的预填值，实际快递在每次发货时填写，不影响任何交付事实。
            'carrier_id', 'default_carrier_id' => ['INFO'],
            'trade_type' => ['COMMERCIAL', 'FULFILLMENT'],
            'customer_id', 'customer_name', 'platform', 'sales_channel_id', 'channel_type_snapshot' => ['COMMERCIAL'],
            default => ['INFO'],
        };
    }

    private function lineImpacts(string $field, $line, mixed $before, mixed $after, array $facts): array
    {
        return match ($field) {
            'unit_price', 'discount_rate' => ['COMMERCIAL', 'FINANCIAL'],
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
        if (!$facts['has_operational_facts']) return ['COMMERCIAL', 'FINANCIAL'];
        $types = ['FINANCIAL', 'FULFILLMENT'];
        if ($line->line_type === 'physical' && $facts['has_production_facts']) $types[] = 'PRODUCTION';
        return $types;
    }
    private function lineLabel(mixed $line, int $fallbackNumber): string
    {
        $number = (int) data_get($line, 'line_no') ?: $fallbackNumber;
        $product = trim((string) data_get($line, 'product_name')) ?: '商品#'.data_get($line, 'product_id');
        $sku = trim((string) data_get($line, 'sku_name')) ?: 'SKU#'.data_get($line, 'sku_id');
        $code = data_get($line, 'sku_snapshot.sku_code') ?: data_get($line, 'sku_code') ?: 'SKU#'.data_get($line, 'sku_id');
        return '第'.$number.'行：'.$product.' / '.$sku.($code !== $sku ? '（'.$code.'）' : '');
    }

    private function diff(string $scope, ?int $lineId, string $key, mixed $before, mixed $after, array $types, ?string $lineLabel = null): array {
        $requirements = array_values(array_unique(array_filter([
            in_array('COMMERCIAL', $types, true) || in_array('PRODUCTION', $types, true) ? 'business' : null,
            in_array('FINANCIAL', $types, true) ? 'finance' : null,
            in_array('FULFILLMENT', $types, true) ? 'fulfillment' : null,
        ])));
        $label = $scope === 'line' && $key === 'remark' ? '行备注' : (self::LABELS[$key] ?? $key);
        return ['scope' => $scope, 'line_id' => $lineId, 'semantic_key' => $key, 'label' => $lineLabel ? $lineLabel.' · '.$label : $label, 'before' => $before, 'after' => $after, 'impact_types' => $types, 'business_impact_text' => $this->impactText($types), 'approval_requirements' => $requirements];
    }
    private function impactText(array $types): string { return implode('、', array_map(fn ($t) => ['INFO'=>'无业务影响','COMMERCIAL'=>'商业信息影响','FINANCIAL'=>'财务结算影响','FULFILLMENT'=>'库存与交付影响','PRODUCTION'=>'生产需求影响'][$t], $types)); }
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
                'fulfillment_method' => '订单行备货方式切换涉及生产定义，需要业务审核。',
                'trade_type' => '贸易类型发生变化，影响订单商业口径，需要业务审核。',
                'customer_id', 'customer_name' => '订单客户发生变化，需要业务审核。',
                'platform', 'sales_channel_id', 'channel_type_snapshot' => '成交平台或收款类型发生变化，需要业务审核。',
                default => '订单已生产确认，'.$label.'发生变化，需要业务审核。',
            },
            'finance' => match ($key) {
                'unit_price', 'discount_rate' => '销售单价或折扣发生调整，影响订单应收和结算金额，需要财务审核。',
                'order_qty', 'line_added', 'line_removed' => '订单商品数量或明细范围发生调整，影响订单应收和结算金额，需要财务审核。',
                'payment_terms_snapshot' => '付款规则发生变化，影响资金门禁和应收口径，需要财务审核。',
                'funding_policy_id' => '资金策略发生变化，影响资金门禁和应收口径，需要财务审核。',
                'freight_amount', 'carrier_fee' => '订单费用发生变化，影响应收和结算金额，需要财务审核。',
                'currency' => '订单币种发生变化，影响结算和本位币金额，需要财务审核。',
                'transaction_mode' => '交易方式发生变化，影响财务结算口径，需要财务审核。',
                'tax_rate', 'price_tax_mode' => '税率或含税方式发生变化，影响税额和结算金额，需要财务审核。',
                default => $label.'发生变化，触发财务审核条件。',
            },
            'fulfillment' => match ($key) {
                'payment_terms_snapshot' => '付款规则发生变化，需要库存交付审核。',
                'funding_policy_id' => '资金策略发生变化，影响生产或发货资金门槛，需要库存交付审核。',
                'required_delivery_date', 'is_delay', 'delay_date' => '要求交期发生调整，影响现有库存、生产或交付计划，需要库存交付审核。',
                'sku_id' => 'SKU 身份发生变化，需要重新核对库存、生产和交付安排。',
                'product_id' => 'Product 身份发生变化，需要重新核对库存、生产和交付安排。',
                'line_added' => '新增订单行改变了库存、生产和交付范围，需要库存交付审核。',
                'line_removed' => '删除订单行改变了库存、生产和交付范围，需要库存交付审核。',
                'order_qty' => '订单行数量发生调整，影响既有库存预留或交付数量，需要库存交付审核。',
                'fulfillment_method' => '订单行备货方式发生变化，需要重新核对库存、生产和交付安排。',
                'electric', 'need_pump', 'is_customized', 'is_special_customized', 'configuration_snapshot' => '生产关键配置发生变化，需要重新核对生产和交付配置。',
                'trade_type' => '贸易类型发生变化，影响交付规则，需要库存交付审核。',
                default => $label.'发生变化，触发库存交付审核条件。',
            },
            default => null,
        };
    }

    private function headerValue(string $field, mixed $value): string
    {
        if ($field === 'agreed_deposit_amount') return $value === null || $value === '' ? '默认值' : $this->decimal($value);
        if ($field === 'trade_type' && ($value === null || $value === '')) return 'domestic';
        if ($field === 'customer_kind' && ($value === null || $value === '')) $value = 'individual';
        if ($field === 'customer_kind') return $value === 'enterprise' ? '企业' : '个人';
        if ($field === 'is_share') return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '是' : '否';
        if ($value === null || $value === '') return '';
        if ($field === 'channel_type_snapshot') return $value === 'online_platform' ? '线上收款' : '线下收款';
        if ($field === 'payment_method_id') {
            return (string) (DB::table('erp_payment_methods')->where('id', (int) $value)->value('method_name') ?: $value);
        }
        if ($field === 'customer_id') {
            $customer = DB::table('erp_sales_customers')->where('id', (int) $value)->first(['customer_name', 'customer_code']);
            return $customer ? $customer->customer_name.'（'.$customer->customer_code.'）' : (string) $value;
        }
        if ($field === 'customer_contact_id') {
            $contact = DB::table('erp_sales_customer_contacts')->where('id', (int) $value)->first(['contact_name', 'mobile', 'phone']);
            return $contact ? trim($contact->contact_name.' '.($contact->mobile ?: $contact->phone)) : (string) $value;
        }
        if ($field === 'customer_address_id') {
            return (string) (DB::table('erp_sales_customer_addresses')->where('id', (int) $value)->value('full_address') ?: $value);
        }
        if ($field === 'funding_policy_id') {
            return (string) (DB::table('erp_sales_funding_policies')->where('id', (int) $value)->value('policy_name') ?: $value);
        }
        if ($field === 'share_user') {
            $ids = array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value))));
            sort($ids);
            $users = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->get(['legacy_id', 'nickname', 'username'])->keyBy('legacy_id');
            return implode('、', array_map(function ($id) use ($users): string {
                $user = $users->get($id);
                return $user ? ($user->nickname ?: $user->username).'（'.($user->username ?: $id).'）' : (string) $id;
            }, $ids));
        }
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
        $hasShipments = $order->shipments()->whereIn('shipment_status', ['draft', 'pending_outbound', 'prepared'])->exists();
        $hasReservations = InventoryReservation::query()
            ->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
            ->where('source_order_id', $order->id)
            ->whereIn('reservation_status', ['active', 'partially_released', 'converted_to_shipment'])
            ->exists();
        $hasFulfillment = SalesOrderFulfillment::query()
            ->where('sales_order_id', $order->id)
            ->whereNotIn('demand_status', ['superseded', 'cancelled'])
            ->exists();
        $hasProduction = SalesOrderFulfillment::query()
            ->where('sales_order_id', $order->id)
            ->where('fulfillment_type', 'production')
            ->whereNotIn('demand_status', ['superseded', 'cancelled'])
            ->exists();
        return [
            'has_pending_shipments' => $hasShipments,
            'has_inventory_reservations' => $hasReservations,
            'has_fulfillment_facts' => $hasReservations || $hasFulfillment || $hasProduction || $hasShipments,
            'has_production_facts' => $hasProduction,
            'has_operational_facts' => $hasReservations || $hasFulfillment || $hasProduction || $hasShipments,
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
        $order->assertCurrentBusiness();
        if ($order->order_status !== 'confirmed' || $order->confirm_status !== 'confirmed') throw ValidationException::withMessages(['order_status' => '只有已正式确认的订单可编辑；草稿订单请直接保存。']);
        if ($order->short_closed_at) throw ValidationException::withMessages(['order_status' => '短交关闭的订单不能重新修改未发需求。']);
    }
    private function log(SalesOrder $order, string $action, string $before, string $after, string $operator, array $payload, string $content): void { SalesOrderLog::create(['sales_order_id'=>$order->id,'action'=>$action,'before_status'=>$before,'after_status'=>$after,'payload'=>$payload,'operator'=>$operator,'content'=>$content]); }
}
