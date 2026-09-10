<?php

namespace App\Services\Erp;

use App\Models\Erp\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentMethodApplicationService
{
    public function create(array $payload, ?int $operatorId): PaymentMethod
    {
        return DB::transaction(function () use ($payload, $operatorId): PaymentMethod {
            $this->assertUsage($payload);
            return PaymentMethod::create([
                ...$payload,
                'method_code' => strtoupper(trim((string) $payload['method_code'])),
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ]);
        }, 5);
    }

    public function update(int $id, array $payload, ?int $operatorId): PaymentMethod
    {
        return DB::transaction(function () use ($id, $payload, $operatorId): PaymentMethod {
            $method = PaymentMethod::query()->lockForUpdate()->findOrFail($id);
            $expectedVersion = (int) ($payload['expected_version'] ?? 0);
            unset($payload['expected_version']);
            if ($expectedVersion !== (int) $method->business_version) {
                throw ValidationException::withMessages(['expected_version' => '付款方式已被其他人修改，请刷新后重试。']);
            }
            unset($payload['method_code']);
            $this->assertUsage([...$method->toArray(), ...$payload]);
            $method->update([...$payload, 'updated_by' => $operatorId, 'business_version' => $method->business_version + 1]);
            return $method->fresh();
        }, 5);
    }

    public function setStatus(int $id, string $status, int $expectedVersion, ?int $operatorId): PaymentMethod
    {
        return DB::transaction(function () use ($id, $status, $expectedVersion, $operatorId): PaymentMethod {
            $method = PaymentMethod::query()->lockForUpdate()->findOrFail($id);
            if ($expectedVersion !== (int) $method->business_version) {
                throw ValidationException::withMessages(['expected_version' => '付款方式已被其他人修改，请刷新后重试。']);
            }
            $method->update(['status' => $status, 'updated_by' => $operatorId, 'business_version' => $method->business_version + 1]);
            return $method->fresh();
        }, 5);
    }

    public function resolveForSales(int|string $idOrCode): PaymentMethod
    {
        return $this->resolve($idOrCode, 'available_for_sales');
    }

    public function resolveForFinance(int|string $idOrCode, string $direction): PaymentMethod
    {
        $field = $direction === 'receipt' ? 'available_for_receipt' : 'available_for_payment';
        return $this->resolve($idOrCode, $field);
    }

    public function snapshot(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'method_code' => $method->method_code,
            'method_name' => $method->method_name,
        ];
    }

    private function resolve(int|string $idOrCode, string $usageField): PaymentMethod
    {
        $value = trim((string) $idOrCode);
        $method = PaymentMethod::query()
            ->where('status', 'enabled')
            ->where($usageField, true)
            ->where(function ($query) use ($value): void {
                if (ctype_digit($value)) $query->whereKey((int) $value)->orWhere('method_code', $value);
                else $query->where('method_code', strtoupper($value));
            })
            ->first();
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => '所选付款方式不存在、已停用或不适用于当前业务。']);
        }
        return $method;
    }

    private function assertUsage(array $payload): void
    {
        if (! ($payload['available_for_sales'] ?? false)
            && ! ($payload['available_for_receipt'] ?? false)
            && ! ($payload['available_for_payment'] ?? false)) {
            throw ValidationException::withMessages(['usage' => '付款方式至少需要启用一个业务用途。']);
        }
    }
}
