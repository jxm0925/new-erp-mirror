<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemCategory, ProductionRouting, ProductionRoutingOperation};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;


/** Only the identity fields needed to perform an authorized shop-floor business action. */
final class ShopfloorOptionQueryService
{
    public function options(string $type, array $filters, array $permissions, bool $super): LengthAwarePaginator
    {
        abort_unless($super || array_intersect(['production.work_order.create', 'production.work_order.edit'], $permissions), 403, '无权选择工单基础资料。');
        abort_unless(in_array($type, ['categories', 'items', 'routings', 'routing_operations'], true), 422, '不支持的工单资料类型。');
        $size = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        $fields = []; $name = ''; $code = '';
        switch ($type) {
            case 'items':
                $query = $this->items()->with('unit.standardUnit');
                if (!empty($filters['category_id'])) $query->where('category_id', $filters['category_id']);
                $code = 'item_code'; $name = 'item_name';
                $fields = ['id', 'item_code', 'item_name', 'spec', 'category_id', 'unit_id', 'is_stock_item', 'is_serial_managed', 'serial_tracking_mode', 'material_management_mode', 'cutting_mode', 'is_length_cut_material', 'standard_stock_length_mm', 'production_execution_mode'];
                break;
            case 'categories':
                $query = ItemCategory::query()->whereIn('id', $this->items()->select('category_id'))->whereIn('status', ['active', 'enabled']);
                $code = 'category_code'; $name = 'category_name'; $fields = ['id', $code, $name];
                break;
            case 'routings':
                abort_unless(!empty($filters['output_item_id']), 422, '请先选择产出物料。');
                $query = ProductionRouting::query()->where('status', 'active')->where('output_item_id', $filters['output_item_id'])->orderByDesc('is_default')->orderByDesc('version');
                $code = 'routing_no'; $name = 'routing_name'; $fields = ['id', $code, $name, 'version', 'is_default', 'output_item_id'];
                break;
            case 'routing_operations':
                abort_unless(!empty($filters['routing_id']) && !empty($filters['output_item_id']), 422, '请先选择产出物料和工艺路线。');
                $route = ProductionRouting::query()->where('status', 'active')->where('output_item_id', $filters['output_item_id'])->findOrFail($filters['routing_id']);
                $query = ProductionRoutingOperation::query()->with('operation:id,operation_no,operation_name')->where('routing_id', $route->id)->orderBy('sequence');
                if ($keyword !== '') $query->whereHas('operation', fn ($q) => $q->where('operation_name', 'like', "%{$keyword}%")->orWhere('operation_no', 'like', "%{$keyword}%"));
                return $query->paginate($size)->through(fn ($row) => [
                    'id' => $row->id, 'code' => $row->operation?->operation_no, 'name' => $row->sequence.' - '.$row->operation?->operation_name,
                    'detail' => '工序顺序 '.$row->sequence, 'sequence' => $row->sequence, 'operation_id' => $row->operation_id,
                ]);
        }
        if ($keyword !== '') $query->where(function ($q) use ($keyword, $code, $name, $type): void {
            $q->where($code, 'like', "%{$keyword}%")->orWhere($name, 'like', "%{$keyword}%");
            if ($type === 'items') $q->orWhere('spec', 'like', "%{$keyword}%");
        });
        return $query->select($fields)->orderBy('id')->paginate($size)->through(function ($row) use ($type, $code, $name): array {
            $data = $row->toArray();
            $data['code'] = $row->{$code}; $data['name'] = $row->{$name}; $data['detail'] = $type === 'items' ? ($row->spec ?: '') : '';
            if ($type === 'routings') $data['detail'] = 'V'.$row->version.($row->is_default ? ' · 默认路线' : '');
            if ($type === 'items') {
                $unit = app(UnitConversionDomainService::class)->canonicalUnit($row->unit);
                $data['unit_id'] = $unit?->id; $data['unit_name'] = $unit?->unit_name;
                $data['allow_decimal'] = (bool) $unit?->allow_decimal; $data['decimal_places'] = (int) ($unit?->decimal_places ?? 0);
            }
            return $data;
        });
    }

    private function items(): Builder
    {
        return Item::query()->where('status', 'enabled')->where('is_production_item', true);
    }
}
