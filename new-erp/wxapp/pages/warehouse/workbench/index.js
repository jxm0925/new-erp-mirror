const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const util = require('../../../utils/util');
Page({
  data: { loading: true, authenticated: false, error: '', summary: null, todos: [], freezes: [], freezeTotal: 0, freezeOverdue: false },
  onShow() { this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    if (!wx.getStorageSync('erp_token')) { this.setData({ authenticated: false, loading: false, summary: null, todos: [], error: '' }); return Promise.resolve(); }
    this.setData({ authenticated: true, loading: true, error: '' });
    return warehouse.summary().then(response => {
      if (sequence !== this.sequence) return;
      const summary = response.data || response;
      const freezes = summary.stocktake_freezes || { data: [], total: 0, overdue: false };
      this.setData({ summary, todos: (summary.todos.data || []).map(page.decorate), loading: false,
        freezes: freezes.data || [], freezeTotal: freezes.total || 0, freezeOverdue: !!freezes.overdue,
        canInbound: summary.types.some(type => type.direction === 'inbound'), canOutbound: summary.types.some(type => type.direction === 'outbound') });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, summary: null, todos: [], error: page.errorText(error) }); });
  },
  openList(event) { wx.navigateTo({ url: `/pages/warehouse/list/index?direction=${event.currentTarget.dataset.direction || 'all'}` }); },
  openTodo(event) { const row = this.data.todos.find(row => row.key === event.currentTarget.dataset.key); if (row) page.openDocument(row); },
  openPacking() { wx.navigateTo({ url: '/pages/production/shipment-packing/index' }); },
  openProfile() { wx.switchTab({ url: '/pages/my/index/index' }); },
  openLogin() { util.BadgePopup(); },
});
