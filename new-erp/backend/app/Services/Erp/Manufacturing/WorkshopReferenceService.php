<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{MfgDrawing, MfgWorkOrder, MfgWorkOrderLink, MfgWorkOrderTech, SalesOrderAttachment, SalesOrderLine, ShipmentPackingOperation};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** 车间执行资料：只返回生产要求与有效图纸，不返回订单金额、合同或付款附件。 */
class WorkshopReferenceService
{
    private const TYPES = ['public_attachment', 'design_drawing', 'customer_drawing', 'technical_agreement', 'configuration_note', 'other'];

    public function forWorkOrder(MfgWorkOrder $wo, ?int $routingOperationId = null): array
    {
        $result = ['notes' => [], 'files' => []];
        $seen = [];
        $current = $wo;
        for ($depth = 0; $current && $depth < 8 && ! isset($seen[$current->id]); $depth++) {
            $seen[$current->id] = true;
            $source = ($depth ? '关联工单 ' : '工单 ').$current->work_order_no;
            $this->note($result, '工单备注', $current->remark, $source);
            $this->note($result, '工艺与特殊要求', $current->special_requirement, $source);
            if ($depth === 0) {
                foreach ((array) data_get($current->routing_snapshot, 'operations', []) as $node) {
                    if ($routingOperationId && (int) ($node['routing_operation_id'] ?? 0) !== $routingOperationId) continue;
                    $name = $node['operation_name'] ?? '工序';
                    $this->note($result, $name.'工艺参数', data_get($node, 'parameters.text'), $source);
                    $this->note($result, $name.'工序备注', $node['remark'] ?? null, $source);
                }
            }
            $tech = MfgWorkOrderTech::query()->where('work_order_id', $current->id)->first();
            $this->note($result, '技术说明', $tech?->remark, $source);
            foreach (MfgDrawing::query()->where('work_order_id', $current->id)->where('status', 'active')->orderBy('id')->get() as $drawing) {
                $this->file($result, ['id' => 'drawing-'.$drawing->id, 'name' => $drawing->drawing_name ?: $drawing->original_name,
                    'url' => $this->publicUrl($drawing->url, $drawing->storage_path), 'mime_type' => $drawing->mime_type,
                    'version' => $drawing->version_no, 'updated_at' => $drawing->uploaded_at?->toDateTimeString(),
                    'source' => $source.' · 技术图纸']);
            }
            $parentId = MfgWorkOrderLink::query()->where('child_work_order_id', $current->id)->value('parent_work_order_id')
                ?: $current->rework_of_work_order_id;
            $current = $parentId ? MfgWorkOrder::query()->find($parentId) : null;
        }
        [$orderId, $lineId] = app(OrderBrief::class)->resolve($wo);
        return $this->merge($result, $orderId ? $this->forSalesOrder($orderId, $lineId) : []);
    }

    public function forShipmentOperation(ShipmentPackingOperation $operation): array
    {
        $result = ['notes' => [], 'files' => []];
        $contents = $operation->contents()->current()->with('shipmentLine.orderLine')->get();
        $workOrderIds = [];
        foreach ($contents as $content) {
            $line = $content->shipmentLine;
            if ($line?->sales_order_id && $content->sales_order_line_id) {
                $result = $this->merge($result, $this->forSalesOrder((int) $line->sales_order_id, (int) $content->sales_order_line_id));
            } elseif ($operation->shipment?->sales_order_id) {
                $result = $this->merge($result, $this->forSalesOrder((int) $operation->shipment->sales_order_id,
                    $content->sales_order_line_id ? (int) $content->sales_order_line_id : null));
            }
            $serialIds = $content->serial_snapshot['inventory_serial_ids'] ?? [];
            foreach (DB::table('erp_mfg_work_order_units')->whereIn('inventory_serial_id', $serialIds ?: [0])->pluck('work_order_id') as $id) $workOrderIds[(int) $id] = true;
            // 无编号产品仍可从已冻结的发货来源找到生产工单。
            foreach ((array) data_get($content->source_snapshot, 'sources', []) as $source) {
                $id = (int) ($source['work_order_id'] ?? data_get($source, 'source_evidence.work_order_id', 0));
                if ($id) $workOrderIds[$id] = true;
            }
            if ($line?->batch_no) {
                $id = MfgWorkOrder::query()->where('work_order_no', $line->batch_no)->where('item_id', $content->item_id)->value('id');
                if ($id) $workOrderIds[(int) $id] = true;
            }
        }
        foreach (MfgWorkOrder::query()->whereIn('id', array_keys($workOrderIds) ?: [0])->get() as $wo) {
            $result = $this->merge($result, $this->forWorkOrder($wo));
        }
        $this->note($result, '发货备注', $operation->shipment?->remark, $operation->shipment?->shipment_no ?: '发货单');
        return $result;
    }

    private function forSalesOrder(int $orderId, ?int $lineId): array
    {
        $result = ['notes' => [], 'files' => []];
        $order = DB::table('erp_sales_orders')->where('id', $orderId)->first(['sales_order_no', 'order_remark', 'remark']);
        if (! $order) return $result;
        $source = $order->sales_order_no;
        $this->note($result, '订单备注', $order->order_remark ?: $order->remark, $source);
        if ($order->order_remark && $order->remark && trim($order->order_remark) !== trim($order->remark)) {
            $this->note($result, '订单补充备注', $order->remark, $source);
        }
        $line = $lineId ? SalesOrderLine::query()->where('sales_order_id', $orderId)->find($lineId) : null;
        if ($line) {
            $source .= ' 第'.$line->line_no.'行';
            $this->note($result, '本行备注', $line->remark, $source);
            $this->note($result, '定制要求', $line->customization_description ?: data_get($line->configuration_snapshot, 'special_custom_description'), $source);
            $design = trim((string) $line->design);
            if ($design !== '' && ! preg_match('#^(https?://|/|storage/)#i', $design)) $this->note($result, '设计要求', $design, $source);
            elseif ($design !== '') $this->file($result, ['id' => 'design-'.$line->id, 'name' => '订单设计图', 'url' => $design, 'source' => $source]);
            $this->images($result, $line->image, $source, '订单图片');
            $this->images($result, data_get($line->product_snapshot, 'image'), $source, '产品参考图');
            $this->images($result, data_get($line->sku_snapshot, 'image'), $source, '规格参考图');
        }
        $attachments = SalesOrderAttachment::query()->where('sales_order_id', $orderId)->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('attachment_type', self::TYPES)->where(function ($query) use ($line) {
                $query->where(fn ($q) => $q->where('attachment_scope', 'order')->whereNull('sales_order_line_id'));
                if ($line) $query->orWhere(fn ($q) => $q->where('attachment_scope', 'line')->where('sales_order_line_id', $line->id));
            })->orderBy('id')->get();
        foreach ($attachments as $attachment) {
            // 订单级“其他”只收图片；订单行明确上传的其他生产资料沿用技术资料范围。
            if ($attachment->attachment_scope === 'order' && $attachment->attachment_type === 'other'
                && ! str_starts_with((string) $attachment->mime_type, 'image/')) continue;
            $this->file($result, ['id' => 'attachment-'.$attachment->id, 'name' => $attachment->original_name,
                'url' => $this->publicUrl($attachment->url, $attachment->storage_disk === 'public' ? $attachment->storage_path : null),
                'mime_type' => $attachment->mime_type, 'source' => $source.' · 订单资料',
                'updated_at' => $attachment->uploaded_at?->toDateTimeString()]);
        }
        return $result;
    }

    private function images(array &$result, mixed $value, string $source, string $label): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $value);
        }
        if (! is_array($value)) return;
        if (isset($value['url']) || isset($value['src'])) $value = [$value];
        foreach ($value as $image) {
            $url = trim((string) (is_array($image) ? ($image['url'] ?? $image['src'] ?? '') : $image));
            if ($url === '') continue;
            if (! preg_match('#^(https?://|//|/?storage/)#i', $url)) {
                $url = 'https://media.sdjiantan.com/'.ltrim($url, '/');
            }
            $this->file($result, ['id' => 'image-'.sha1($url), 'name' => $label,
                'url' => $url, 'mime_type' => 'image/jpeg', 'source' => $source]);
        }
    }

    private function note(array &$result, string $label, mixed $value, string $source): void
    {
        $text = trim((string) $value);
        if ($text !== '') $result['notes'][] = ['label' => $label, 'text' => $text, 'source' => $source];
    }

    private function file(array &$result, array $file): void
    {
        if (trim((string) ($file['url'] ?? '')) !== '') $result['files'][] = $file;
    }

    private function publicUrl(?string $url, ?string $path): ?string
    {
        if ($path) {
            $public = Storage::disk('public')->url($path);
            return parse_url($public, PHP_URL_PATH) ?: $public;
        }
        return $url;
    }

    private function merge(array $left, array $right): array
    {
        return ['notes' => collect([...($left['notes'] ?? []), ...($right['notes'] ?? [])])
            ->unique(fn ($note) => $note['label'].'|'.$note['source'].'|'.$note['text'])->values()->all(),
            'files' => collect([...($left['files'] ?? []), ...($right['files'] ?? [])])->unique('url')->values()->all()];
    }
}
