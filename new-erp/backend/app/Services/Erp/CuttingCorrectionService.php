<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

/** Reopens an untouched confirmation as controlled correction WIP; it never restores a cut plate. */
final class CuttingCorrectionService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly DocumentNumberService $numbers) {}

    public function reverse(int $batchId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.confirm');
        $c->assertBatchVisible($batchId, $user, $permissions, $super, 'production.cutting.confirm');

        return $c->run('reverse_cutting_confirmation', $batchId, $payload, $user, function () use ($batchId, $payload, $user, $permissions, $super, $c): array {
            $batch = $c->batch($batchId, $user, $permissions, $super, 'production.cutting.confirm');
            $c->version($batch, $payload);
            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 1000) {
                $c->fail('reason_required', '请填写用料核算更正原因。');
            }
            if ($batch->status !== 'CONFIRMED') {
                $c->fail('cutting_correction_invalid', '只有已核算且尚未更正的用料批次可以发起更正。', 409);
            }
            if (DB::table('erp_cutting_corrections')->where('original_settlement_batch_id', $batchId)->exists()) {
                $c->fail('cutting_correction_exists', '该用料批次已经存在正式更正记录。', 409);
            }

            $results = DB::table('erp_cutting_results')->where('settlement_batch_id', $batchId)
                ->whereNotIn('status', ['VOIDED', 'SUPERSEDED', 'REVERSED'])->orderBy('id')->lockForUpdate()->get();
            if ($results->isEmpty()) {
                $c->fail('cutting_correction_results_missing', '原用料批次没有可更正的核算结果。', 409);
            }
            $resultIds = $results->pluck('id');
            $routes = DB::table('erp_cutting_result_routes')->whereIn('result_id', $resultIds)->where('status', '!=', 'CANCELLED')
                ->orderBy('id')->lockForUpdate()->get();
            $routeIds = $routes->pluck('id');
            if (DB::table('erp_cutting_handovers')->whereIn('route_id', $routeIds)->exists()
                || DB::table('erp_cutting_warehouse_receipts')->whereIn('route_id', $routeIds)->exists()) {
                $c->fail('cutting_correction_downstream_exists', '产出已经发生交接或入库，须先按下游正式业务更正，不能直接冲销本批。', 409);
            }

            $outputHoldingIds = [];
            foreach ($routes as $route) {
                $holding = DB::table('erp_material_holdings')->where('id', $route->holding_id)->lockForUpdate()->first();
                if (! $holding || $holding->status !== 'ACTIVE' || bccomp((string) $holding->quantity, (string) $route->quantity, 8) !== 0
                    || bccomp((string) $holding->total_cost, (string) $route->total_cost, 4) !== 0) {
                    $c->fail('cutting_correction_output_changed', '产出持有份额已经变化，不能直接冲销本批。', 409);
                }
                $outputHoldingIds[] = (int) $holding->id;
            }

            foreach ($results->where('result_type', 'usable_remnant') as $result) {
                $holding = DB::table('erp_material_holdings')->where('position_type', 'REMNANT_WIP')->where('position_id', $result->id)
                    ->orderBy('id')->lockForUpdate()->first();
                if (($result->physical_material_id && DB::table('erp_cutting_settlement_batches')->where('physical_material_id', $result->physical_material_id)->exists())
                    || ($holding && DB::table('erp_cutting_settlement_batches')->where('source_holding_id', $holding->id)->exists())) {
                    $c->fail('cutting_correction_child_recut', '本批形成的子余料已经再次下料，不能冲销父用料核算。', 409);
                }
                $physical = $result->physical_material_id
                    ? DB::table('erp_material_physicals')->where('id', $result->physical_material_id)->lockForUpdate()->first() : null;
                if (! $holding || $holding->status !== 'ACTIVE' || bccomp((string) $holding->quantity, '1', 8) !== 0
                    || bccomp((string) $holding->total_cost, (string) $result->total_cost, 4) !== 0
                    || ($physical && ($physical->status !== 'AVAILABLE' || (int) $physical->current_holding_id !== (int) $holding->id))) {
                    $c->fail('cutting_correction_remnant_changed', '余料持有份额已经变化，不能直接冲销本批。', 409);
                }
                if ($physical && (DB::table('erp_material_physical_reservations')->where('physical_material_id', $physical->id)->where('status', 'ACTIVE')->exists()
                    || DB::table('erp_material_physicals')->where('parent_physical_id', $physical->id)->exists())) {
                    $c->fail('cutting_correction_remnant_changed', '余料已经被占用或形成后续子料，不能直接冲销本批。', 409);
                }
                $outputHoldingIds[] = (int) $holding->id;
            }
            if ($outputHoldingIds !== [] && DB::table('erp_material_movements')->whereIn('source_holding_id', $outputHoldingIds)->exists()) {
                $c->fail('cutting_correction_downstream_exists', '产出或余料已经发生后续物料移动，不能直接冲销本批。', 409);
            }

            $sourceWip = DB::table('erp_material_holdings')->where('id', $batch->wip_holding_id)->lockForUpdate()->first();
            if (! $sourceWip || $sourceWip->status !== 'CONSUMED') {
                $c->fail('cutting_correction_source_invalid', '原投入在制状态不完整，不能建立更正。', 409);
            }

            DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->update(['status' => 'REVERSED',
                'business_version' => $batch->business_version + 1, 'updated_at' => now()]);
            $replacementId = DB::table('erp_cutting_settlement_batches')->insertGetId([
                'batch_no' => $this->numbers->next('cutting_settlement', 'CB'), 'cutting_order_id' => $batch->cutting_order_id,
                'cutting_task_id' => $batch->cutting_task_id, 'input_item_id' => $batch->input_item_id,
                'physical_material_id' => $batch->physical_material_id, 'source_holding_id' => $sourceWip->id,
                'input_qty' => $batch->input_qty, 'original_total_cost' => $batch->original_total_cost,
                'standard_stock_length_mm' => $batch->standard_stock_length_mm, 'status' => 'PROCESSING',
                'business_version' => 1, 'first_cut_at' => $batch->first_cut_at ?: now(),
                'correction_of_batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $correctionWipId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $sourceWip->material_lot_id,
                'position_type' => 'CORRECTION_WIP', 'position_id' => $replacementId, 'quantity' => $batch->input_qty,
                'total_cost' => $batch->original_total_cost, 'status' => 'ACTIVE', 'business_version' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_cutting_settlement_batches')->where('id', $replacementId)->update(['wip_holding_id' => $correctionWipId]);

            foreach ($routes as $route) {
                DB::table('erp_material_holdings')->where('id', $route->holding_id)->update(['quantity' => '0', 'total_cost' => '0',
                    'status' => 'REVERSED', 'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
                DB::table('erp_cutting_result_routes')->where('id', $route->id)->update(['status' => 'CANCELLED',
                    'business_version' => $route->business_version + 1, 'updated_at' => now()]);
            }
            foreach ($results->where('result_type', 'usable_remnant') as $result) {
                $holding = DB::table('erp_material_holdings')->where('position_type', 'REMNANT_WIP')->where('position_id', $result->id)->first();
                DB::table('erp_material_holdings')->where('id', $holding->id)->update(['quantity' => '0', 'total_cost' => '0',
                    'status' => 'REVERSED', 'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
                if ($result->physical_material_id) {
                    DB::table('erp_material_physicals')->where('id', $result->physical_material_id)
                        ->update(['status' => 'REVERSED', 'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
                }
            }
            DB::table('erp_cutting_output_allocations')->whereIn('result_id', $resultIds)->where('status', 'EFFECTIVE')
                ->update(['status' => 'REVERSED', 'updated_at' => now()]);
            DB::table('erp_cutting_cost_dispositions')->whereIn('result_id', $resultIds)->where('status', 'RECORDED')
                ->update(['status' => 'REVERSED', 'updated_at' => now()]);
            DB::table('erp_cutting_results')->whereIn('id', $resultIds)->update(['status' => 'REVERSED', 'voided_at' => now(),
                'voided_by_legacy_id' => $c->actor($user), 'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
            DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'),
                'source_holding_id' => $sourceWip->id, 'target_holding_id' => $correctionWipId, 'action' => 'CORRECTION_REOPEN',
                'quantity' => $batch->input_qty, 'total_cost' => $batch->original_total_cost,
                'operator_legacy_id' => $c->actor($user), 'created_at' => now(), 'updated_at' => now()]);
            if ($batch->physical_material_id) {
                DB::table('erp_material_physicals')->where('id', $batch->physical_material_id)->update([
                    'status' => 'CORRECTION', 'current_holding_id' => $correctionWipId,
                    'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
            }
            $correctionId = DB::table('erp_cutting_corrections')->insertGetId(['correction_no' => $this->numbers->next('cutting_correction', 'CC'),
                'original_settlement_batch_id' => $batchId, 'correction_settlement_batch_id' => $replacementId,
                'status' => 'OPEN', 'reason' => $reason, 'created_by_legacy_id' => $c->actor($user),
                'reversed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $response = ['correction_id' => $correctionId, 'original_settlement_batch_id' => $batchId,
                'correction_settlement_batch_id' => $replacementId, 'status' => 'OPEN', 'business_version' => 1];
            $c->event('batch', $batchId, 'reverse_confirmation', $user, $batch, $response + ['reason' => $reason]);

            return $response;
        });
    }
}
