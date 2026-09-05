const production = require('../../services/production');

const windowInfo = wx.getWindowInfo();
const menuButton = wx.getMenuButtonBoundingClientRect();
const statusBarHeight = windowInfo.statusBarHeight || 20;
const navBarHeight = Math.max(44, (menuButton.top - statusBarHeight) * 2 + menuButton.height);

const STATUS_LABELS = {
  WAIT_PREVIOUS: '待上序', WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套',
  WAIT_HANDOVER: '待交接', READY: '待开工', IN_PROGRESS: '进行中', PAUSED: '已暂停',
  WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成', CANCELLED: '已取消',
};

function pageRows(response) {
  return (response && response.data) || [];
}

function firstTarget(task) {
  return (task.target_details && task.target_details[0]) || {};
}

function minuteText(value) {
  const minutes = Math.max(0, Number(value || 0));
  const hours = Math.floor(minutes / 60);
  const rest = Math.floor(minutes % 60);
  return `${String(hours).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

function taskView(task) {
  const target = firstTarget(task);
  const item = (task.work_order && task.work_order.output_item) || {};
  const count = (task.target_details || []).length;
  return Object.assign({}, task, {
    statusLabel: STATUS_LABELS[target.status || task.status] || target.status || task.status || '-',
    statusClass: target.status === 'IN_PROGRESS' ? 'running' : (['WAIT_MATERIAL', 'WAIT_HANDOVER'].includes(target.status) ? 'warning' : ''),
    productName: item.item_name || item.name || (task.work_order && task.work_order.output_item_name_snapshot) || '-',
    unitNo: target.production_unit_no || (task.execution_mode === 'quantity' ? `数量任务 · ${target.planned_base_qty || 0}` : `${count} 个生产单元`),
    operationLabel: `${task.sequence_no_snapshot || '-'} - ${task.operation_name_snapshot || '-'}`,
    laborText: minuteText(target.actual_labor_minutes),
    targetStatus: target.status,
  });
}

Page({
  data: {
    statusBarHeight,
    navBarHeight,
    navRight: Math.max(88, windowInfo.windowWidth - menuButton.left + 8),
    loading: true,
    unauthenticated: false,
    userName: '生产员工',
    stats: { running: 0, pending: 0, completed: 0 },
    badges: { pool: 0, owned: 0, collaboration: 0, receipts: 0, handovers: 0, kitting: 0 },
    currentTasks: [],
    entries: [
      { key: 'pool', label: '待接任务', icon: 'orders-o', type: 'pool' },
      { key: 'owned', label: '我的任务', icon: 'description', tab: true },
      { key: 'collaboration', label: '我的协同', icon: 'friends-o', type: 'collaboration' },
      { key: 'receipts', label: '待收料', icon: 'logistics', type: 'receipts', tone: 'orange' },
      { key: 'handovers', label: '待交接', icon: 'exchange', type: 'handover' },
      { key: 'kitting', label: '待齐套', icon: 'passed', type: 'kitting', tone: 'orange' },
    ],
  },

  onShow() {
    if (typeof this.getTabBar === 'function' && this.getTabBar()) this.getTabBar().setData({ active: 0 });
    this.load();
  },

  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },

  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, unauthenticated: true, currentTasks: [], stats: { running: 0, pending: 0, completed: 0 }, badges: { pool: 0, owned: 0, collaboration: 0, receipts: 0, handovers: 0, kitting: 0 } });
      return Promise.resolve();
    }
    this.setData({ loading: true, unauthenticated: false });
    const safe = (promise) => promise.catch((error) => ({ __error: error }));
    return Promise.all([
      safe(production.taskPool({ page: 1, per_page: 1 })),
      safe(production.myTasks({ page: 1, per_page: 20 })),
      safe(production.collaborations({ page: 1, per_page: 1 })),
      safe(production.deliveries({ status: 'DELIVERED', page: 1, per_page: 1 })),
      safe(production.pendingHandovers({ page: 1, per_page: 1 })),
    ]).then(([pool, owned, collaboration, receipts, handovers]) => {
      const error = [pool, owned, collaboration, receipts, handovers].find((item) => item && item.__error);
      if (error && error.__error.statusCode === 401) {
        this.setData({ unauthenticated: true, currentTasks: [], loading: false });
        return;
      }
      const tasks = owned.__error ? [] : pageRows(owned).map(taskView);
      const running = tasks.filter((task) => task.targetStatus === 'IN_PROGRESS').length;
      const completed = tasks.filter((task) => task.targetStatus === 'COMPLETED').length;
      const pending = tasks.length - running - completed;
      const user = wx.getStorageSync('erp_user') || {};
      this.setData({
        userName: user.nickname || user.username || '生产员工',
        stats: { running, pending, completed },
        badges: {
          pool: pool.total || 0, owned: owned.total || 0, collaboration: collaboration.total || 0,
          receipts: receipts.total || 0, handovers: handovers.total || 0,
          kitting: tasks.filter((task) => task.targetStatus === 'WAIT_MATERIAL').length,
        },
        currentTasks: tasks.filter((task) => ['IN_PROGRESS', 'PAUSED', 'WAIT_MATERIAL', 'READY'].includes(task.targetStatus)).slice(0, 2),
        loading: false,
      });
    });
  },

  openEntry(event) {
    const entry = event.currentTarget.dataset.entry;
    if (entry.tab) return wx.switchTab({ url: '/pages/production/tasks/index' });
    wx.navigateTo({ url: `/pages/production/queue/index?type=${entry.type}` });
  },

  openTask(event) {
    wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` });
  },

  scan() {
    wx.scanCode({ success: (result) => wx.navigateTo({ url: `/pages/production/queue/index?type=trace&keyword=${encodeURIComponent(result.result)}` }) });
  },

  openMore(event) {
    const urls = { suggest: '/pages/suggests/index/index', report: '/pages/my/report/index/index', mall: '/pages/mall/index/index' };
    wx.navigateTo({ url: urls[event.currentTarget.dataset.key] });
  },
});
