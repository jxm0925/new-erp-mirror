const ui = require('./warehouse-page');

const statuses = { WAIT_PICK: '待拣货', PICKING: '拣货中', PICKED: '已实拣', WAIT_DELIVERY: '待配送', DELIVERING: '配送中', DELIVERED: '已送达', PARTIALLY_RECEIVED: '部分签收', RECEIVED: '已签收', CANCELLED: '已取消' };
const copy = value => JSON.parse(JSON.stringify(value));
const keyOf = (row, mode) => mode === 'target'
  ? [row.work_order_id, row.production_target_type, row.production_target_id, row.target_routing_operation_id].join(':') : String(row.id);
const validQty = value => /^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/.test(String(value));
const sumQty = values => ui.quantity(values.reduce((sum, value) => sum + Number(value || 0), 0).toFixed(8));
function dimensions(value) { return ['length_mm', 'width_mm', 'thickness_mm'].map(key => value && value[key]).filter(v => v !== undefined && v !== null && v !== '').map(ui.quantity).join(' × '); }
function optionView(row, mode) {
  const result = Object.assign({}, row, { key: keyOf(row, mode) });
  if (mode === 'target') Object.assign(result, { title: row.work_order_no, subtitle: [row.target_operation_name, row.production_unit_no, row.output_item_name].filter(Boolean).join(' / ') });
  if (mode === 'people') Object.assign(result, { title: row.nickname || row.username, subtitle: row.username });
  if (mode === 'warehouse') Object.assign(result, { title: row.warehouse_name, subtitle: row.warehouse_code });
  if (mode === 'stock') Object.assign(result, { title: row.batch_no || '无批次', subtitle: row.location && (row.location.location_code || row.location.location_name), availableText: ui.quantity(row.picking_available_qty), unitName: row.unit && row.unit.unit_name });
  if (mode === 'physical') Object.assign(result, { title: row.physical_no, subtitle: dimensions(row.dimensions), statusText: row.status === 'AVAILABLE' ? '可用' : row.status });
  if (mode === 'serial') Object.assign(result, { title: row.serial_no, statusText: ({ available: '可用', production_in_transit: '待配送', production_rejected: '已拒收' })[row.serial_status] || row.serial_status });
  return result;
}
function sourceView(row) { return Object.assign(optionView(row, 'stock'), { selected_qty: String(row.selected_qty === undefined ? '' : row.selected_qty) }); }
function lineView(line, draft) {
  const item = line.component_item || {}; const balance = line.inventory_balance || {};
  const physical = item.material_management_mode === 'physical';
  const serialMode = item.serial_tracking_mode || item.serial_control_type || (item.is_serial_managed ? 'required' : 'none');
  return Object.assign({}, line, { itemCode: item.item_code || '', itemName: item.item_name || '', spec: item.spec || '',
    unitName: line.requirement && line.requirement.base_unit_name_snapshot || line.unit_name_snapshot || '',
    locationName: balance.location && (balance.location.location_code || balance.location.location_name) || '', batchNo: balance.batch_no || '',
    plannedText: ui.quantity(line.planned_pick_qty), actualText: ui.quantity(line.actual_pick_qty),
    physical, serial: !physical && serialMode !== 'none', draftQty: draft ? draft.actual_pick_qty : '',
    selectedCount: draft ? (physical ? draft.physicalRows : draft.serialRows).length : 0,
  });
}
function createPayload(target, warehouseId, drafts) {
  if (!target || !Number(target.work_order_id)) throw new Error('请选择来源工单和目标工序');
  if (!Number(warehouseId)) throw new Error('请选择仓库');
  const lines = []; const sourceTotals = {};
  Object.values(drafts).forEach(draft => {
    const sources = draft.sources || []; const total = sumQty(sources.map(row => row.selected_qty));
    if (Number(total) > Number(draft.demand.remaining_to_prepare) + 0.00000001) throw new Error(`${draft.demand.item_name}本次配料超过待配数量`);
    sources.forEach(row => {
      if (!validQty(row.selected_qty) || Number(row.selected_qty) <= 0) throw new Error('每条库存来源都必须填写大于0的本次数量');
      if (Number(row.warehouse_id) !== Number(warehouseId)) throw new Error('库存来源与所选仓库不一致，请重新选择');
      if (Number(row.selected_qty) > Number(row.picking_available_qty) + 0.00000001) throw new Error('本次数量超过来源可用数量，请重新核对');
      sourceTotals[row.id] = (sourceTotals[row.id] || 0) + Number(row.selected_qty);
      if (sourceTotals[row.id] > Number(row.picking_available_qty) + 0.00000001) throw new Error('同一库存来源的累计配料超过可用数量');
      lines.push({ target_material_requirement_id: Number(draft.demand.id), inventory_balance_id: Number(row.id), planned_pick_qty: row.selected_qty });
    });
  });
  if (!lines.length) throw new Error('请为物料选择库存来源和本次数量');
  if (lines.length > 100) throw new Error('每次配料最多100条库存来源，请分批办理');
  return { expected_version: Number(target.work_order_version), warehouse_id: Number(warehouseId), lines };
}
function confirmPayload(task, drafts, total) {
  const rows = Object.values(drafts);
  // Missing pages must never become implicit zero picks: every source is a conscious input.
  if (rows.length !== Number(total)) throw new Error('请逐页核对全部实拣明细，未拣出的物料填写0');
  const physicalIds = new Set(); const serialIds = new Set(); let positive = false;
  const lines = rows.map(draft => {
    const qty = draft.actual_pick_qty; const view = lineView(draft.line, draft);
    if (!validQty(qty)) throw new Error('请填写每条来源的实拣数量，未拣出的填写0');
    if (Number(qty) > Number(draft.line.planned_pick_qty) + 0.00000001) throw new Error('实拣数量不能超过应拣数量');
    if (Number(qty) > 0) positive = true;
    const selected = view.physical ? draft.physicalRows : view.serial ? draft.serialRows : [];
    if ((view.physical || view.serial) && (!Number.isInteger(Number(qty)) || selected.length !== Number(qty))) throw new Error(view.physical ? '所选实物数必须等于实拣数量' : '所选序列号数必须等于实拣数量');
    const ids = selected.map(row => Number(row.id)); const seen = view.physical ? physicalIds : serialIds;
    ids.forEach(id => { if (!id || seen.has(id)) throw new Error('实物或序列号不能在多个来源中重复选择'); seen.add(id); });
    const result = { picking_task_line_id: Number(draft.line.id), actual_pick_qty: qty };
    if (view.physical) result.physical_material_ids = ids;
    if (view.serial) result.serial_ids = ids;
    return result;
  });
  if (!positive) throw new Error('至少填写一条大于0的实拣数量');
  if (lines.length > 100) throw new Error('该配料单超过移动端单次办理上限，请联系管理员处理');
  return { expected_version: Number(task.business_version), lines };
}
module.exports = { statuses, copy, keyOf, validQty, sumQty, optionView, sourceView, lineView, createPayload, confirmPayload };
