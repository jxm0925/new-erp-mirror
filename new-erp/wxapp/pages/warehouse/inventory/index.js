const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const document = require('../../../utils/warehouse-document');
const production = require('../../../services/production');
const STATUS = { available: '可用', reserved: '已占用', locked: '已占用', defective: '不良', pending: '待处理', pending_posting: '待入库', issued: '已领用', shipped: '已发货', outbound: '已出库', shipment_reserved: '发货占用', staged: '待发区', quality_hold: '质量冻结', repair: '返修中', production_consumed: '生产已领用', packing_consumed: '打包已耗用', adjusted_out: '已调整出库', returned: '已退回', scrapped: '已报废', sales_return_pending: '退货待处理', sales_return_scrapped: '退货已报废', sales_return_rejected: '退货已拒收', AVAILABLE: '可用', LOCKED: '已占用', CONSUMED: '已耗用', SCRAPPED: '已报废' };
function decorate(row) {
  const item = row.item || {};
  return Object.assign({}, row, { key: row.id ? 'id:' + row.id : 'batch:' + String(row.batch_no || ''), itemName: row.item_name || item.item_name || '', itemCode: row.item_code || item.item_code || '',
    spec: row.spec || item.spec || '', unit: row.unit_name || (item.unit || {}).unit_name || '', onHand: page.quantity(row.quantity_on_hand),
    available: page.quantity(row.quantity_available), locked: page.quantity(row.quantity_locked), amount: page.money(row.inventory_value),
    statusText: STATUS[row.serial_status || row.status] || row.serial_status || row.status || '', dimensionText: document.dimensions(row.dimensions),
    areaText: page.quantity(row.area_m2 || (row.dimensions || {}).area_m2), costText: page.money(row.total_cost),
    lengthText: page.quantity((row.measure || {}).length_m), areaTotalText: page.quantity((row.measure || {}).area_m2), perLengthText: page.quantity((row.measure || {}).lot_length_mm),
  });
}
Page({
  data: { loading: true, error: '', keyword: '', rows: [], page: 1, lastPage: 1, total: 0, locator: {}, locatorOpen: false,
    selected: null, tab: 'batches', batch: '', balanceId: 0, balanceTitle: '', detailRows: [], detailPage: 1, detailLast: 1, detailTotal: 0,
    detailKeyword: '', canAmount: false, scanInfo: null, scanPhysicalId: 0, scanSerialId: 0 },
  onLoad(options) { this.initialCode = options.code || ''; this.setData({ canAmount: page.permissions()('inventory.amount.view') }); },
  onShow() {
    if (page.resumingScan(this)) return;
    if (this.initialCode) { const code = this.initialCode; this.initialCode = ''; this.resolveCode(code); }
    else this.data.selected ? this.loadDetail() : this.load();
  },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { (this.data.selected ? this.loadDetail() : this.load()).finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    return warehouse.balances({ view: 'item', keyword: this.data.keyword.trim(), warehouse_id: this.data.locator.warehouse_id, location_id: this.data.locator.location_id, page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return; const result = page.rows(response);
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.load(); }
      this.setData({ rows: result.rows.map(decorate), page: result.page, total: result.total, lastPage: result.lastPage, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: page.errorText(error) }); });
  },
  loadDetail() {
    const selected = this.data.selected; if (!selected) return this.load();
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    const serialSearch = this.data.tab === 'serials' && this.data.balanceId;
    const query = { page: this.data.detailPage, per_page: 10, keyword: this.data.tab === 'physicals' || serialSearch ? this.data.detailKeyword.trim() : undefined, warehouse_id: this.data.locator.warehouse_id,
      location_id: this.data.locator.location_id, batch_no: this.data.batch || (!serialSearch && this.data.tab !== 'physicals' ? this.data.detailKeyword.trim() : undefined), exact_batch: this.data.batch ? 1 : undefined };
    let request;
    if (this.data.tab === 'physicals') request = warehouse.itemPhysicals(selected.item_id, Object.assign({ pagination: 1, physical_id: this.data.scanPhysicalId || undefined }, query));
    else if (this.data.tab === 'serials' && this.data.balanceId) request = warehouse.balanceSerials(this.data.balanceId, Object.assign({ 'ids[0]': this.data.scanSerialId || undefined }, query));
    else if (this.data.tab === 'serials' || this.data.batch) request = warehouse.balances(Object.assign({ item_id: selected.item_id }, query));
    else request = warehouse.itemBatches(selected.item_id, query);
    return request.then(response => {
      if (sequence !== this.sequence) return; const result = page.rows(response);
      if (result.page > result.lastPage) { this.setData({ detailPage: result.lastPage }); return this.loadDetail(); }
      this.setData({ detailRows: result.rows.map(decorate), detailPage: result.page, detailTotal: result.total, detailLast: result.lastPage, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, detailRows: [], error: page.errorText(error) }); });
  },
  input(event) { this.setData({ [event.currentTarget.dataset.field || 'keyword']: event.detail.value }); },
  search() { this.setData({ page: 1, detailPage: 1, scanPhysicalId: 0, scanSerialId: 0 }); this.data.selected ? this.loadDetail() : this.load(); },
  reset() { this.setData({ keyword: '', locator: {}, page: 1, scanInfo: null }); this.load(); },
  turn(event) {
    const detail = !!this.data.selected; const field = detail ? 'detailPage' : 'page'; const last = detail ? this.data.detailLast : this.data.lastPage;
    const target = this.data[field] + Number(event.currentTarget.dataset.delta); if (!this.data.loading && target > 0 && target <= last) { this.setData({ [field]: target }); detail ? this.loadDetail() : this.load(); }
  },
  open(event) {
    const row = this.data.rows.find(row => Number(row.item_id) === Number(event.currentTarget.dataset.id)); if (!row) return;
    this.setData({ selected: row, tab: event.currentTarget.dataset.tab || 'batches', batch: '', balanceId: 0, detailPage: 1, detailKeyword: '', scanInfo: null });
    wx.setNavigationBarTitle({ title: '库存详情' }); this.loadDetail();
  },
  closeDetail() { this.setData({ selected: null, tab: 'batches', batch: '', balanceId: 0, detailKeyword: '', scanInfo: null, scanPhysicalId: 0, scanSerialId: 0 }); wx.setNavigationBarTitle({ title: '库存查询' }); this.load(); },
  switchTab(event) { this.setData({ tab: event.currentTarget.dataset.tab, batch: '', balanceId: 0, detailKeyword: '', detailPage: 1, scanPhysicalId: 0, scanSerialId: 0 }); this.loadDetail(); },
  openBatch(event) { this.setData({ batch: event.currentTarget.dataset.batch, detailPage: 1 }); this.loadDetail(); },
  closeBatch() { this.setData({ batch: '', balanceId: 0, detailPage: 1 }); this.loadDetail(); },
  openSerials(event) { this.setData({ tab: 'serials', balanceId: Number(event.currentTarget.dataset.id), balanceTitle: event.currentTarget.dataset.title, detailPage: 1, detailKeyword: '' }); this.loadDetail(); },
  chooseBalance() { this.setData({ balanceId: 0, detailPage: 1, detailKeyword: '', scanPhysicalId: 0, scanSerialId: 0 }); this.loadDetail(); },
  openLocator() { this.setData({ locatorOpen: true }); }, closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) { this.setData({ locator: event.detail, locatorOpen: false, page: 1, detailPage: 1, balanceId: 0, scanPhysicalId: 0, scanSerialId: 0 }); this.data.selected ? this.loadDetail() : this.load(); },
  async resolveCode(code) {
    this.setData({ loading: true, error: '' });
    try { const response = await warehouse.resolveScan(code); await this.handleScan(response.data || response); }
    catch (error) { this.setData({ loading: false, error: page.errorText(error) }); }
  },
  scanned(event) { this.handleScan(event.detail.result).catch(error => this.setData({ loading: false, error: page.errorText(error) })); },
  async handleScan(result) {
    result = Object.assign({}, result, { statusText: result.status_label || STATUS[result.status] || '' });
    if (result.type === 'location') { this.setData({ locator: result, page: 1, detailPage: 1, balanceId: 0, scanPhysicalId: 0, scanSerialId: 0 }); return this.data.selected ? this.loadDetail() : this.load(); }
    if (result.type === 'document') {
      if (['material_delivery', 'material_return', 'stocktake', 'purchase_receipt', 'purchase_return', 'sales_return', 'sales_return_receipt', 'sales_shipment'].includes(result.kind)) return page.openDocument({ kind: result.kind === 'sales_return_receipt' ? 'sales_return' : result.kind, id: result.id, stage: result.kind === 'sales_return' ? 'receive' : 'post' });
      this.setData({ loading: false, scanInfo: result }); return;
    }
    if (result.type === 'serial' && page.permissions()('production.serial.view')) {
      try {
        const response = await production.serialLookup({ serial_no: result.serial_no }); const trace = response.data || response;
        if (trace && trace.serial_no) result = Object.assign({}, result, { status_label: trace.status_label, statusText: trace.status_label || result.statusText, current_operation_name: trace.current_operation_name,
          work_order_no: (trace.work_order || {}).work_order_no, product_name: (trace.work_order || {}).product_name || (trace.work_order || {}).item_name });
      } catch (_) { /* 库存编号仍可查询；生产追溯由自己的权限和数据范围决定。 */ }
    }
    if (!result.item_id) { this.setData({ loading: false, scanInfo: result }); return; }
    const response = await warehouse.balances({ view: 'item', item_id: result.item_id, page: 1, per_page: 10 });
    const row = page.rows(response).rows.find(row => Number(row.item_id) === Number(result.item_id));
    const selected = decorate(row || { item_id: result.item_id, item: { item_code: result.item_code, item_name: result.item_name, spec: result.spec, unit: { unit_name: result.unit_name } } });
    const position = ['serial', 'physical'].includes(result.type) ? { locator: result.location_id && result.warehouse_id ? { warehouse_id: result.warehouse_id, warehouse_name: result.warehouse_name, location_id: result.location_id, location_code: result.location_code, location_name: result.location_name } : {} } : {};
    this.setData(Object.assign({ selected, tab: result.type === 'physical' ? 'physicals' : result.type === 'serial' ? 'serials' : 'batches', batch: '',
      balanceId: result.type === 'serial' ? Number(result.inventory_balance_id || 0) : 0, balanceTitle: [result.warehouse_name, result.location_code, result.batch_no].filter(Boolean).join(' · '),
      scanPhysicalId: result.type === 'physical' ? Number(result.id || 0) : 0, scanSerialId: result.type === 'serial' ? Number(result.inventory_serial_id || 0) : 0,
      detailPage: 1, detailKeyword: result.type === 'serial' ? result.serial_no : result.type === 'physical' ? result.physical_no || result.code : '', scanInfo: ['serial', 'physical'].includes(result.type) ? result : null }, position));
    wx.setNavigationBarTitle({ title: '库存详情' }); return this.loadDetail();
  },
});
