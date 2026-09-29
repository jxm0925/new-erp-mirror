const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const { statuses, creationStore } = require('../../../utils/warehouse-delivery');
Page({
  data: { tabs: [{ value: 'READY', name: '待发出' }, { value: 'IN_TRANSIT', name: '配送中' }, { value: 'DELIVERED', name: '待签收' }, { value: 'RECEIVED', name: '已完成' }], status: 'READY', keyword: '', rows: [], page: 1, lastPage: 1, total: 0, loading: true, error: '' },
  onLoad(options) { if (this.data.tabs.some(tab => tab.value === options.status)) this.setData({ status: options.status }); },
  onShow() { this.setData({ recoveryRows: creationStore().pending(warehouse.pending) }); this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { return this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '', rows: [] });
    return warehouse.get('production/material-deliveries', { status: this.data.status, keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return;
      const result = page.rows(response);
      this.setData({ loading: false, rows: result.rows.map(row => Object.assign({}, row, { statusText: statuses[row.status] || row.status })), page: result.page, lastPage: result.lastPage, total: result.total });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  statusChange(event) { this.setData({ status: event.currentTarget.dataset.status, page: 1 }); this.load(); },
  turnPage(event) { const next = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && next > 0 && next <= this.data.lastPage) { this.setData({ page: next }); this.load(); } },
  open(event) { wx.navigateTo({ url: `/pages/warehouse/delivery/index?id=${Number(event.currentTarget.dataset.id)}` }); },
  recover(event) { wx.navigateTo({ url: `/pages/warehouse/delivery/index?recover=${Number(event.currentTarget.dataset.id)}` }); },
});
