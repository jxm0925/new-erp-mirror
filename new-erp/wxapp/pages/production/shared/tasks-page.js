const production = require('../../../services/production');

const STATUS_LABELS = { WAIT_PREVIOUS: '待前工序', WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待开工', IN_PROGRESS: '进行中', PAUSED: '已暂停', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成', CANCELLED: '已取消' };

function targetOf(task) {
  const targets = task.target_details || [];
  return targets.find(row => row.status === 'IN_PROGRESS') || targets.find(row => row.status === 'PAUSED') || targets.find(row => !['COMPLETED', 'CANCELLED'].includes(row.status)) || targets.find(row => row.status === 'COMPLETED') || targets[0] || {};
}
function view(task) {
  const target = targetOf(task);
  const item = (task.work_order && task.work_order.output_item) || {};
  const planned = (task.target_details || []).reduce((sum, row) => sum + Number(row.planned_base_qty || 0), 0);
  const completed = (task.target_details || []).reduce((sum, row) => sum + Number(row.completed_base_qty || 0), 0);
  return Object.assign({}, task, {
    targetStatus: target.status || task.status,
    statusLabel: STATUS_LABELS[target.status || task.status] || '状态待更新',
    productName: item.item_name || item.name || '-',
    workOrderNo: (task.work_order && task.work_order.work_order_no) || '-',
    planned, completed,
    unitName: task.execution_mode === 'unit' ? '台' : '',
    operation: `${task.sequence_no_snapshot || '-'} - ${task.operation_name_snapshot || '-'}`,
    kitting: target.kitting_confirmed_at ? '已齐套' : (target.status === 'WAIT_MATERIAL' ? '缺料' : '待确认'),
  });
}

module.exports = function createTasksPage() { return {
  data: { loading: true, loaded: false, rows: [], filteredRows: [], active: 'all', keyword: '', page: 0, total: 0, loadingMore: false, syncedAt: '', stats: { total: '—', running: '—', waiting: '—', completed: '—' } },
  onLoad(options) {
    if (options && options.execution_filter) {
      this.setData({ active: options.execution_filter });
    }
  },
  onShow() {
    if (typeof this.getTabBar === 'function' && this.getTabBar()) this.getTabBar().setData({ active: 1 });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() { if (!this.data.loading && !this.data.loadingMore && this.data.rows.length < this.data.total) this.load(true); },
  onUnload() { clearTimeout(this.searchTimer); this.requestSequence = (this.requestSequence || 0) + 1; },
  load(append = false) {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, loaded: false, rows: [], filteredRows: [], stats: { total: '—', running: '—', waiting: '—', completed: '—' } });
      wx.showToast({ title: '请先登录统一账号', icon: 'none' });
      return Promise.resolve();
    }
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    const page = append ? this.data.page + 1 : 1;
    this.setData(append ? { loadingMore: true } : { loading: true, loaded: false, rows: [], filteredRows: [], stats: { total: '—', running: '—', waiting: '—', completed: '—' } });
    return production.myTasks({ page, per_page: 20, keyword: this.data.keyword.trim(), execution_filter: this.data.active, include_stats: 1 }).then((response) => {
      if (sequence !== this.requestSequence) return;
      const existing = append ? this.data.rows : [];
      const ids = new Set(existing.map(row => row.id));
      const rows = existing.concat((response.data || []).filter(row => !ids.has(row.id)).map(view));
      const stats = response.stats || {};
      this.setData({ rows, filteredRows: rows, page, total: response.total, stats: {
        total: stats.today_total, running: stats.running, waiting: stats.waiting, completed: stats.completed_today,
      }, loaded: true, loading: false, loadingMore: false, syncedAt: new Date().toLocaleTimeString() });
    }).catch((error) => {
      if (sequence !== this.requestSequence) return;
      this.setData({ loading: false, loadingMore: false, loaded: append && this.data.loaded });
      wx.showToast({ title: error.message, icon: 'none' });
    });
  },
  setTab(event) { clearTimeout(this.searchTimer); this.setData({ active: event.currentTarget.dataset.value }); this.load(); },
  onSearch(event) { this.setData({ keyword: event.detail.value || '' }); clearTimeout(this.searchTimer); this.searchTimer = setTimeout(() => this.load(), 300); },
  openTask(event) { wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` }); },
  scan() { wx.scanCode({ success: (result) => wx.navigateTo({ url: `/pages/production/queue/index?type=trace&keyword=${encodeURIComponent(result.result)}` }) }); },
}; };
