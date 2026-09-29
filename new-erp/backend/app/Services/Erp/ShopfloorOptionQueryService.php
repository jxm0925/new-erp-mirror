<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemCategory, ProductionRouting, ProductionRoutingOperation};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;


/** Only the identity fields needed to perform an authorized shop-floor business action. */
final class ShopfloorOptionQueryService
{
    public function options(string $type, array $filters, array $permissions, bool $super, ?object $user = null): LengthAwarePaginator
    {
        abort_unless($super || array_intersect(['production.work_order.create', 'production.work_order.edit'], $permissions), 403, '无权选择工单基础资料。');
        abort_unless(in_array($type, ['categories', 'items', 'routings', 'routing_operations', 'routing_preview', 'reserved_work_orders', 'reserved_units', 'reserved_operations'], true), 422, '不支持的工单资料类型。');
        $size = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if (str_starts_with($type, 'reserved_')) {
            abort_unless($user, 401, '请先登录。');
            return $this->reservedOptions($type, $filters, $permissions, $super, $user, $size, $keyword);
        }

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
                app(StockPrebuildEligibilityService::class)->assertItems((int) $filters['output_item_id']);
                $query = ProductionRouting::query()->where('status', 'active')->where('output_item_id', $filters['output_item_id'])->orderByDesc('is_default')->orderByDesc('version');
                $code = 'routing_no'; $name = 'routing_name'; $fields = ['id', $code, $name, 'version', 'is_default', 'output_item_id'];
                break;
            case 'routing_operations':
            case 'routing_preview':
                abort_unless(!empty($filters['routing_id']) && !empty($filters['output_item_id']), 422, '请先选择产出物料和工艺路线。');
                app(StockPrebuildEligibilityService::class)->assertItems((int) $filters['output_item_id']);
                $route = ProductionRouting::query()->where('status', 'active')->where('output_item_id', $filters['output_item_id'])->findOrFail($filters['routing_id']);
                $query = ProductionRoutingOperation::query()->with('operation:id,operation_no,operation_name')->where('routing_id', $route->id)->orderBy('sequence');
                if ($type === 'routing_operations') $query->whereIn('output_item_id', $this->items()->select('id'));
                if ($type === 'routing_preview' && !empty($filters['target_routing_operation_id'])) {
                    $end = ProductionRoutingOperation::where('routing_id', $route->id)->findOrFail($filters['target_routing_operation_id']);
                    $query->where('sequence', '<=', $end->sequence);
                }
                if ($keyword !== '') $query->whereHas('operation', fn ($q) => $q->where('operation_name', 'like', "%{$keyword}%")->orWhere('operation_no', 'like', "%{$keyword}%"));
                return $query->paginate($size)->through(fn ($row) => [
                    'id' => $row->id, 'code' => $row->operation?->operation_no, 'name' => $row->sequence.' - '.$row->operation?->operation_name,
                    'detail' => '工序顺序 '.$row->sequence, 'sequence' => $row->sequence, 'operation_id' => $row->operation_id,
                    'output_item_id' => $row->output_item_id, 'operation_name' => $row->operation?->operation_name,
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
        return app(StockPrebuildEligibilityService::class)->items();
    }

    private function reservedOptions(string $type, array $filters, array $permissions, bool $super, object $user, int $size, string $keyword): LengthAwarePaginator
    {
        $service = app(StockPrebuildTargetService::class);
        $targets = $service->candidates($service->outputItem($filters), $user, $permissions, $super);
        if ($type !== 'reserved_work_orders') {
            abort_unless(! empty($filters['reserved_for_work_order_id']), 422, '请先选择目标工单。');
            $targets->where('work_order_id', (int) $filters['reserved_for_work_order_id']);
        }
        if ($type === 'reserved_work_orders') {
            $query = DB::table('erp_work_orders as wo')->whereIn('wo.id', $targets->select('work_order_id'))
                ->leftJoin('erp_items as item', 'item.id', '=', 'wo.output_item_id');
            if (!empty($filters['status'])) $query->where('wo.status', $filters['status']);
            if ($keyword !== '') $query->where(fn ($q) => $q->where('wo.work_order_no', 'like', "%{$keyword}%")
                ->orWhere('item.item_code', 'like', "%{$keyword}%")->orWhere('item.item_name', 'like', "%{$keyword}%")->orWhere('item.spec', 'like', "%{$keyword}%"));
            return $query->select('wo.id', 'wo.work_order_no as code', 'wo.work_order_no as name', 'item.item_name as detail',
                'item.spec', 'wo.status', 'wo.production_execution_mode_snapshot as execution_mode')->orderByDesc('wo.id')->paginate($size);
        }
        if ($type === 'reserved_units') {
            $query = DB::table('erp_production_units as pu')->whereIn('pu.id', $targets->select('production_unit_id'));
            if ($keyword !== '') $query->where('pu.unit_no', 'like', "%{$keyword}%");
            return $query->select('pu.id', 'pu.unit_no as code', 'pu.unit_no as name')->orderBy('pu.id')->paginate($size);
        }
        $targets->where(function ($query) use ($filters): void {
            $query->where('target_type', 'quantity_operation');
            if (! empty($filters['reserved_for_production_unit_id'])) {
                $query->orWhere('production_unit_id', (int) $filters['reserved_for_production_unit_id']);
            }
        });
        if ($keyword !== '') $targets->where(fn ($q) => $q->where('operation_code', 'like', "%{$keyword}%")->orWhere('operation_name', 'like', "%{$keyword}%"));
        return $targets->orderBy('sequence_no')->orderBy('target_id')->paginate($size)->through(fn ($row) => [
            'id' => (int) $row->routing_operation_id, 'code' => $row->operation_code, 'name' => $row->operation_name,
            'detail' => '工序顺序 '.$row->sequence_no, 'sequence' => (int) $row->sequence_no,
            'target_type' => $row->target_type, 'target_id' => (int) $row->target_id,
        ]);
    }
}
