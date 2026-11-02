const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
Page({
  data: { loading: false, error: '', keyword: '', status: 'pending', rows: [], page: 1, lastPage: 1, total: 0,
    tabs: [{ value: 'pending', label: '待配料' }, { value: 'prepared', label: '待发出' }, { value: 'dispatched', label: '待签收' }, { value: 'rejected', label: '拒收' }, { value: 'signed', label: '已签收' }, { value: 'cancelled', label: '已取消' }, { value: 'recalled', label: '已收回' }] },
  onShow() { this.load(); }, onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    const query = { keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10, status: this.data.status };
    return (this.data.status === 'pending' ? warehouse.pendingDeliveries(query) : warehouse.deliveries(query)).then(response => {
      if (sequence !== this.sequence) return; const result = page.rows(response);
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.load(); }
      this.setData({ loading: false, rows: result.rows.map(row => Object.assign({}, row, { key: this.data.status === 'pending' ? row.operation_id : row.id, lines: (row.lines || []).map(line => Object.assign({}, line, { qtyText: page.quantity(line.qty) })) })), page: result.page, lastPage: result.lastPage, total: result.total });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error), rows: [] }); });
  },
  input(event) { this.setData({ keyword: event.detail.value }); }, search() { this.setData({ page: 1 }); this.load(); },
  switchTab(event) { this.setData({ status: event.currentTarget.dataset.status, page: 1 }); this.load(); },
  turn(event) { const target = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && target > 0 && target <= this.data.lastPage) { this.setData({ page: target }); this.load(); } },
  open(event) { const row = this.data.rows[Number(event.currentTarget.dataset.index)]; if (row) page.openDocument({ kind: this.data.status === 'pending' ? 'material_preparation' : 'material_delivery', id: this.data.status === 'pending' ? row.operation_id : row.id, parent_id: row.work_order_id }); },
});
