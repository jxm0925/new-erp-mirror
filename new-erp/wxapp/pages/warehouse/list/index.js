const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
Page({
  data: { direction: 'inbound', statusGroup: 'pending', kind: '', keyword: '', page: 1, lastPage: 1, total: 0, rows: [], types: [{ key: '', name: '全部' }], typeIndex: 0,
    loading: false, error: '', filterOpen: false, dateFrom: '', dateTo: '', draftFrom: '', draftTo: '' },
  onLoad(options) {
    const direction = ['inbound', 'outbound', 'all'].includes(options.direction) ? options.direction : 'inbound';
    this.setData({ direction, canCreatePicking: direction !== 'inbound' && page.permissions()('production.material_picking.create') });
    wx.setNavigationBarTitle({ title: direction === 'inbound' ? '入库办理' : direction === 'outbound' ? '出库办理' : '仓库待办' });
  },
  onShow() { this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return warehouse.queue({ direction: this.data.direction, status_group: this.data.statusGroup, kind: this.data.kind,
      keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10, date_from: this.data.dateFrom, date_to: this.data.dateTo }).then(response => {
      if (sequence !== this.sequence) return;
      const parsed = page.rows(response);
      const types = [{ key: '', name: '全部' }].concat((response.types || []).filter(type => this.data.direction === 'all' || type.direction === this.data.direction));
      if (parsed.page > parsed.lastPage) { this.setData({ page: parsed.lastPage }); return this.load(); }
      this.setData({ rows: parsed.rows.map(page.decorate), page: parsed.page, lastPage: parsed.lastPage, total: parsed.total, types,
        typeIndex: Math.max(0, types.findIndex(type => type.key === this.data.kind)), loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: page.errorText(error) }); });
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  switchStatus(event) { this.setData({ statusGroup: event.currentTarget.dataset.status, page: 1 }); this.load(); },
  changeType(event) { const type = this.data.types[Number(event.detail.value)]; if (type) { this.setData({ kind: type.key, typeIndex: Number(event.detail.value), page: 1 }); this.load(); } },
  previous() { if (this.data.page > 1 && !this.data.loading) { this.setData({ page: this.data.page - 1 }); this.load(); } },
  next() { if (this.data.page < this.data.lastPage && !this.data.loading) { this.setData({ page: this.data.page + 1 }); this.load(); } },
  openDocument(event) { const row = this.data.rows.find(row => row.key === event.currentTarget.dataset.key); if (row) page.openDocument(row); },
  createPicking() { wx.navigateTo({ url: '/pages/warehouse/picking/index?mode=create' }); },
  openFilter() { this.setData({ filterOpen: true, draftFrom: this.data.dateFrom, draftTo: this.data.dateTo, filterError: '' }); },
  closeFilter() { this.setData({ filterOpen: false }); },
  filterDate(event) { this.setData({ [event.currentTarget.dataset.field]: event.detail.value }); },
  resetFilter() { this.setData({ draftFrom: '', draftTo: '' }); },
  applyFilter() {
    if (this.data.draftFrom && this.data.draftTo && this.data.draftFrom > this.data.draftTo) return this.setData({ filterError: '结束日期不能早于开始日期' });
    this.setData({ dateFrom: this.data.draftFrom, dateTo: this.data.draftTo, page: 1, filterOpen: false }); this.load();
  },
  stop() {},
});
