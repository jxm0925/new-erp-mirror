const { quantity } = require('./warehouse-page');
const labels = { pending: '待入库', posted: '已入库', not_required: '无须库存入库', draft: '草稿', confirmed: '已确认', pending_outbound: '已确认 · 待出库',
  outbound_posted: '已出库待发运', shipped: '已发运', completed: '已完成', pending_receipt: '退货已确认', partial_received: '部分收货', received: '已收货',
  SUBMITTED: '待接收', WAIT_QUALITY: '待检验', RECEIVED: '已接收', COMPLETED: '已完成', WAIT_WAREHOUSE: '待入库', PART_WAREHOUSED: '部分入库', WAREHOUSED: '已入库', CREATED: '待办理' };
const titles = { purchase_receipt: '采购入库', production_return: '生产退料接收', output: '成品入库', cutting_product: '下料产品入库', sales_return: '销售退货入库', sales_shipment: '销售出库', purchase_return: '采购退货出库' };
const actionLabels = { 'purchase.post': '确认入库', 'production_return.receive': '确认接收', 'output.warehouse': '确认入库', 'cutting.warehouse': '确认入库',
  'sales_return.receive': '确认收货', 'sales_return.post': '确认入库', 'sales_shipment.post': '确认出库', 'sales_shipment.dispatch': '确认发运', 'purchase_return.post': '确认退货出库' };
const fields = entries => entries.filter(entry => entry[1] !== null && entry[1] !== undefined && entry[1] !== '').map(([label, value]) => ({ label, value: String(value) }));
function dimensions(d) { return ['length_mm', 'width_mm', 'thickness_mm'].map(k => d && d[k]).filter(v => v !== undefined && v !== null && v !== '').map(quantity).join(' × '); }
function line(row) {
  const item = row.item || (row.order_line && row.order_line.item) || {};
  const balance = (row.reservation && row.reservation.balance) || {};
  const costs = row.cost_allocations || (row.sales_return_item && row.sales_return_item.cost_allocations) || [];
  const sourceBatches = row.source_batches || Array.from(new Set(costs.map(c => c.shipment_line && c.shipment_line.batch_no).filter(Boolean)));
  const serials = row.serial_snapshot || {};
  return Object.assign({}, row, { itemName: row.item_name || item.item_name || '', itemCode: row.item_code || item.item_code || '', spec: row.spec || item.spec || '—',
    unit: row.unit_name || row.base_unit_name_snapshot || (row.base_unit && row.base_unit.unit_name) || (item.unit && item.unit.unit_name) || '',
    warehouseName: row.warehouse_name || (row.warehouse && row.warehouse.warehouse_name) || (balance.warehouse && balance.warehouse.warehouse_name) || '',
    locationName: row.location_name || (row.location && row.location.location_name) || (balance.location && balance.location.location_name) || '',
    physical: item.material_management_mode === 'physical', serialTracked: typeof row.serial_tracked === 'boolean' ? row.serial_tracked : item.serial_tracking_mode === 'required' || item.is_serial_managed === true,
    serialNos: (row.serial_nos || (Array.isArray(serials) ? serials : serials.serial_nos || [])).map(s => typeof s === 'object' ? s.serial_no : s).filter(Boolean).join('、'),
    sourceShipments: (row.source_shipments || Array.from(new Set(costs.map(c => c.shipment && c.shipment.shipment_no).filter(Boolean)))).join('、'),
    sourceBatch: sourceBatches.length === 1 ? sourceBatches[0] : '',
    allocations: (row.allocations || []).map(a => Object.assign({}, a, { physical_entries: (a.physical_entries || []).map(p => Object.assign({}, p, { dimensionText: dimensions(p.dimensions) })) })),
    physicals: (row.physicals || []).map(p => Object.assign({}, p, { dimensionText: dimensions(p.dimensions) })) });
}
function header(kind, h, stage) {
  const order = h.order || (h.sales_return && h.sales_return.order) || {};
  const ret = h.sales_return || h;
  const state = kind === 'sales_return' ? (stage === 'receive' ? h.return_status : h.stock_post_status)
    : kind === 'sales_shipment' ? h.shipment_status : kind === 'purchase_receipt' ? h.stock_post_status : kind === 'purchase_return' ? h.return_status : h.status;
  const status = labels[state] || state || '—';
  const map = {
    purchase_receipt: [['收货单号', h.receipt_no], ['单据状态', status], ['供应商', h.supplier && h.supplier.supplier_name], ['单据日期', String(h.receipt_date || '').slice(0, 10)], ['备注', h.remark]],
    production_return: [['退料单号', h.return_no], ['单据状态', status], ['来源工单', h.work_order_no], ['退料类型', h.return_type === 'quality_return' ? '质量退料' : '普通退料'], ['退料原因', h.reason]],
    output: [['工单编号', h.work_order_no], ['工单状态', h.completion_approved ? '完工审核已通过' : status]],
    cutting_product: [['下料单号', h.cutting_order_no], ['分流记录', h.id], ['分流状态', status]],
    sales_return: [['退货单号', ret.return_no], ['收货单号', h.receipt_no], ['单据状态', status], ['客户名称', (ret.customer && ret.customer.customer_name) || order.customer_name]],
    sales_shipment: [['出库单号', h.shipment_no], ['单据状态', status], ['销售订单', order.sales_order_no], ['客户名称', order.customer_name], ['备注', h.remark]],
    purchase_return: [['退货出库单号', h.return_no], ['单据状态', status], ['供应商', h.supplier && h.supplier.supplier_name], ['原入库单号', h.receipt && h.receipt.receipt_no], ['退货原因', h.return_reason], ['备注', h.remark]],
  };
  return fields(map[kind] || []);
}
const returnDispositions = ['restock', 'pending', 'scrap', 'rejected'];
function returnSerialSelection(rowsByDisposition, maximum) {
  const ids = new Set(); const batches = new Set(); const serialRows = {}; const serial_dispositions = {};
  const quantities = {}; const sources = [];
  returnDispositions.forEach(disposition => {
    const rows = rowsByDisposition && rowsByDisposition[disposition] || [];
    if (!Array.isArray(rows)) throw new Error('序列号处理去向无效');
    serialRows[disposition] = rows.map(row => {
      const id = Number(row.id);
      if (!Number.isInteger(id) || id <= 0 || ids.has(id)) throw new Error('同一序列号不能重复选择或用于多个处理去向');
      ids.add(id); batches.add(String(row.batch_no || '')); sources.push(row);
      return Object.assign({}, row);
    });
    serial_dispositions[disposition] = serialRows[disposition].map(row => Number(row.id));
    quantities[`${disposition}_base_qty`] = String(rows.length);
  });
  if (batches.size > 1) throw new Error('同一退货行每次只接收一个原批次，不同批次请分次收货');
  if (maximum !== undefined && maximum !== null && Number.isFinite(Number(maximum)) && ids.size > Number(maximum)) throw new Error('所选序列号数量超过待收数量，请重新核对');
  return Object.assign(quantities, { received_base_qty: String(ids.size), batch_no: sources.length ? String(sources[0].batch_no || '') : '', serialRows, serial_dispositions });
}
function returnReceiptItems(drafts) {
  const used = new Set();
  return Object.values(drafts).filter(draft => Number(draft.received_base_qty) > 0).map(draft => {
    // Only business fields leave the page. Candidate rows and review state are local UI data.
    const row = { sales_return_item_id: draft.sales_return_item_id, received_base_qty: draft.received_base_qty,
      restock_base_qty: draft.restock_base_qty, pending_base_qty: draft.pending_base_qty, scrap_base_qty: draft.scrap_base_qty,
      rejected_base_qty: draft.rejected_base_qty, batch_no: draft.batch_no, inspection_remark: draft.inspection_remark,
      warehouse_id: draft.locator && draft.locator.warehouse_id, location_id: draft.locator && draft.locator.location_id };
    if (draft.serialTracked) {
      if (!draft.serialReviewed || draft.serialReviewFingerprint !== returnSerialFingerprint(draft)) throw new Error('请先核对全部退货序列号');
      const selection = returnSerialSelection(draft.serialRows, draft.remainingReceivable);
      if (Number(row.received_base_qty) !== Number(selection.received_base_qty) || String(row.batch_no || '') !== selection.batch_no
          || returnDispositions.some(disposition => Number(row[`${disposition}_base_qty`]) !== Number(selection[`${disposition}_base_qty`]))) throw new Error('本次实收及处理数量必须与核对序列号一致');
      Object.values(selection.serial_dispositions).forEach(ids => ids.forEach(id => { if (used.has(id)) throw new Error('同一序列号不能用于多个退货行'); used.add(id); }));
      row.serial_dispositions = selection.serial_dispositions;
    }
    return row;
  });
}
function returnSerialFingerprint(draft) {
  return JSON.stringify([draft.sales_return_item_id, draft.received_base_qty, draft.batch_no,
    returnDispositions.map(disposition => [disposition, draft[`${disposition}_base_qty`], (draft.serialRows && draft.serialRows[disposition] || []).map(row => [Number(row.id), String(row.batch_no || ''), Number(row.outbound_transaction_item_id || 0)]).sort((left, right) => left[0] - right[0])])]);
}
module.exports = { labels, titles, actionLabels, fields, dimensions, line, header, returnDispositions, returnSerialSelection, returnReceiptItems, returnSerialFingerprint };
