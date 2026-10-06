<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, PurchaseOrder, PurchasePlanSupplierSplit};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasePlanningConversionService
{
    public function __construct(private readonly UnitConversionDomainService $domain) {}

    /** $trustedSnapshot must come from a locked server-side document, never the request body. */
    public function fromPurchaseQuantity(array $line, ?array $trustedSnapshot = null, ?float $requiredBase = null): array
    {
        return $this->calculate($line, $trustedSnapshot, true, $requiredBase);
    }

    public function calculate(array $line, ?array $trustedSnapshot = null, bool $direct = false, ?float $requiredBase = null): array
    {
        return DB::transaction(function () use ($line, $trustedSnapshot, $direct, $requiredBase) {
            $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
            $unitId = (int) ($line['purchase_unit_id'] ?? 0);
            $snapshot = $trustedSnapshot;
            if (!$snapshot || (int) ($snapshot['item_id'] ?? 0) !== (int) $item->id
                || ($unitId && $unitId !== (int) $snapshot['purchase_unit_id'])) {
                $base = $this->domain->canonicalUnit($item->unit);
                abort_unless($base, 422, '物料尚未维护库存单位');
                $conversion = null;
                if ($unitId && $unitId !== (int) $base->id) {
                    $conversion = $this->domain->purchaseConversion($item->id, $unitId, lock: true);
                } elseif (!$unitId) {
                    $conversion = $this->domain->activePurchaseConversions($item->id)->where('is_default', true)
                        ->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])->lockForUpdate()->first();
                }
                $purchase = $conversion ? $this->domain->canonicalUnit($conversion->purchaseUnit) : $base;
                abort_unless($purchase && (!$conversion || (int) $this->domain->canonicalUnit($conversion->baseUnit)?->id === (int) $base->id), 422, '采购换算与物料库存单位不一致');
                $snapshot = [
                    'snapshot_version' => 1, 'item_id' => $item->id,
                    'conversion_id' => $conversion?->id, 'conversion_version' => $conversion?->version_no,
                    'purchase_unit_id' => $purchase->id, 'purchase_unit_name_snapshot' => $purchase->unit_name,
                    'purchase_decimal_places' => (int) $purchase->decimal_places,
                    'base_unit_id' => $base->id, 'base_unit_name_snapshot' => $base->unit_name,
                    'base_decimal_places' => (int) $base->decimal_places,
                    'conversion_factor_snapshot' => (string) ($conversion?->factor ?? '1'),
                    'allow_actual_conversion_snapshot' => (bool) ($conversion?->allow_actual_conversion ?? false),
                    'rounding_rule' => 'ceil_purchase_unit_precision', 'captured_at' => now()->toISOString(),
                ];
            }
            $factor = (float) $snapshot['conversion_factor_snapshot'];
            $purchasePrecision = (int) $snapshot['purchase_decimal_places'];
            $basePrecision = (int) $snapshot['base_decimal_places'];
            $required = $direct ? ($requiredBase ?? (float) bcmul(sprintf('%.8F', (float) ($line['purchase_quantity'] ?? 0)), (string) $snapshot['conversion_factor_snapshot'], 8)) : (float) ($line['required_qty'] ?? 0);
            $this->assertPrecision($required, $basePrecision, 'required_qty', $snapshot['base_unit_name_snapshot']);
            abort_if(($direct ? $required < 0 : $required <= 0) || $factor <= 0, 422, '需求数量和换算因子无效');

            // Only legacy base-requirement callers need ceiling. Direct entry keeps the entered
            // purchase quantity exactly; downstream orders copy the saved result.
            $suggested = null;
            if (!$direct) {
                $ratio = bcdiv(sprintf('%.8F', $required), (string) $snapshot['conversion_factor_snapshot'], $purchasePrecision);
                $suggested = bccomp(bcmul($ratio, (string) $snapshot['conversion_factor_snapshot'], 12), sprintf('%.8F', $required), 12) < 0
                    ? bcadd($ratio, bcdiv('1', bcpow('10', (string) $purchasePrecision), $purchasePrecision), $purchasePrecision) : $ratio;
            }
            $quantity = (float) ($line['purchase_quantity'] ?? ($direct ? 0 : $suggested));
            $this->assertPrecision($quantity, $purchasePrecision, 'purchase_quantity', $snapshot['purchase_unit_name_snapshot']);
            $planned = (float) bcmul(sprintf('%.8F', $quantity), (string) $snapshot['conversion_factor_snapshot'], 8);
            $this->assertPrecision($planned, $basePrecision, 'purchase_quantity', $snapshot['base_unit_name_snapshot']);
            // Legacy quantity columns store four decimals; reject instead of silently truncating a stock fact.
            $this->assertPrecision($planned, min(4, $basePrecision), 'purchase_quantity', $snapshot['base_unit_name_snapshot']);
            abort_if($quantity <= 0 || $planned + 0.00000001 < $required, 422, '采购数量换算后不能少于本次分配的需求数量');
            $price = (float) ($line['purchase_unit_price'] ?? ((float) ($line['base_unit_price'] ?? 0) * $factor));
            abort_if($price < 0, 422, '采购单价不能小于0');
            $fingerprint = hash('sha256', json_encode(Arr::only($snapshot, [
                'item_id', 'conversion_id', 'conversion_version', 'purchase_unit_id', 'base_unit_id',
                'conversion_factor_snapshot', 'purchase_decimal_places', 'base_decimal_places', 'allow_actual_conversion_snapshot',
            ])));
            if (!empty($line['expected_conversion_fingerprint']) && !hash_equals($fingerprint, $line['expected_conversion_fingerprint'])) {
                throw ValidationException::withMessages(['purchase_unit_id' => '采购换算已变化，请重新选择采购单位并核对数量后保存。']);
            }
            return array_replace($snapshot, [
                'conversion_fingerprint' => $fingerprint,
                'input_mode' => $direct ? 'purchase_quantity' : 'base_requirement',
                'rounding_rule' => $direct ? 'none' : 'ceil_purchase_unit_precision',
                'required_base_qty' => $required, 'suggested_purchase_qty' => $direct ? null : (float) $suggested,
                'purchase_qty' => $quantity, 'planned_base_qty' => $planned,
                'excess_base_qty' => round($planned - $required, 8),
                'purchase_unit_price' => $price,
                'base_unit_price' => $this->domain->calculateBaseUnitPrice($price, $factor),
                'amount' => round($quantity * $price, 4),
            ]);
        });
    }

    /** Keep the original source allocation in base units while suppliers enter actual purchase quantities. */
    public function preparePlanLine(array $line, ?array $trustedSnapshot, ?float $sourceRequired, $priorSplits): array
    {
        $direct = isset($line['purchase_quantity']);
        $snapshot = $direct ? $this->fromPurchaseQuantity($line, $trustedSnapshot, $sourceRequired) : $this->calculate($line, $trustedSnapshot);
        $required = (float) $snapshot['required_base_qty'];
        $allocated = 0.0;
        $planned = 0.0;
        $splits = [];
        foreach ($line['splits'] ?? [] as $split) {
            $prior = $priorSplits?->firstWhere('id', $split['id'] ?? 0);
            abort_if(!empty($split['id']) && !$prior, 422, '供应商拆分不属于当前计划明细');
            $input = [...$split, 'item_id' => $line['item_id'], 'required_qty' => $split['purchase_qty'] ?? 0,
                'purchase_unit_id' => $split['purchase_unit_id'] ?? $snapshot['purchase_unit_id'], 'base_unit_price' => $split['unit_price'] ?? 0];
            $facts = $direct ? $this->fromPurchaseQuantity($input, $prior?->purchase_conversion_snapshot ?? $snapshot)
                : $this->calculate($input, $prior?->purchase_conversion_snapshot ?? $snapshot);
            $allocation = $direct ? min(max(0, $required - $allocated), (float) $facts['planned_base_qty']) : (float) $split['purchase_qty'];
            $facts['required_base_qty'] = $allocation;
            $facts['excess_base_qty'] = round((float) $facts['planned_base_qty'] - $allocation, 8);
            $allocated += $allocation;
            $planned += (float) $facts['planned_base_qty'];
            $splits[] = [...$split, 'purchase_qty' => $allocation, 'purchase_conversion_snapshot' => $facts];
        }
        abort_if($allocated > $required + 0.00000001 || ($direct && $planned > (float) $snapshot['planned_base_qty'] + 0.00000001), 422, '供应商采购数量换算后不能超过计划采购数量');
        return ['snapshot' => $snapshot, 'splits' => $splits, 'allocated' => $allocated];
    }

    public function assertPrecision(float $quantity, int $precision, string $field, string $name): void
    {
        if (!is_finite($quantity) || abs($quantity - round($quantity, $precision)) > 0.00000001) {
            throw ValidationException::withMessages([$field => "单位 {$name} 最多允许 {$precision} 位小数。"]);
        }
    }

    public function orderSnapshot(PurchasePlanSupplierSplit $split): array
    {
        $snapshot = $split->purchase_conversion_snapshot;
        // Historical approved plans cannot be silently repriced or rounded using current masters.
        // They must be reopened and confirmed through the plan editor before generating orders.
        abort_unless($snapshot && (int) ($snapshot['snapshot_version'] ?? 0) === 1, 422, '该计划尚未确认采购单位换算，请退回草稿并保存确认后再生成订单');
        abort_if(abs((float) $snapshot['required_base_qty'] - (float) $split->purchase_qty) > 0.00000001, 422, '计划需求分配与采购换算快照不一致');
        return ['purchase_conversion_snapshot' => $snapshot] + Arr::only($snapshot, [
            'purchase_unit_id', 'purchase_unit_name_snapshot', 'conversion_factor_snapshot',
            'allow_actual_conversion_snapshot', 'base_unit_id', 'base_unit_name_snapshot',
            'purchase_qty', 'planned_base_qty', 'purchase_unit_price', 'base_unit_price', 'amount',
        ]);
    }

    public function assertOrderEdit(PurchaseOrder $order, array $payload, $priorItems): void
    {
        foreach ($payload['items'] as $line) {
            abort_if(!empty($line['id']) && !$priorItems->has($line['id']), 422, '采购订单明细不属于当前订单');
        }
        if (!$order->plan_id) return;
        abort_if((int) $payload['supplier_id'] !== (int) $order->supplier_id || count($payload['items']) !== $priorItems->count(), 422, '计划生成的订单须保留原供应商和计划明细');
        foreach ($payload['items'] as $line) {
            $prior = $priorItems->get($line['id'] ?? 0);
            abort_if(!$prior || (int) $prior->item_id !== (int) $line['item_id']
                || (int) $prior->purchase_unit_id !== (int) ($line['purchase_unit_id'] ?? $prior->purchase_unit_id)
                || abs((float) $prior->order_qty - (float) $line['order_qty']) > 0.00000001,
                422, '计划生成的订单直接引用已确认的物料、采购单位和数量，不能在订单中重新换算');
        }
    }
}
