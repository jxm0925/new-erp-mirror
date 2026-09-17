<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class ProductionTargetReadinessService
{
    public function project(string $targetType, object $target): array
    {
        $eligibleState = in_array($target->status, ['CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER'], true);
        $pendingHandover = DB::table('erp_production_operation_handovers')
            ->where('target_target_type', $targetType)->where('target_target_id', $target->id)
            ->where('status', 'WAIT_RECEIVE')->exists();
        $pendingCuttingHandover = DB::table('erp_cutting_handovers')->where('target_type', $targetType)->where('target_id', $target->id)
            ->whereIn('status', ['IN_TRANSIT', 'PARTIAL'])->exists();
        if ($pendingHandover || $pendingCuttingHandover) {
            return $this->result('handover_confirmation_required', '上一工序或下料产出尚未接收，必须先完成交接。', [], false);
        }
        if (! $eligibleState) {
            return $this->result(null, null, [], false, true);
        }
        if (! $target->kitting_required) {
            return $this->result(null, null, [], false, true);
        }

        $latestChecks = DB::table('erp_production_workstation_stock_confirmations')
            ->selectRaw('target_material_requirement_id, MAX(id) as latest_id')
            ->groupBy('target_material_requirement_id');
        $rows = DB::table('erp_production_target_material_requirements as requirement')
            ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'requirement.material_supply_rule_snapshot_id')
            ->leftJoinSub($latestChecks, 'latest_check', fn ($join) => $join->on('latest_check.target_material_requirement_id', '=', 'requirement.id'))
            ->leftJoin('erp_production_workstation_stock_confirmations as check', 'check.id', '=', 'latest_check.latest_id')
            ->where('requirement.target_type', $targetType)->where('requirement.target_id', $target->id)
            ->where('supply.participates_in_kitting_snapshot', true)
            ->get([
                'requirement.id', 'requirement.component_item_id', 'requirement.required_base_qty',
                'requirement.cut_length_mm_snapshot', 'requirement.required_piece_qty_snapshot',
                'requirement.satisfied_base_qty', 'requirement.returned_base_qty', 'supply.supply_mode_snapshot',
                'check.onsite_available_base_qty_snapshot', 'check.shortage_base_qty_snapshot', 'check.result',
            ]);

        $shortages = [];
        $onsiteMissing = false;
        $onsiteInsufficient = false;
        foreach ($rows as $row) {
            $mode = $row->supply_mode_snapshot === 'line_side_stock' ? 'workstation_stock' : $row->supply_mode_snapshot;
            if ($mode === 'workstation_stock') {
                if ($row->result === null) {
                    $onsiteMissing = true;
                    continue;
                }
                if ($row->result === 'INSUFFICIENT') {
                    $onsiteInsufficient = true;
                    $shortages[] = $this->shortage($row, (float) $row->shortage_base_qty_snapshot, (float) $row->onsite_available_base_qty_snapshot, $mode);
                }
                continue;
            }
            $available = max(0, (float) $row->satisfied_base_qty - (float) $row->returned_base_qty);
            $shortage = max(0, (float) $row->required_base_qty - $available);
            if ($shortage > 0.00000001) $shortages[] = $this->shortage($row, $shortage, $available, $mode);
        }

        $materialShortages = array_values(array_filter($shortages, fn (array $row): bool => $row['supply_mode'] !== 'workstation_stock'));
        if ($materialShortages !== []) {
            return $this->result('materials_not_ready', '配送、领用或交接物料尚未真实到位，不能开工。', $shortages, false);
        }
        if ($onsiteMissing) {
            return $this->result('onsite_confirmation_required', '其他物料已具备，等待负责人核对工位常备料。', $shortages, $eligibleState);
        }
        if ($onsiteInsufficient) {
            return $this->result('workstation_stock_insufficient', '上次工位核对数量不足，可补足后重新核对。', $shortages, $eligibleState);
        }
        return $this->result('kitting_confirmation_required', '物料条件已满足，等待负责人确认齐套并开工。', [], $eligibleState, true);
    }

    private function shortage(object $row, float $shortage, float $available, string $mode): array
    {
        return [
            'requirement_id' => (int) $row->id,
            'component_item_id' => (int) $row->component_item_id,
            'supply_mode' => $mode,
            'required_base_qty' => (float) $row->required_base_qty,
            'cut_length_mm' => $row->cut_length_mm_snapshot === null ? null : (float) $row->cut_length_mm_snapshot,
            'required_piece_qty' => $row->required_piece_qty_snapshot === null ? null : (float) $row->required_piece_qty_snapshot,
            'available_base_qty' => $available,
            'shortage_base_qty' => $shortage,
        ];
    }

    private function result(?string $code, ?string $message, array $shortages, bool $confirmAllowed, bool $ready = false): array
    {
        return [
            'ready' => $ready,
            'reason_code' => $code,
            'reason_message' => $message,
            'shortages' => $shortages,
            'onsite_confirmations_required' => in_array($code, ['onsite_confirmation_required', 'workstation_stock_insufficient'], true),
            'confirm_kitting_allowed' => $confirmAllowed,
        ];
    }
}
