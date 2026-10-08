<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionOutputRecord;
use Illuminate\Support\Facades\DB;

/** Quantity-aware traversal of immutable production facts, used only by PC statistics. */
class ProductionOutputTraceService
{
    private const INVALID_OUTPUT_STATUSES = ['QUALITY_FAILED', 'REWORK', 'REJECTED', 'HANDOVER_REJECTED', 'CANCELLED'];

    public function contributions(int $outputRecordId, string $baseQty): array
    {
        $rows = [];
        $this->walk($outputRecordId, $baseQty, '1', [], $rows);
        return array_values($rows);
    }

    private function walk(int $id, string $qty, string $basis, array $path, array &$rows): void
    {
        $output = ProductionOutputRecord::find($id);
        if (! $output || in_array($output->status, self::INVALID_OUTPUT_STATUSES, true) || isset($path[$id]) || bccomp($qty, '0', 8) <= 0
            || bccomp($qty, (string) $output->output_base_qty, 8) > 0) {
            $rows['missing:'.$id] = ['output_record_id' => $id, 'trace_status' => 'pending'];
            return;
        }
        $path[$id] = true;
        $total = (string) DB::table('erp_production_output_records')->where('source_target_type', $output->source_target_type)
            ->where('source_target_id', $output->source_target_id)->whereNotIn('status', self::INVALID_OUTPUT_STATUSES)->sum('output_base_qty');
        $key = $output->source_target_type.':'.$output->source_target_id;
        $row = ['target_type' => $output->source_target_type, 'target_id' => (int) $output->source_target_id,
            'work_order_id' => (int) $output->work_order_id, 'output_record_id' => $id,
            'allocated_base_qty' => $qty, 'output_base_qty' => (string) $output->output_base_qty,
            'total_target_output_qty' => $total, 'fraction' => bcdiv($qty, (string) $output->output_base_qty, 12),
            'basis_fraction' => $basis, 'trace_status' => 'complete'];
        if (isset($rows[$key])) {
            // Multiple ancestry paths may converge on the same operation. Its amount coverage
            // cannot exceed this terminal slice, and its physical coverage cannot exceed its output.
            $row['basis_fraction'] = bcadd($rows[$key]['basis_fraction'], $basis, 12);
            if (bccomp($row['basis_fraction'], '1', 12) > 0) $row['basis_fraction'] = '1';
            $row['allocated_base_qty'] = bcadd($rows[$key]['allocated_base_qty'], $qty, 8);
            if (bccomp($row['allocated_base_qty'], $total, 8) > 0) $row['trace_status'] = 'pending';
        }
        $rows[$key] = $row;
        $links = DB::table('erp_production_output_lineage_links')->where('child_output_record_id', $id)->orderBy('id')->get();
        if ($links->isEmpty()) return;
        $parents = $links->map(fn ($link) => ProductionOutputRecord::find($link->parent_output_record_id));
        // Component ancestry must not dilute an earlier product phase: group equivalent
        // source checkpoints by item + operation identity; each phase covers the product slice.
        $groups = [];
        foreach ($links as $index => $link) {
            $parent = $parents[$index];
            if (! $parent || $link->parent_base_qty === null || $link->child_base_qty === null
                || bccomp((string) $link->parent_base_qty, '0', 8) <= 0 || bccomp((string) $link->child_base_qty, '0', 8) <= 0) {
                $rows['link:'.$link->id] = ['output_record_id' => (int) $link->parent_output_record_id, 'trace_status' => 'pending'];
                continue;
            }
            $table = $parent->source_target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
            $node = DB::table($table)->where('id', $parent->source_target_id)->first();
            $wo = DB::table('erp_work_orders')->where('id', $parent->work_order_id)->first(['routing_snapshot']);
            $route = $wo ? (json_decode((string) $wo->routing_snapshot, true) ?: []) : [];
            $occurrence = collect($route['operations'] ?? [])->filter(fn ($entry) => ($entry['execution_context'] ?? 'production') === 'production'
                && (int) ($entry['operation_id'] ?? 0) === (int) ($node?->operation_id_snapshot ?? 0)
                && (int) ($entry['sequence'] ?? 0) <= (int) ($node?->sequence_no_snapshot ?? 0))->count();
            // The reusable operation and its occurrence identify a phase across copied
            // route versions. Every source retains its own target, owner and frozen rate.
            $group = $parent->output_item_id.':'.($node?->operation_id_snapshot ?? 'missing').':'.($occurrence ?: $node?->sequence_no_snapshot);
            $groups[$group][] = [$link, $parent];
        }
        foreach ($groups as $group) {
            $sum = '0'; foreach ($group as [$link]) $sum = bcadd($sum, (string) $link->parent_base_qty, 8);
            foreach ($group as [$link, $parent]) {
                $allocated = bcmul((string) $link->parent_base_qty, bcdiv($qty, (string) $link->child_base_qty, 12), 8);
                $share = bcmul($basis, bcdiv((string) $link->parent_base_qty, $sum, 12), 12);
                $this->walk((int) $parent->id, $allocated, $share, $path, $rows);
            }
        }
    }
}
