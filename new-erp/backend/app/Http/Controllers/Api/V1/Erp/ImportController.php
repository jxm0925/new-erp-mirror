<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{ImportBatch, ImportRow, Item, Location, Product, Sku, SkuItemRelation, Supplier, Warehouse};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    private const TYPES = ['Product', 'SKU', 'Item', 'Supplier', 'Warehouse', 'Location', 'SKU-Item Relation'];

    public function upload(Request $request)
    {
        $data = $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240', 'import_type' => 'required|in:'.implode(',', self::TYPES)]);
        $path = $request->file('file')->store('erp-imports');
        $batch = ImportBatch::create([
            'batch_no' => 'IMP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'import_type' => $data['import_type'], 'file_name' => $request->file('file')->getClientOriginalName(),
            'stored_path' => $path, 'status' => 'uploaded',
        ]);
        return response()->json(['message' => '上传成功', 'data' => $batch], 201);
    }

    public function preview(int $id)
    {
        $batch = ImportBatch::findOrFail($id);
        $fullPath = storage_path('app/private/'.$batch->stored_path);
        if (!is_file($fullPath)) $fullPath = storage_path('app/'.$batch->stored_path);
        abort_unless(is_file($fullPath), 404, '导入文件不存在');
        $sheet = IOFactory::load($fullPath)->getActiveSheet()->toArray(null, true, true, false);
        abort_if(count($sheet) < 2, 422, '文件没有可导入的数据');
        $headers = array_map(fn ($value) => trim((string) $value), array_shift($sheet));
        $batch->rows()->delete();
        $counts = ['valid' => 0, 'warning' => 0, 'error' => 0];
        foreach ($sheet as $index => $values) {
            if (!array_filter($values, fn ($v) => $v !== null && trim((string) $v) !== '')) continue;
            $raw = [];
            foreach ($headers as $i => $header) if ($header !== '') $raw[$header] = $values[$i] ?? null;
            [$status, $field, $type, $reason, $suggestion] = $this->validateRow($batch->import_type, $raw);
            $counts[$status]++;
            ImportRow::create([
                'batch_id' => $batch->id, 'row_no' => $index + 2, 'raw_data' => $raw, 'normalized_data' => $raw,
                'validation_status' => $status, 'error_field' => $field, 'error_type' => $type,
                'error_reason' => $reason, 'suggestion' => $suggestion,
            ]);
        }
        $batch->update([
            'status' => 'previewed', 'total_rows' => array_sum($counts), 'valid_rows' => $counts['valid'],
            'warning_rows' => $counts['warning'], 'error_rows' => $counts['error'],
        ]);
        return response()->json(['message' => '预检完成', 'data' => $batch->fresh(), 'rows' => $batch->rows()->paginate(50)]);
    }

    public function rows(Request $request, int $id)
    {
        $query = ImportRow::where('batch_id', $id);
        if ($request->filled('status')) $query->where('validation_status', $request->status);
        return response()->json($query->orderBy('row_no')->paginate(min(200, max(10, $request->integer('per_page', 50)))));
    }

    public function confirm(int $id)
    {
        $batch = ImportBatch::with(['rows' => fn ($q) => $q->whereIn('validation_status', ['valid', 'warning'])])->findOrFail($id);
        abort_if($batch->status === 'confirmed', 422, '该批次已经确认导入');
        DB::transaction(function () use ($batch) {
            foreach ($batch->rows as $row) {
                $target = $this->persist($batch->import_type, $row->normalized_data ?? $row->raw_data);
                if ($target) $row->update(['target_id' => $target->id]);
            }
            $batch->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        });
        return response()->json(['message' => "已导入 {$batch->rows->count()} 条正确数据", 'data' => $batch->fresh()]);
    }

    public function exportErrors(int $id): StreamedResponse
    {
        $batch = ImportBatch::findOrFail($id);
        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['行号', '字段', '错误类型', '错误原因', '原始值', '建议处理方式']);
            ImportRow::where('batch_id', $batch->id)->where('validation_status', 'error')->orderBy('row_no')
                ->each(fn ($row) => fputcsv($out, [$row->row_no, $row->error_field, $row->error_type, $row->error_reason, json_encode($row->raw_data, JSON_UNESCAPED_UNICODE), $row->suggestion]));
            fclose($out);
        }, $batch->batch_no.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validateRow(string $type, array $row): array
    {
        $definitions = [
            'Product' => ['code' => ['product_code', '商品编码'], 'name' => ['product_name', '商品名称']],
            'SKU' => ['code' => ['sku_code', 'SKU编码'], 'name' => ['sku_name', 'SKU名称'], 'parent' => ['product_code', '商品编码']],
            'Item' => ['code' => ['item_code', '物料编码'], 'name' => ['item_name', '物料名称'], 'unit' => ['unit_code', '单位编码']],
            'Supplier' => ['code' => ['supplier_code', '供应商编码'], 'name' => ['supplier_name', '供应商名称']],
            'Warehouse' => ['code' => ['warehouse_code', '仓库编码'], 'name' => ['warehouse_name', '仓库名称']],
            'Location' => ['code' => ['location_code', '库位编码'], 'name' => ['location_name', '库位名称'], 'warehouse' => ['warehouse_code', '仓库编码']],
            'SKU-Item Relation' => ['sku' => ['sku_code', 'SKU编码'], 'item' => ['item_code', '物料编码'], 'relation' => ['relation_type', '关系类型']],
        ];
        $def = $definitions[$type];
        $get = fn (array $keys) => collect($keys)->map(fn ($k) => $row[$k] ?? null)->first(fn ($v) => $v !== null && trim((string) $v) !== '');
        foreach ($def as $label => $keys) {
            if ($get($keys) === null) return ['error', $keys[0], 'required', "{$keys[1]}不能为空", "补充{$keys[1]}"];
        }
        $code = isset($def['code']) ? trim((string) $get($def['code'])) : null;
        $duplicates = ['Product' => [Product::class, 'product_code'], 'SKU' => [Sku::class, 'sku_code'], 'Item' => [Item::class, 'item_code'],
            'Supplier' => [Supplier::class, 'supplier_code'], 'Warehouse' => [Warehouse::class, 'warehouse_code'], 'Location' => [Location::class, 'location_code']];
        if ($code && isset($duplicates[$type]) && $duplicates[$type][0]::where($duplicates[$type][1], $code)->exists()) {
            return ['error', $duplicates[$type][1], 'duplicate', "编码 {$code} 已存在", '使用唯一编码或改为编辑现有数据'];
        }
        if ($type === 'SKU' && !Product::where('product_code', $get($def['parent']))->exists()) return ['error', 'product_code', 'not_found', 'Product 不存在', '先导入 Product'];
        if ($type === 'Location' && !Warehouse::where('warehouse_code', $get($def['warehouse']))->exists()) return ['error', 'warehouse_code', 'not_found', '仓库不存在', '先导入 Warehouse'];
        if ($type === 'SKU-Item Relation') {
            if (!Sku::where('sku_code', $get($def['sku']))->exists()) return ['error', 'sku_code', 'not_found', 'SKU 不存在', '先导入 SKU'];
            if (!Item::where('item_code', $get($def['item']))->exists()) return ['error', 'item_code', 'not_found', 'Item 不存在', '先导入 Item'];
            if (!in_array($get($def['relation']), ['finished_product', 'sales_bundle_item', 'shipping_accessory', 'packaging', 'service_none'], true)) return ['error', 'relation_type', 'invalid', '关系类型不合法', '使用允许的关系类型'];
        }
        return ['valid', null, null, null, null];
    }

    private function persist(string $type, array $row)
    {
        $value = fn (...$keys) => collect($keys)->map(fn ($k) => $row[$k] ?? null)->first(fn ($v) => $v !== null && $v !== '');
        return match ($type) {
            'Product' => Product::create(['product_code' => $value('product_code', '商品编码'), 'product_name' => $value('product_name', '商品名称'), 'product_type' => $value('product_type', '商品类型') ?: 'standard', 'status' => 'enabled']),
            'SKU' => Sku::create(['product_id' => Product::where('product_code', $value('product_code', '商品编码'))->value('id'), 'sku_code' => $value('sku_code', 'SKU编码'), 'sku_name' => $value('sku_name', 'SKU名称'), 'spec_text' => $value('spec_text', '规格'), 'order_line_type' => ($value('order_line_type', '订单行类型') ?: ($value('fulfillment_type', '履约方式') ?: 'physical')) === 'virtual' ? 'no_delivery' : ($value('order_line_type', '订单行类型') ?: ($value('fulfillment_type', '履约方式') ?: 'physical')), 'fulfillment_type' => $value('fulfillment_type', '履约方式') ?: 'physical', 'status' => 'draft']),
            'Item' => Item::create(['item_code' => $value('item_code', '物料编码'), 'item_name' => $value('item_name', '物料名称'), 'item_type' => $value('item_type', '物料类型') ?: 'raw_material', 'unit_id' => \App\Models\Erp\Unit::where('unit_code', $value('unit_code', '单位编码'))->value('id') ?: \App\Models\Erp\Unit::value('id'), 'cost_method' => 'weighted_average', 'status' => 'enabled']),
            'Supplier' => Supplier::create(['supplier_code' => $value('supplier_code', '供应商编码'), 'supplier_name' => $value('supplier_name', '供应商名称'), 'supplier_type' => 'manufacturer', 'status' => 'enabled']),
            'Warehouse' => Warehouse::create(['warehouse_code' => $value('warehouse_code', '仓库编码'), 'warehouse_name' => $value('warehouse_name', '仓库名称'), 'warehouse_type' => 'general', 'status' => 'enabled']),
            'Location' => Location::create(['location_code' => $value('location_code', '库位编码'), 'location_name' => $value('location_name', '库位名称'), 'warehouse_id' => Warehouse::where('warehouse_code', $value('warehouse_code', '仓库编码'))->value('id'), 'status' => 'enabled']),
            'SKU-Item Relation' => SkuItemRelation::create(['sku_id' => Sku::where('sku_code', $value('sku_code', 'SKU编码'))->value('id'), 'item_id' => Item::where('item_code', $value('item_code', '物料编码'))->value('id'), 'relation_type' => $value('relation_type', '关系类型'), 'qty' => $value('qty', '数量') ?: 1, 'is_primary' => (bool) ($value('is_primary', '是否主Item') ?? true), 'status' => 'active']),
        };
    }
}
