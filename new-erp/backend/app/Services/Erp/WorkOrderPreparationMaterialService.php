<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, InventoryBalance, Item, WorkOrder, WorkOrderMaterialRequirement, WorkOrderPreparationMaterial};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Versioned buying authority before release, with no execution or inventory side effects. */
final class WorkOrderPreparationMaterialService
{
    public function schemaReady(): bool
    {
        return Schema::hasColumns('erp_work_order_material_preparations', ['id', 'work_order_id', 'version', 'work_order_version', 'status', 'input_hash', 'input_snapshot', 'prepared_by_legacy_id', 'published_at', 'created_at', 'updated_at'])
            && Schema::hasColumns('erp_work_order_material_preparation_versions', ['id', 'preparation_id', 'version', 'input_hash', 'input_snapshot', 'prepared_by_legacy_id', 'created_at', 'updated_at'])
            && Schema::hasColumns('erp_work_order_preparation_materials', ['id', 'work_order_id', 'preparation_id', 'preparation_version', 'identity_key', 'bom_item_id', 'component_item_id', 'base_unit_id', 'required_base_qty', 'material_snapshot', 'status', 'material_requirement_id', 'created_at', 'updated_at'])
            && Schema::hasColumns('erp_material_procurement_sources', ['preparation_material_requirement_id', 'preparation_version']);
    }

    public function synchronizeLocked(WorkOrder $workOrder, object $user): array
    {
        $this->transaction();
        $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
        if (! $this->schemaReady()) return $this->unavailable('schema_not_ready', '发布前物料准备结构尚未就绪。');
        $this->editable($workOrder);
        $input = $this->calculate($workOrder);
        $hash = $this->hash($input);
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->lockForUpdate()->first();
        if ($header && $this->inputMatches($header->input_snapshot, $input) && $header->status === 'ACTIVE') return $this->projection($workOrder);
        // A changed BOM/configuration must not detach already ordered material. The operator
        // must reverse the old procurement explicitly before a new version can become usable.
        $existing = WorkOrderPreparationMaterial::where('work_order_id', $workOrder->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($existing as $row) {
            if (bccomp($this->pendingForPreparation((int) $row->id), '0', 8) > 0) {
                $this->fail('preparation_procurement_conflict', '物料准备依据已变化，但旧版本仍有未撤销申购；请先处理原采购需求再重新准备。');
            }
        }
        $version = (int) ($header->version ?? 0) + 1;
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $values = ['version' => $version, 'work_order_version' => $workOrder->business_version, 'status' => 'ACTIVE',
            'input_hash' => $hash, 'input_snapshot' => $this->json($input), 'prepared_by_legacy_id' => $actor, 'updated_at' => now()];
        $headerId = $header?->id;
        if ($headerId) DB::table('erp_work_order_material_preparations')->where('id', $headerId)->update($values);
        else $headerId = DB::table('erp_work_order_material_preparations')->insertGetId([...$values, 'work_order_id' => $workOrder->id, 'created_at' => now()]);
        DB::table('erp_work_order_material_preparation_versions')->insert(['preparation_id' => $headerId, 'version' => $version,
            'input_hash' => $hash, 'input_snapshot' => $this->json($input), 'prepared_by_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        WorkOrderPreparationMaterial::where('work_order_id', $workOrder->id)->update(['status' => 'SUPERSEDED']);
        foreach ($input['rows'] as $row) {
            WorkOrderPreparationMaterial::updateOrCreate(['work_order_id' => $workOrder->id, 'identity_key' => $this->materialIdentity($row)], [
                'bom_item_id' => $row['bom_item_id'],
                'preparation_id' => $headerId, 'preparation_version' => $version, 'component_item_id' => $row['component_item_id'],
                'base_unit_id' => $row['base_unit_id'], 'required_base_qty' => $row['base_required_qty'],
                'material_snapshot' => $row, 'status' => 'ACTIVE', 'material_requirement_id' => null,
            ]);
        }
        return $this->projection($workOrder);
    }

    /** Called inside publication, after the ordinary frozen requirements have been created. */
    public function bindPublishedLocked(WorkOrder $workOrder): void
    {
        $this->transaction();
        if (! $this->schemaReady()) {
            if ($this->hasPreparation($workOrder)) $this->fail('schema_not_ready', '已有物料准备记录，但绑定所需结构不完整，不能发布。');
            return; // Old installations have no preparation authority to bind.
        }
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->lockForUpdate()->first();
        if (! $header) return; // Historical work orders retain the original release contract.
        $input = $this->calculate($workOrder);
        if (! $this->inputMatches($header->input_snapshot, $input)) $this->fail('preparation_stale', '物料准备依据已变化，请重新准备后发布。');
        $rows = WorkOrderPreparationMaterial::where('work_order_id', $workOrder->id)->whereIn('status', ['ACTIVE', 'PUBLISHED'])->orderBy('id')->lockForUpdate()->get();
        $formal = WorkOrderMaterialRequirement::where('work_order_id', $workOrder->id)->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== $formal->count() || $rows->count() !== count($input['rows'])) $this->fail('preparation_binding_mismatch', '准备需求与正式物料需求行数不一致。');
        foreach ($rows as $row) {
            $matches = $formal->filter(fn ($candidate) => $this->materialIdentity($candidate->getAttributes()) === $row->identity_key);
            $match = $matches->first();
            if ($matches->count() !== 1 || ! $match || $this->hash($this->materialFacts($match->getAttributes())) !== $this->hash($row->material_snapshot)) {
                $this->fail('preparation_binding_mismatch', '准备需求与发布冻结用料不一致，不能发布。');
            }
            if ($row->material_requirement_id && (int) $row->material_requirement_id !== (int) $match->id) $this->fail('preparation_binding_mismatch', '准备需求已绑定另一条正式需求。');
            $row->update(['material_requirement_id' => $match->id, 'status' => 'PUBLISHED']);
        }
        DB::table('erp_work_order_material_preparations')->where('id', $header->id)->update(['status' => 'PUBLISHED', 'published_at' => now(), 'updated_at' => now()]);
    }

    /** Gate uses this without mutating or silently regenerating a preparation version. */
    public function releaseCheck(WorkOrder $workOrder): ?array
    {
        if (! $this->schemaReady()) return $this->hasPreparation($workOrder)
            ? ['valid' => false, 'code' => 'schema_not_ready', 'message' => '已有物料准备记录，但准备结构不完整，不能发布。', 'version' => null] : null;
        if (! DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->exists()) return null;
        $result = $this->projection($workOrder);
        return ['valid' => $result['ready'], 'code' => $result['issues'][0]['code'] ?? '',
            'message' => $result['issues'][0]['message'] ?? '通过', 'version' => $result['version']];
    }

    public function projection(WorkOrder $workOrder): array
    {
        if (! $this->schemaReady()) return $this->unavailable('schema_not_ready', '发布前物料准备结构尚未就绪。');
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->first();
        if (! $header) return $this->unavailable('not_prepared', '尚未完成物料准备。');
        $issues = [];
        if ($header->status !== 'PUBLISHED') {
            try {
                $this->editable($workOrder);
                if (! $this->inputMatches($header->input_snapshot, $this->calculate($workOrder))) $this->fail('preparation_stale', '物料准备依据已变化，请重新准备。');
            } catch (WorkOrderDomainException $e) { $issues[] = ['code' => $e->errorCode, 'message' => $e->getMessage()]; }
            catch (ValidationException $e) { $issues[] = ['code' => 'preparation_material_invalid', 'message' => collect($e->errors())->flatten()->first() ?: '物料准备参数无效。']; }
        }
        $rows = WorkOrderPreparationMaterial::with('componentItem')->where('preparation_id', $header->id)
            ->where('preparation_version', $header->version)->whereIn('status', ['ACTIVE', 'PUBLISHED'])->orderBy('id')->get();
        return ['status' => $issues ? 'blocked' : strtolower($header->status), 'ready' => $issues === [], 'issues' => $issues,
            'version' => (int) $header->version, 'rows' => $rows->map(fn ($row) => $this->rowProjection($row, $workOrder, $issues === []))->all()];
    }

    public function rowProjection(WorkOrderPreparationMaterial $row, WorkOrder $wo, bool $valid = true): array
    {
        $item = $row->componentItem;
        $snapshot = $row->material_snapshot;
        $secured = $this->securedForPreparation($row);
        $commitments = $this->procurementTotals($this->activeProcurement()->where('source.preparation_material_requirement_id', $row->id));
        $pending = $commitments['pending'];
        $purchasable = $valid && $row->status === 'ACTIVE' && $item?->status === 'enabled' && $item?->is_purchase_item
            && $item->manufacturing_strategy !== 'make' && ($snapshot['requirement_kind'] ?? 'standard') !== 'stock_continuation' && ! $row->material_requirement_id;
        // Shared free stock is informative only. It is not an ownership fact and cannot
        // be counted as guaranteed coverage for several concurrent preparation rows.
        $available = $this->availableStock(new WorkOrderMaterialRequirement($snapshot));
        // Multiple resolved lines of the same Item share a single stock pool. Allocate only
        // a read-only estimate in stable line order; no reservation or execution target is made.
        $earlierRequired = '0.00000000';
        $earlier = WorkOrderPreparationMaterial::where('preparation_id', $row->preparation_id)
            ->where('preparation_version', $row->preparation_version)->where('status', $row->status)
            ->where('component_item_id', $row->component_item_id)->where('id', '<', $row->id)->orderBy('id')->get();
        foreach ($earlier as $prior) {
            if (! $this->sameStockPool($snapshot, $prior->material_snapshot)) continue;
            $priorProcurement = $this->procurementTotals($this->activeProcurement()->where('source.preparation_material_requirement_id', $prior->id));
            $need = $this->positive(bcsub(bcsub((string) $prior->required_base_qty, $this->securedForPreparation($prior), 8), $priorProcurement['pending'], 8));
            $earlierRequired = bcadd($earlierRequired, $need, 8);
        }
        $availableForRow = $this->positive(bcsub($available, $earlierRequired, 8));
        $requirement = new WorkOrderMaterialRequirement($snapshot);
        if (app(ProductionCuttingAllocationService::class)->unitKind($requirement)) {
            // Whole pieces with different geometries can overlap. Claim candidate identities
            // once in line order, rather than subtracting unrelated theoretical fractions.
            $claimed = ['physical_ids' => [], 'balance_qty' => []];
            foreach ($earlier as $prior) {
                if (! $this->sameStockPool($snapshot, $prior->material_snapshot)) continue;
                $priorRequirement = new WorkOrderMaterialRequirement($prior->material_snapshot);
                $priorProcurement = $this->procurementTotals($this->activeProcurement()->where('source.preparation_material_requirement_id', $prior->id));
                $need = $this->positive(bcsub(bcsub((string) $prior->required_base_qty, $this->securedForPreparation($prior), 8), $priorProcurement['pending'], 8));
                if (! app(ProductionCuttingAllocationService::class)->unitKind($priorRequirement)) { $availableForRow = '0.00000000'; break; }
                $this->cuttingAvailableCoverage($priorRequirement, $claimed, $need);
            }
            if (! isset($priorRequirement) || app(ProductionCuttingAllocationService::class)->unitKind($priorRequirement)) {
                $need = $this->positive(bcsub(bcsub((string) $row->required_base_qty, $secured, 8), $pending, 8));
                $availableForRow = $this->cuttingAvailableCoverage($requirement, $claimed, $need);
            }
        }
        $quantities = $this->netQuantities((string) $row->required_base_qty, $secured, $availableForRow, $commitments['committed'], $commitments['received']);
        $shortage = $quantities['shortage_qty'];
        return ['id' => (int) $row->id, 'preparation_material_requirement_id' => (int) $row->id,
            'preparation_version' => (int) $row->preparation_version, 'work_order_id' => (int) $wo->id,
            'work_order_no' => $wo->work_order_no, 'work_order_version' => (int) $wo->business_version,
            'demand_stage' => 'preparation', 'fulfillment_mode' => 'procurement_only', 'can_pick' => false,
            'can_procure' => (bool) $purchasable, 'production_target_type' => null, 'production_target_id' => null,
            'target_material_requirement_id' => null, 'material_requirement_id' => $row->material_requirement_id,
            'component_item_id' => (int) $row->component_item_id, 'item_code' => $snapshot['component_item_code_snapshot'],
            'item_name' => $snapshot['component_item_name_snapshot'], 'spec' => $snapshot['component_spec_snapshot'],
            'unit_name' => $snapshot['base_unit_name_snapshot'], 'configuration_id' => $snapshot['configuration_id'],
            'cut_length_mm_snapshot' => $snapshot['cut_length_mm_snapshot'], 'required_piece_qty_snapshot' => $snapshot['required_piece_qty'],
            'required_qty' => (string) $row->required_base_qty, 'secured_qty' => $secured,
            'available_stock_qty' => $availableForRow, 'unsecured_qty' => $quantities['unsecured_qty'],
            'committed_procurement_qty' => $commitments['committed'], 'received_procurement_qty' => $commitments['received'],
            'pending_procurement_qty' => $pending, 'shortage_qty' => $shortage, 'remaining_to_prepare' => $shortage,
            'procureable_qty' => $purchasable ? $quantities['procureable_qty'] : '0.00000000',
            'status' => $valid ? $row->status : 'BLOCKED', 'business_version' => (int) $row->preparation_version];
    }

    public function assertProcurementLocked(WorkOrderPreparationMaterial $row, WorkOrder $wo, array $line): array
    {
        $this->transaction();
        $this->editable($wo);
        if ((int) ($line['work_order_version'] ?? 0) !== (int) $wo->business_version
            || (int) ($line['preparation_version'] ?? 0) !== (int) $row->preparation_version) $this->fail('version_conflict', '工单或物料准备版本已变化，请刷新后重新申购。');
        $result = $this->projection($wo);
        if (! $result['ready'] || $row->status !== 'ACTIVE' || (int) $result['version'] !== (int) $row->preparation_version) $this->fail('preparation_stale', '物料准备已失效，请重新准备后申购。');
        $projection = $this->rowProjection($row, $wo);
        if (! $projection['can_procure']) $this->fail('preparation_not_purchasable', '该准备需求当前不能申购。');
        if (bccomp((string) $line['request_qty'], (string) $projection['procureable_qty'], 8) > 0) $this->fail('procurement_quantity_exceeded', '申购数量超过当前准备需求的未申购缺口。');
        return $projection;
    }

    public function pendingForPreparation(int $id): string
    {
        if (! $this->schemaReady()) return '0.00000000';
        return $this->procurementTotals($this->activeProcurement()->where('source.preparation_material_requirement_id', $id))['committed'];
    }

    /** Count all targets sharing the same frozen requirement together with its pre-release source. */
    public function pendingForFormal(int $id): string
    {
        return $this->procurementTotals($this->formalProcurement($id))['pending'];
    }

    private function formalProcurement(int $id)
    {
        return $this->activeProcurement()->where(function ($q) use ($id): void {
            $q->whereIn('source.target_material_requirement_id', DB::table('erp_production_target_material_requirements')->where('material_requirement_id', $id)->select('id'));
            if ($this->schemaReady()) $q->orWhereIn('source.preparation_material_requirement_id', WorkOrderPreparationMaterial::where('material_requirement_id', $id)->select('id'));
        });
    }

    public function formalProcurementBalance(object $source): array
    {
        $totals = $this->procurementTotals($this->formalProcurement((int) $source->material_requirement_id));
        $requirement = WorkOrderMaterialRequirement::findOrFail($source->material_requirement_id);
        if (app(ProductionCuttingAllocationService::class)->unitKind($requirement)) {
            $remaining = app(ProductionCuttingAllocationService::class)->remaining($requirement)['formal_coverage_qty'];
            $secured = $this->positive(bcsub((string) $requirement->required_qty, $remaining, 8));
            $quantities = $this->netQuantities((string) $requirement->required_qty, $secured, $this->availableStock($requirement), $totals['committed'], $totals['received']);
            return ['pending_procurement_qty' => $totals['pending'], 'committed_procurement_qty' => $totals['committed'],
                'received_procurement_qty' => $totals['received'], 'procureable_qty' => $quantities['procureable_qty']];
        }
        $targets = DB::table('erp_production_target_material_requirements')->where('material_requirement_id', $source->material_requirement_id)
            ->selectRaw('SUM(required_base_qty) as required, SUM(GREATEST(satisfied_base_qty-returned_base_qty,0)) as satisfied')->first();
        $allocations = DB::table('erp_material_picking_task_lines as line')->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->where('line.material_requirement_id', $source->material_requirement_id)->where('task.status', '<>', 'CANCELLED')
            ->groupBy('line.inventory_balance_id')->select('line.inventory_balance_id')
            ->selectRaw("SUM(CASE WHEN task.status IN ('WAIT_PICK','PICKING') THEN line.planned_pick_qty ELSE 0 END) as pending_pick")
            ->selectRaw("SUM(CASE WHEN task.status IN ('WAIT_PICK','PICKING') THEN 0 ELSE GREATEST(line.actual_pick_qty-line.received_qty,0) END) as in_delivery")->get();
        $assembly = app(AssemblyProductionInventoryService::class);
        $secured = bcadd((string) ($targets->satisfied ?? 0), $assembly->totalOwnedQuantity((int) $requirement->id), 8);
        foreach ($allocations as $allocation) {
            // A pending pick can use the very same assembly reservation. Its covered
            // part is already included in owned quantity and must not be counted twice.
            $owned = $assembly->ownedQuantity((int) $allocation->inventory_balance_id, (int) $requirement->id);
            $secured = bcadd($secured, $this->positive(bcsub((string) $allocation->pending_pick, $owned, 8)), 8);
            $secured = bcadd($secured, (string) $allocation->in_delivery, 8);
        }
        $quantities = $this->netQuantities((string) ($targets->required ?? 0), $secured, $this->availableStock($requirement), $totals['committed'], $totals['received']);
        return ['pending_procurement_qty' => $totals['pending'], 'committed_procurement_qty' => $totals['committed'],
            'received_procurement_qty' => $totals['received'], 'procureable_qty' => $quantities['procureable_qty']];
    }

    private function activeProcurement()
    {
        // Closing releases only the unconverted remainder. Converted procurement remains
        // an obligation; receipt posting changes its in-transit part, not its provenance.
        return DB::table('erp_material_procurement_sources as source')->join('erp_purchase_requests as request', 'request.id', '=', 'source.request_id')
            ->leftJoin('erp_purchase_request_items as request_line', 'request_line.id', '=', 'source.request_item_id')
            ->whereNull('request.deleted_at')
            ->where('request.request_status', '<>', 'cancelled');
    }

    private function procurementTotals($query): array
    {
        $committed = '0.00000000'; $received = '0.00000000';
        foreach ($query->get(['source.*', 'request.request_status', 'request_line.request_qty as current_qty', 'request_line.converted_qty']) as $source) {
            $quantity = $source->request_status === 'closed' ? (string) ($source->converted_qty ?? $source->requested_base_qty)
                : (string) ($source->current_qty ?? $source->requested_base_qty);
            $committed = bcadd($committed, $quantity, 8);
            if (! $source->request_item_id) continue;
            $posted = (string) DB::table('erp_inventory_transaction_items as movement')
                ->join('erp_inventory_transactions as posting', 'posting.id', '=', 'movement.transaction_id')
                ->join('erp_purchase_receipt_items as receipt', 'receipt.id', '=', 'movement.source_item_id')
                ->join('erp_purchase_order_items as ordered', 'ordered.id', '=', 'receipt.order_item_id')
                ->where('ordered.request_item_id', $source->request_item_id)->where('movement.item_id', $source->component_item_id)
                ->where('movement.source_type', 'purchase_receipt')->whereIn('posting.transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release'])
                ->where('posting.posting_status', 'posted')->sum('movement.change_qty');
            $received = bcadd($received, bccomp($posted, $quantity, 8) < 0 ? $posted : $quantity, 8);
        }
        return ['committed' => $committed, 'received' => $received, 'pending' => $this->positive(bcsub($committed, $received, 8))];
    }

    /** Pure decimal planning arithmetic; public for regression coverage without a database. */
    public function netQuantities(string $required, string $secured, string $available, string $committed, string $received): array
    {
        $unsecured = $this->positive(bcsub($required, $secured, 8));
        $shortage = $this->positive(bcsub($unsecured, $available, 8));
        $pending = $this->positive(bcsub($committed, $received, 8));
        $net = $this->positive(bcsub($shortage, $pending, 8));
        // Posted purchases have become stock (or have already been picked/received).
        // Deduct only their unreceived remainder here; lifetime procurement totals are
        // provenance, not a second deduction from today's unmet material quantity.
        return ['unsecured_qty' => $unsecured, 'shortage_qty' => $shortage, 'procureable_qty' => $net];
    }

    public function materialIdentity(array $row): string
    {
        // The same BOM template may resolve to several cut dimensions. line_no is the
        // resolver's stable occurrence identity; quantity/spec changes belong to a version.
        // Keep the original typed key order: this hash is a persisted source identity,
        // unlike the semantic hashes used for JSON snapshot comparison.
        return hash('sha256', $this->json(['bom_item_id' => isset($row['bom_item_id']) ? (int) $row['bom_item_id'] : null,
            'line_no' => (int) $row['line_no'], 'component_item_id' => (int) $row['component_item_id']]));
    }

    private function calculate(WorkOrder $wo): array
    {
        $wo->loadMissing('demand.line', 'demand.order');
        $demand = $wo->demand;
        $root = $wo->assembly_root_work_order_id ? WorkOrder::with('demand')->find($wo->assembly_root_work_order_id) : $wo;
        if (! $root || in_array($root->status, ['CANCELLED', 'CLOSED'], true)) $this->fail('demand_inactive', '来源根工单已失效，不能继续物料准备。');
        $source = $root->demand;
        if ($root->source_type === 'sales_order' && (! $source || ! $source->is_active || in_array($source->requirement_status, ['cancelled', 'closed', 'superseded'], true))) $this->fail('demand_inactive', '来源生产需求已失效，不能继续物料准备。');
        $technical = app(WorkOrderTechnicalService::class);
        $pinnedBomId = (int) ($wo->bom_id ?: $demand?->bom_id) ?: null;
        $bom = $pinnedBomId ? Bom::with(['items.componentItem.unit', 'items.unit', 'outputItem.unit'])->find($pinnedBomId) : null;
        if ($bom && ! $technical->confirmationCurrent($wo, $bom)) {
            $this->fail('technical_confirmation_required', 'BOM 已修改或存在定制资料，请重新选择当前 BOM 并确认生产资料。');
        }
        $configuration = $technical->effectiveConfiguration($wo);
        $match = app(BomMatcher::class)->match((int) ($demand?->product_id ?: $demand?->line?->product_id) ?: null,
            (int) ($demand?->sku_id ?: $demand?->line?->sku_id) ?: null, (int) $wo->output_item_id ?: null,
            $configuration, $pinnedBomId);
        if (($match['status'] ?? null) !== 'matched') $this->fail('bom_not_matched', (string) ($match['block_reason'] ?? '未匹配到唯一有效 BOM。'));
        if (! $bom || (int) $bom->id !== (int) $match['bom_id']) {
            $bom = Bom::with(['items.componentItem.unit', 'items.unit', 'outputItem.unit'])->find($match['bom_id']);
        }
        if (! $bom || $bom->audit_status !== 'approved' || ! in_array($bom->status, ['active', 'enabled', 'published'], true)
            || ($bom->effective_date && $bom->effective_date->toDateString() > now()->toDateString())
            || ($bom->expire_date && $bom->expire_date->toDateString() < now()->toDateString())) $this->fail('bom_not_effective', '物料准备必须使用当前有效的已审核 BOM。');
        if (! $technical->confirmationCurrent($wo, $bom)) {
            $this->fail('technical_confirmation_required', 'BOM 已修改或存在定制资料，请重新选择当前 BOM 并确认生产资料。');
        }
        $rows = app(ReleaseGateApplicationService::class)->buildMaterialRows($wo, $bom, $configuration);
        $rows = app(ProductionInventoryContinuationService::class)->materialRows($wo, $rows);
        if ($rows === []) $this->fail('bom_incomplete', '物料准备不能使用空 BOM。');
        foreach ($rows as $row) {
            $item = Item::find($row['component_item_id']);
            if (! $item || $item->managementScope() !== 'factory' || $item->status !== 'enabled' || ! $row['base_unit_id'] || (float) $row['base_required_qty'] <= 0) $this->fail('preparation_material_invalid', '物料准备包含无效物料、单位或需求数量。');
        }
        return ['bom_id' => (int) $bom->id, 'bom_version' => $bom->version,
            'technical_version' => (int) $wo->technical_version, 'technical_snapshot' => $wo->technical_snapshot,
            'routing_snapshot' => $this->routingDefinition($wo->routing_snapshot ?: []), 'output_item_id' => (int) $wo->output_item_id,
            'inventory_continuation_plan' => $wo->inventory_continuation_plan,
            'target_base_qty' => number_format((float) $wo->target_base_qty, 8, '.', ''),
            'rows' => array_map(fn ($row) => $this->materialFacts($row), $rows)];
    }

    private function materialFacts(array $row): array
    {
        $fields = ['line_no', 'bom_id', 'bom_item_id', 'component_item_id', 'configuration_id', 'configuration_snapshot',
            'component_item_code_snapshot', 'component_item_name_snapshot', 'component_spec_snapshot', 'cut_length_mm_snapshot',
            'cutting_requirement_snapshot', 'per_output_piece_qty', 'required_piece_qty', 'unit_id', 'unit_name_snapshot',
            'per_output_qty', 'loss_rate', 'fixed_qty', 'required_qty', 'base_unit_id', 'base_unit_name_snapshot', 'base_required_qty',
            'requirement_kind', 'remaining_supply_snapshot'];
        $facts = [];
        foreach ($fields as $key) {
            $value = $row[$key] ?? ($key === 'requirement_kind' ? 'standard' : null);
            if (in_array($key, ['configuration_snapshot', 'cutting_requirement_snapshot', 'remaining_supply_snapshot'], true) && is_string($value)) $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if ($value !== null && (str_ends_with($key, '_qty') || in_array($key, ['loss_rate', 'cut_length_mm_snapshot'], true))) $value = is_float($value) ? number_format($value, 8, '.', '') : bcadd((string) $value, '0', 8);
            if ($value !== null && (str_ends_with($key, '_id') || $key === 'line_no')) $value = (int) $value;
            $facts[$key] = $value;
        }
        return $facts;
    }

    private function editable(WorkOrder $wo): void
    {
        if ($wo->released_at || ! in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true)) $this->fail('invalid_state', '只有未发布的有效工单可进行物料准备或申购。');
    }

    private function availableStock(WorkOrderMaterialRequirement $requirement): string
    {
        if (app(ProductionCuttingAllocationService::class)->unitKind($requirement)) {
            $claimed = ['physical_ids' => [], 'balance_qty' => []];
            return $this->cuttingAvailableCoverage($requirement, $claimed, (string) $requirement->required_qty);
        }
        $stock = app(ProductionPickingStockService::class);
        $total = '0.00000000';
        foreach (InventoryBalance::with(['item', 'warehouse', 'location'])->where('item_id', $requirement->component_item_id)
            ->where('unit_id', $requirement->base_unit_id)->orderBy('id')->get() as $balance) {
            if ($stock->eligible($balance, $requirement)) $total = bcadd($total, $stock->availableDecimal($balance, null, [], $requirement), 8);
        }
        return $total;
    }

    private function cuttingAvailableCoverage(WorkOrderMaterialRequirement $requirement, array &$claimed, string $limit): string
    {
        $pieces = FrozenCuttingStockPolicy::positiveDecimal($requirement->required_piece_qty);
        $totalLength = FrozenCuttingStockPolicy::isTotalLength($requirement->cutting_requirement_snapshot ?? []);
        $netLength = $totalLength ? app(ProductionCuttingAllocationService::class)->netLength($requirement) : null;
        $required = FrozenCuttingStockPolicy::positiveDecimal($requirement->required_qty);
        if ((! $totalLength && ! $pieces) || ! $required || bccomp($limit, '0', 8) <= 0) return '0.00000000';
        $stock = app(ProductionPickingStockService::class); $total = '0.00000000';
        $reservedPhysicalIds = app(ProductionCuttingAllocationService::class)->reservedPhysicalIds();
        foreach (InventoryBalance::with(['item', 'warehouse', 'location'])->where('item_id', $requirement->component_item_id)
            ->where('unit_id', $requirement->base_unit_id)->orderBy('id')->get() as $balance) {
            if (! $stock->eligible($balance, $requirement)) continue;
            $available = $this->positive(bcsub($stock->availableDecimal($balance, null, [], $requirement, true), (string) ($claimed['balance_qty'][$balance->id] ?? 0), 8));
            if (bccomp($available, '0', 8) <= 0) continue;
            $geometry = $stock->geometry($requirement, $balance->item?->cuttingMode() ?? 'none');
            $physical = $balance->item?->materialManagementMode() === 'physical';
            $stockKind = app(ProductionCuttingAllocationService::class)->stockUnitKind($requirement);
            if ($physical) {
                $sources = $stock->physicalQuery($balance, $requirement)->whereNotIn('physical.id', [...$reservedPhysicalIds, ...$claimed['physical_ids']])
                    ->orderBy('physical.id')->select('physical.id', 'physical.shape', 'physical.dimensions')->cursor();
            } else {
                $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
                $dimensions = $stock->lengthSource($balance, $requirement, $lot);
                $perSource = CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions);
                $count = (int) bcdiv($available, $perSource, 0);
                $sources = (function () use ($count, $dimensions) { for ($i = 0; $i < $count; $i++) yield (object) ['dimensions' => $dimensions]; })();
            }
            foreach ($sources as $source) {
                $dimensions = is_array($source->dimensions) ? $source->dimensions : json_decode($source->dimensions ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                if ($physical) $dimensions = ['shape' => $source->shape] + $dimensions;
                $capacity = $totalLength ? FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null) : CuttingStockAllocationPolicy::capacity($geometry, $dimensions);
                if (! $capacity || bccomp((string) $capacity, '0', 8) <= 0) continue;
                $sourceQuantity = CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions);
                if (bccomp($sourceQuantity, $available, 8) > 0) continue;
                $coverage = bcdiv(bcmul($required, (string) $capacity, 16), $totalLength ? $netLength : $pieces, 8);
                if (bccomp($coverage, '0', 8) <= 0) continue;
                if ($physical) $claimed['physical_ids'][] = (int) $source->id;
                $claimed['balance_qty'][$balance->id] = bcadd((string) ($claimed['balance_qty'][$balance->id] ?? 0), $sourceQuantity, 8);
                $available = bcsub($available, $sourceQuantity, 8);
                $total = bcadd($total, $coverage, 8);
                if (bccomp($total, $limit, 8) >= 0) return bcadd($limit, '0', 8);
            }
        }
        return $total;
    }

    private function securedForPreparation(WorkOrderPreparationMaterial $row): string
    {
        if (! $row->bom_item_id || ! Schema::hasTable('erp_assembly_inventory_reservations')) return '0.00000000';
        return (string) DB::table('erp_assembly_inventory_reservations as r')->join('erp_assembly_component_demands as d', 'd.id', '=', 'r.component_demand_id')
            ->where('r.work_order_id', $row->work_order_id)->where('d.bom_item_id', $row->bom_item_id)->where('r.status', 'ACTIVE')
            ->selectRaw('COALESCE(SUM(r.reserved_base_qty-r.consumed_base_qty),0) as qty')->value('qty');
    }

    private function sameStockPool(array $left, array $right): bool
    {
        // Match the configuration and unit boundaries used by availableStock/eligible.
        foreach (['component_item_id', 'base_unit_id', 'configuration_id'] as $key) {
            if ((int) ($left[$key] ?? 0) !== (int) ($right[$key] ?? 0)) return false;
        }
        return true;
    }

    private function hasPreparation(WorkOrder $wo): bool
    {
        return Schema::hasColumn('erp_work_order_material_preparations', 'work_order_id')
            && DB::table('erp_work_order_material_preparations')->where('work_order_id', $wo->id)->exists();
    }

    private function routingDefinition(array $snapshot): array
    {
        // Publication derives the execution plan and cutting-component facts after Gate.
        // They are not preparation inputs; retain every route, supply and output definition.
        unset($snapshot['output_plan']);
        foreach ($snapshot['operations'] ?? [] as $index => $node) {
            unset($node['output_kind'], $node['component_requirements']);
            $snapshot['operations'][$index] = $node;
        }
        return $snapshot;
    }

    private function inputMatches(string|array $stored, array $current): bool
    {
        $previous = is_string($stored) ? json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : $stored;
        // Compare semantic facts, not a legacy serialization hash. Existing preparations
        // with live procurement remain usable after MySQL reorders JSON object keys.
        foreach ([&$previous, &$current] as &$input) {
            if (is_array($input['routing_snapshot'] ?? null)) $input['routing_snapshot'] = $this->routingDefinition($input['routing_snapshot']);
        }
        unset($input);
        return hash_equals($this->hash($previous), $this->hash($current));
    }

    private function canonical(array $value): array
    {
        foreach ($value as $key => $child) if (is_array($child)) $value[$key] = $this->canonical($child);
        // MySQL JSON may reorder object keys. Preserve actual list order while
        // making persisted/reloaded material and technical facts hash identically.
        if (! array_is_list($value)) ksort($value);
        return $value;
    }
    private function transaction(): void { if (DB::transactionLevel() < 1) $this->fail('transaction_required', '物料准备必须在锁定工单的事务内执行。'); }
    private function positive(string $qty): string { return bccomp($qty, '0', 8) > 0 ? $qty : '0.00000000'; }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    private function hash(array $value): string { return hash('sha256', $this->json($this->canonical($value))); }
    private function unavailable(string $code, string $message): array { return ['status' => $code, 'ready' => false, 'issues' => [['code' => $code, 'message' => $message]], 'version' => null, 'rows' => []]; }
    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }
}
