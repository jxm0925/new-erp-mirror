<?php

namespace App\Services\Erp;

use App\Models\Erp\Sku;

class SkuItemRelationAuditService
{
    public function inspect(Sku $sku): array
    {
        $type = $sku->line_type;
        $relations = $sku->itemRelations->where('status', 'active')->where('is_primary', true)->values();
        if (in_array($type, ['service', 'no_delivery'], true)) {
            return $this->result($relations->isEmpty() ? 'not_required' : 'wrong_binding', $relations, $relations->isEmpty() ? null : '服务或无需发货 SKU 不应绑定默认 Item');
        }
        if ($relations->isEmpty()) return $this->result('missing', $relations, '实物 SKU 缺少默认 Item');
        if ($relations->count() > 1) return $this->result('duplicate', $relations, '实物 SKU 存在多个启用默认 Item');
        $item = $relations->first()->item;
        if (!$item || $item->status !== 'enabled') return $this->result('item_disabled', $relations, '默认 Item 已停用或不存在');
        return $this->result('normal', $relations, null);
    }

    private function result(string $status, $relations, ?string $reason): array
    {
        return ['check_status' => $status, 'reason' => $reason, 'relations' => $relations, 'checked_at' => now()->toDateTimeString()];
    }
}
