const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const { sumAmounts } = require('../../../utils/warehouse-decimal');
const statuses = [{ value: 'PENDING', name: '待入库' }, { value: 'POSTED', name: '已入库' }, { value: 'UNAVAILABLE', name: '已领用 / 已变更' }];
function dimensions(value) { return ['length_mm', 'width_mm', 'thickness_mm'].map(key => value && value[key]).filter(value => value !== null && value !== undefined && value !== '').map(page.quantity).join(' × '); }
function rowView(row) { return Object.assign({}, row, { dimensionText: dimensions(row.dimensions), quantityText: page.quantity(row.quantity) }); }
Page({
  data: { orderId: 0, mode: 'list', orderNo: '', workOrderNos: '', statuses, statusIndex: 0, page: 1, lastPage: 1, total: 0, rows: [], selectedRows: [], selectedCount: 0,
    selectedAmount: '0.0000', allPageSelected: false, loading: true, busy: false, error: '', locatorOpen: false, locator: {}, locatorError: '', remark: '', canPost: false, pending: false, receipt: null, resultDialog: false, resultMessage: '' },
  onLoad(options) {
    this.selected = {}; this.sourcePage = 1;
    const completed = options.completed === '1';
    this.setData({ orderId: Number(completed ? options.parent : options.id), canPost: page.permissions()('production.cutting.warehouse') });
    if (completed) this.loadReceipt(Number(options.id)); else this.load();
  },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { (this.data.mode === 'receipt' ? this.loadReceipt(this.receiptId) : this.load()).finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '', pending: !!warehouse.pending('remnant.warehouse', this.data.orderId) });
    return warehouse.remnants(this.data.orderId, { status: statuses[this.data.statusIndex].value, page: this.data.page, per_page: 10, source_page: this.sourcePage }).then(response => {
      if (sequence !== this.sequence) return;
      const result = page.rows(response); const sources = response.source_work_orders || { data: [], current_page: 1, last_page: 1 };
      let changed = false;
      result.rows.forEach(row => {
        const selected = this.selected[row.id];
        if (selected && (!row.receivable || Number(selected.business_version) !== Number(row.business_version)
          || Number(selected.holding_version) !== Number(row.holding_version)
          || Number(selected.physical_version || 0) !== Number(row.physical_version || 0))) { delete this.selected[row.id]; changed = true; }
      });
      this.setData({ rows: result.rows.map(rowView), page: result.page, lastPage: result.lastPage, total: result.total, loading: false, orderNo: response.order_no,
        workOrderNos: sources.data.map(order => order.work_order_no).join('、'), sourcePage: sources.current_page, sourceLast: sources.last_page,
        error: changed ? '部分余料已变化，请重新核对选择' : '' });
      this.syncSelection();
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: page.errorText(error) }); });
  },
  syncSelection() {
    const rows = this.data.rows.map(row => Object.assign({}, row, { checked: !!this.selected[row.id] }));
    const selectedRows = Object.values(this.selected); const available = rows.filter(row => row.receivable);
    this.setData({ rows, selectedRows, selectedCount: selectedRows.length, selectedAmount: sumAmounts(selectedRows.map(row => row.total_cost)),
      allPageSelected: available.length > 0 && available.every(row => row.checked), sourceBatches: Array.from(new Set(selectedRows.map(row => row.source_batch_no))).join('、') });
  },
  turnSource(event) { const next = this.sourcePage + Number(event.currentTarget.dataset.delta); if (!this.data.loading && next >= 1 && next <= this.data.sourceLast) { this.sourcePage = next; return this.load(); } },
  toggleRow(event) {
    if (this.data.busy || this.data.pending) return;
    const row = this.data.rows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id));
    if (!row || !row.receivable) return;
    if (this.selected[row.id]) delete this.selected[row.id];
    else { if (Object.keys(this.selected).length >= 100) return this.setData({ error: '每次最多办理100块余料' }); this.selected[row.id] = Object.assign({}, row); }
    this.syncSelection();
  },
  togglePage() {
    if (this.data.busy || this.data.pending) return;
    const rows = this.data.rows.filter(row => row.receivable); const selected = this.data.allPageSelected;
    if (!selected && Object.keys(this.selected).length + rows.filter(row => !this.selected[row.id]).length > 100) return this.setData({ error: '每次最多办理100块余料' });
    rows.forEach(row => { if (selected) delete this.selected[row.id]; else this.selected[row.id] = Object.assign({}, row); }); this.syncSelection();
  },
  removeSelected(event) { if (!this.data.busy && !this.data.pending) { delete this.selected[event.currentTarget.dataset.id]; this.syncSelection(); } },
  changeStatus(event) { this.setData({ statusIndex: Number(event.detail.value), page: 1 }); this.load(); },
  turnPage(event) { const next = this.data.page + Number(event.currentTarget.dataset.delta); if (next > 0 && next <= this.data.lastPage && !this.data.loading) { this.setData({ page: next }); this.load(); } },
  openConfirm() {
    if (this.data.pending) return this.retry();
    if (!this.data.selectedCount || !this.data.canPost) return;
    this.setData({ mode: 'confirm', locator: {}, locatorError: '', remark: '', error: '' }); wx.setNavigationBarTitle({ title: '确认余料入库' });
  },
  returnToList() { if (this.data.busy) return; this.setData({ mode: 'list', locatorOpen: false, locatorError: '', error: '' }); wx.setNavigationBarTitle({ title: '余料入库' }); this.load(); },
  openLocator() { if (!this.data.busy && !this.data.pending) this.setData({ locatorOpen: true }); },
  closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) { this.setData({ locator: event.detail, locatorOpen: false, locatorError: '' }); },
  remarkInput(event) { this.setData({ remark: event.detail.value }); },
  submit() {
    if (this.data.busy || !this.data.canPost) return;
    if (!this.data.pending && !this.data.locator.location_id) return this.setData({ locatorError: '请选择仓库和库位' });
    if (!this.data.pending && (!this.data.selectedRows.length || this.data.selectedAmount === null)) return this.setData({ error: '请核对余料数量和金额' });
    this.setData({ busy: true, error: '' });
    const payload = { warehouse_id: this.data.locator.warehouse_id, location_id: this.data.locator.location_id, remark: this.data.remark,
      lines: this.data.selectedRows.map(row => ({ result_id: row.id, expected_version: row.business_version, holding_version: row.holding_version, physical_version: row.physical_version })) };
    return warehouse.submit('remnant.warehouse', this.data.orderId, payload).then(result => {
      this.selected = {}; this.setData({ pending: false, selectedRows: [], selectedCount: 0, selectedAmount: '0.0000', resultDialog: false });
      return this.loadReceipt(result.receipt_id);
    }).catch(error => {
      this.setData({ pending: !!error.pendingCommand, resultDialog: !!error.pendingCommand || error.statusCode === 409, resultMessage: page.errorText(error), error: page.errorText(error) });
    }).finally(() => this.setData({ busy: false }));
  },
  retry() { this.setData({ resultDialog: false }); return this.submit(); },
  closeResult() { this.setData({ resultDialog: false }); if (!this.data.pending) this.returnToList(); },
  openReceipt(event) { this.loadReceipt(Number(event.currentTarget.dataset.id)); },
  loadReceipt(id) {
    this.receiptId = id;
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ mode: 'receipt', receipt: null, loading: true, error: '' });
    return warehouse.remnantReceipt(this.data.orderId, id).then(response => {
      if (sequence !== this.sequence) return;
      const receipt = response.data || response;
      receipt.workOrderNos = (receipt.header_snapshot.work_order_nos || []).join('、');
      receipt.lines = receipt.lines.map(line => rowView(Object.assign({}, line.line_snapshot, { id: line.id, quantity: line.posted_qty, total_cost: line.posted_cost, batch_no: line.batch_no })));
      this.setData({ mode: 'receipt', receipt, loading: false }); wx.setNavigationBarTitle({ title: '余料入库记录' });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  }, retryReceipt() { return this.loadReceipt(this.receiptId); }, stop() {},
});
