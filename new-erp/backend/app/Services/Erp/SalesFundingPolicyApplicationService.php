<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesFundingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesFundingPolicyApplicationService
{
    public function create(array $payload, ?int $operatorId): SalesFundingPolicy
    {
        return DB::transaction(function () use ($payload, $operatorId): SalesFundingPolicy {
            $payload = $this->normalize($payload);
            return SalesFundingPolicy::create([
                ...$payload,
                'policy_code' => strtoupper(trim((string) $payload['policy_code'])),
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ]);
        }, 5);
    }

    public function update(int $id, array $payload, ?int $operatorId): SalesFundingPolicy
    {
        return DB::transaction(function () use ($id, $payload, $operatorId): SalesFundingPolicy {
            $policy = SalesFundingPolicy::query()->lockForUpdate()->findOrFail($id);
            $expected = (int) ($payload['expected_version'] ?? 0);
            unset($payload['expected_version'], $payload['policy_code']);
            if ($expected !== (int) $policy->business_version) {
                throw ValidationException::withMessages(['expected_version' => '付款策略已被其他人修改，请刷新后重试。']);
            }
            $payload = $this->normalize([...$policy->toArray(), ...$payload]);
            unset($payload['id'], $payload['policy_code'], $payload['created_by'], $payload['created_at'], $payload['updated_at'], $payload['business_version']);
            $policy->update([
                ...$payload,
                'updated_by' => $operatorId,
                'business_version' => $policy->business_version + 1,
            ]);
            return $policy->fresh();
        }, 5);
    }

    public function setStatus(int $id, string $status, int $expectedVersion, ?int $operatorId): SalesFundingPolicy
    {
        return DB::transaction(function () use ($id, $status, $expectedVersion, $operatorId): SalesFundingPolicy {
            $policy = SalesFundingPolicy::query()->lockForUpdate()->findOrFail($id);
            if ($expectedVersion !== (int) $policy->business_version) {
                throw ValidationException::withMessages(['expected_version' => '付款策略已被其他人修改，请刷新后重试。']);
            }
            $policy->update([
                'status' => $status,
                'updated_by' => $operatorId,
                'business_version' => $policy->business_version + 1,
            ]);
            return $policy->fresh();
        }, 5);
    }

    private function normalize(array $payload): array
    {
        $type = (string) ($payload['policy_type'] ?? '');
        if (! in_array($type, ['full_prepay', 'deposit_production', 'installment_contract', 'custom_threshold'], true)) {
            throw ValidationException::withMessages(['policy_type' => '付款策略类型无效。']);
        }
        if (($payload['shipment_requires_full_payment'] ?? true) !== true) {
            throw ValidationException::withMessages(['shipment_requires_full_payment' => '授信发货尚未启用，发货策略必须要求全额收款。']);
        }
        if ($type === 'full_prepay') {
            $payload['production_threshold_type'] = 'ratio';
            $payload['production_threshold_value'] = '1';
        } else {
            $thresholdType = (string) ($payload['production_threshold_type'] ?? '');
            try {
                $threshold = Money::normalize((string) ($payload['production_threshold_value'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['production_threshold_value' => '生产资金门槛必须是有效数字。']);
            }
            if ($thresholdType === 'ratio') {
                if (Money::compare($threshold, '0') < 0 || Money::compare($threshold, '1') > 0) {
                    throw ValidationException::withMessages(['production_threshold_value' => '比例门槛必须在 0 到 1 之间。']);
                }
            } elseif ($thresholdType === 'amount') {
                if (Money::compare($threshold, '0') < 0) {
                    throw ValidationException::withMessages(['production_threshold_value' => '金额门槛不能小于 0。']);
                }
            } else {
                throw ValidationException::withMessages(['production_threshold_type' => '生产门槛只能按金额或比例配置。']);
            }
            $payload['production_threshold_value'] = $threshold;
        }
        $payload['shipment_requires_full_payment'] = true;
        return $payload;
    }
}
