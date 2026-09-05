const production = require('../../../services/production');

const STATUS_LABELS = { WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待开工', IN_PROGRESS: '进行中', PAUSED: '已暂停', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成' };

function targetOf(task) { return (task.target_details && task.target_details[0]) || {}; }
function view(task) {
  const target = targetOf(task);
  const item = (task.work_order && task.work_order.output_item) || {};
  const planned = (task.target_details || []).reduce((sum, row) => sum + Number(row.planned_base_qty || 0), 0);
  const completed = (task.target_details || []).reduce((sum, row) => sum + Number(row.completed_base_qty || 0), 0);
  return Object.assign({}, task, {
    targetStatus: target.status || task.status,
    statusLabel: STATUS_LABELS[target.status || task.status] || target.status || task.status,
    productName: item.item_name || item.name || '-',
    workOrderNo: (task.work_order && task.work_order.work_order_no) || '-',
    planned, completed,
    unitName: task.execution_mode === 'unit' ? '台' : '',
    operation: `${task.sequence_no_snapshot || '-'} - ${task.operation_name_snapshot || '-'}`,
    kitting: target.kitting_confirmed_at ? '已齐套' : (target.status === 'WAIT_MATERIAL' ? '缺料' : '待确认'),
  });
}

Page({
  data: { loading: true, rows: [], filteredRows: [], active: 'all', keyword: '', stats: { total: 0, running: 0, waiting: 0, completed: 0 } },
  onShow() {
    if (typeof this.getTabBar === 'function' && this.getTabBar()) this.getTabBar().setData({ active: 1 });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, rows: [], filteredRows: [] });
      wx.showToast({ title: '请先在“我的”登录 ERP', icon: 'none' });
      return Promise.resolve();
    }
    this.setData({ loading: true });
    return production.myTasks({ page: 1, per_page: 100 }).then((response) => {
      const rows = (response.data || []).map(view);
      this.setData({ rows, stats: {
        total: rows.length,
        running: rows.filter((row) => ['IN_PROGRESS', 'PAUSED'].includes(row.targetStatus)).length,
        waiting: rows.filter((row) => ['CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'READY'].includes(row.targetStatus)).length,
        completed: rows.filter((row) => row.targetStatus === 'COMPLETED').length,
      }, loading: false });
      this.filterRows();
    }).catch((error) => { this.setData({ loading: false, rows: [], filteredRows: [] }); wx.showToast({ title: error.message, icon: 'none' }); });
  },
  setTab(event) { this.setData({ active: event.currentTarget.dataset.value }); this.filterRows(); },
  onSearch(event) { this.setData({ keyword: event.detail.value || '' }); this.filterRows(); },
  filterRows() {
    const active = this.data.active;
    const keyword = this.data.keyword.trim().toLowerCase();
    const groups = { running: ['IN_PROGRESS', 'PAUSED'], waiting: ['CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'READY'], completed: ['COMPLETED'] };
    this.setData({ filteredRows: this.data.rows.filter((row) => {
      const stateOk = active === 'all' || (groups[active] || []).includes(row.targetStatus);
      const text = `${row.task_no} ${row.workOrderNo} ${row.productName} ${row.operation}`.toLowerCase();
      return stateOk && (!keyword || text.includes(keyword));
    }) });
  },
  openTask(event) { wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` }); },
  scan() { wx.scanCode({ success: (result) => wx.navigateTo({ url: `/pages/production/queue/index?type=trace&keyword=${encodeURIComponent(result.result)}` }) }); },
});
