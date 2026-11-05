<?php

namespace App\Services\Erp;

use App\Models\Erp\AftersalesCase;
use App\Models\Erp\AftersalesClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 索赔登记与跟进：本期只做登记，不生成财务单据；售后单关闭后仍可继续跟进。 */
final class AftersalesClaimApplicationService
{
    private const TARGET_TYPES = ['carrier', 'supplier', 'other'];

    public function __construct(private readonly AftersalesCaseApplicationService $cases) {}

    public function create(int $caseId, array $payload, object $actor): AftersalesClaim
    {
        return DB::transaction(function () use ($caseId, $payload, $actor): AftersalesClaim {
            $case = AftersalesCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            if ($case->status === 'cancelled') throw ValidationException::withMessages(['case' => '已取消的售后单不能登记索赔。']);
            $data = $this->assertClaim($payload, $case);
            $claim = AftersalesClaim::create($data + ['case_id' => $case->id, 'created_by_legacy_id' => (int) $actor->legacy_id]);
            app(AftersalesAttachmentService::class)->bindDraft($case->id, $payload['attachment_draft_token'] ?? null, $actor, $claim->id);
            $this->cases->log($case, 'claim_add', null, $claim->status, "登记索赔：{$claim->target_name}，金额 {$claim->claim_amount}", $actor, null, $claim->id);
            return $claim;
        }, 5);
    }

    public function update(int $claimId, array $payload, object $actor): AftersalesClaim
    {
        return DB::transaction(function () use ($claimId, $payload, $actor): AftersalesClaim {
            $claim = AftersalesClaim::query()->lockForUpdate()->findOrFail($claimId);
            $case = AftersalesCase::query()->whereKey($claim->case_id)->lockForUpdate()->firstOrFail();
            $data = $this->assertClaim($payload, $case, $claim);
            $from = $claim->status;
            $claim->update($data + ['updated_by_legacy_id' => (int) $actor->legacy_id]);
            $this->cases->log($case, 'claim_update', $from, $claim->status, "更新索赔：{$claim->target_name}", $actor, null, $claim->id);
            return $claim->fresh();
        }, 5);
    }

    private function assertClaim(array $payload, AftersalesCase $case, ?AftersalesClaim $existing = null): array
    {
        $type = (string) ($payload['target_type'] ?? $existing?->target_type ?? '');
        if (! in_array($type, self::TARGET_TYPES, true)) throw ValidationException::withMessages(['target_type' => '索赔对象类型不正确。']);
        $data = ['target_type' => $type];
        if ($type === 'carrier') {
            $data['carrier_id'] = (int) ($payload['carrier_id'] ?? $existing?->carrier_id ?? 0) ?: null;
            $data['supplier_id'] = null;
            $data['target_name'] = $data['carrier_id'] ? (string) DB::table('erp_sales_order_deliveries')->where('id', $data['carrier_id'])->value('name') : null;
            if (! $data['target_name']) throw ValidationException::withMessages(['carrier_id' => '请选择承运商。']);
        } elseif ($type === 'supplier') {
            $data['supplier_id'] = (int) ($payload['supplier_id'] ?? $existing?->supplier_id ?? 0) ?: null;
            $data['carrier_id'] = null;
            $data['target_name'] = $data['supplier_id'] ? (string) DB::table('erp_suppliers')->where('id', $data['supplier_id'])->value('supplier_name') : null;
            if (! $data['target_name']) throw ValidationException::withMessages(['supplier_id' => '请选择供应商。']);
        } else {
            $data['carrier_id'] = null;
            $data['supplier_id'] = null;
            $data['target_name'] = trim((string) ($payload['target_name'] ?? $existing?->target_name ?? ''));
            if ($data['target_name'] === '') throw ValidationException::withMessages(['target_name' => '“其他”类型必须填写索赔对象名称。']);
        }
        if ($existing === null) {
            $amount = (float) ($payload['claim_amount'] ?? 0);
            if ($amount <= 0) throw ValidationException::withMessages(['claim_amount' => '索赔金额必须大于 0。']);
            $data['claim_amount'] = $amount;
            $data['claimed_on'] = $payload['claimed_on'] ?? null;
            if (empty($data['claimed_on'])) throw ValidationException::withMessages(['claimed_on' => '提出日期必填。']);
            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($reason === '') throw ValidationException::withMessages(['reason' => '索赔理由必填。']);
            $data['reason'] = $reason;
            $data['related_doc_type'] = $payload['related_doc_type'] ?? null;
            $data['related_doc_id'] = $payload['related_doc_id'] ?? null;
            $data['related_doc_no'] = $payload['related_doc_no'] ?? null;
            $data['status'] = $payload['status'] ?? 'submitted';
        } else {
            if (isset($payload['claim_amount'])) {
                $amount = (float) $payload['claim_amount'];
                if ($amount <= 0) throw ValidationException::withMessages(['claim_amount' => '索赔金额必须大于 0。']);
                $data['claim_amount'] = $amount;
            }
            if (isset($payload['reason'])) {
                $reason = trim((string) $payload['reason']);
                if ($reason === '') throw ValidationException::withMessages(['reason' => '索赔理由必填。']);
                $data['reason'] = $reason;
            }
            if (isset($payload['status'])) $data['status'] = $payload['status'];
        }
        if (! in_array($data['status'] ?? 'submitted', AftersalesClaim::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => '索赔状态不正确。']);
        }
        if (in_array($data['status'] ?? $existing?->status, ['paid', 'partially_paid'], true)) {
            $paid = $payload['paid_amount'] ?? $existing?->paid_amount;
            if ($paid === null) throw ValidationException::withMessages(['paid_amount' => '赔付金额必填（已赔付/部分赔付）。']);
            $data['paid_amount'] = $paid;
            $data['paid_on'] = $payload['paid_on'] ?? $existing?->paid_on ?? now()->toDateString();
        }
        return $data;
    }
}
