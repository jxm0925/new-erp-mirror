const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const TABS = [{ key: 'mine', label: '我的' }, { key: 'available', label: '可接' }, { key: 'done', label: '做完的' }];

function decorate(row, tab, canExecute) {
  const planned = Number(row.planned_qty || 0);
  const done = Number(row.done_qty || 0);
  const canClaim = Boolean(canExecute && row.allowed && row.allowed.claim);
  return Object.assign({}, row, {
    plannedText: view.quantity(row.planned_qty), doneText: view.quantity(row.done_qty),
    percent: planned > 0 ? Math.min(100, Math.round(done / planned * 100)) : 0,
    dueText: String(row.planned_finish_date || '').slice(5, 10),
    canClaim,
    buttonText: !canExecute || tab === 'done' || (tab === 'available' && !canClaim) ? '查看' : (tab === 'available' ? '接单' : (row.timing ? '继续报工' : (row.here_qty ? '开始报工' : '查看'))),
  });
}

Page({
  data: { tabs: TABS, tab: 'mine', counts: { mine: 0, available: 0 }, rows: [], page: 1, lastPage: 1, total: 0,
    loading: true, busy: false, error: '', keyword: '', userName: '', canExecute: false },
  onLoad(options) {
    const user = wx.getStorageSync('erp_user') || {};
    this.setData({ tab: TABS.some(t => t.key === options.tab) ? options.tab : 'mine', userName: user.nickname || user.display_name || user.username || '' });
  },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return production.tasks({ tab: this.data.tab, page: this.data.page, per_page: 10, keyword: this.data.keyword }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response);
      const canExecute = Boolean(response.permissions && response.permissions.execute);
      this.setData({ rows: paged.rows.map(row => decorate(row, this.data.tab, canExecute)), page: paged.page, lastPage: paged.lastPage, total: paged.total, canExecute,
        counts: response.counts || this.data.counts, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  pickTab(event) {
    const tab = event.currentTarget.dataset.tab;
    if (tab === this.data.tab) return;
    this.setData({ tab, page: 1, rows: [] });
    this.load();
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  turnPage(event) {
    const page = this.data.page + Number(event.currentTarget.dataset.delta);
    if (page > 0 && page <= this.data.lastPage) { this.setData({ page }); this.load(); }
  },
  open(event) { wx.navigateTo({ url: `/pages/production/task/index?id=${Number(event.currentTarget.dataset.id)}` }); },
  primary(event) {
    const id = Number(event.currentTarget.dataset.id);
    const row = this.data.rows.find(item => item.id === id);
    if (this.data.tab !== 'available' || !this.data.canExecute || !row || !row.canClaim) return this.open(event);
    if (this.data.busy) return;
    this.setData({ busy: true });
    production.action(id, { action: 'claim' }).then(response => {
      wx.showToast({ title: response.message || '接单成功', icon: 'success' });
      wx.navigateTo({ url: `/pages/production/task/index?id=${id}` });
    }).catch(error => {
      wx.showModal({ title: '接单没成功', content: view.errorText(error), showCancel: false });
      this.load();
    }).finally(() => this.setData({ busy: false }));
  },
  back() { view.back(); },
});
