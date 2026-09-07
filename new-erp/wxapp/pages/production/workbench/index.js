const production = require('../../../services/production');
const util = require('../../../utils/util');

const STATUS_LABELS = {
  WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接',
  READY: '待开工', IN_PROGRESS: '进行中', PAUSED: '已暂停', WAIT_QUALITY: '待质检',
  WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成'
};

function taskView(task) {
  const target = (task.target_details && task.target_details[0]) || {};
  const workOrder = task.work_order || {};
  const item = workOrder.output_item || {};
  return Object.assign({}, task, {
    targetStatus: target.status || task.status,
    statusLabel: STATUS_LABELS[target.status || task.status] || target.status || task.status || '-',
    workOrderNo: workOrder.work_order_no || '-',
    productName: item.item_name || item.name || workOrder.output_item_name_snapshot || '-',
    operationLabel: `${task.sequence_no_snapshot || '-'} - ${task.operation_name_snapshot || '-'}`,
    unitNo: target.production_unit_no || '-'
  });
}

Page({
  data: {
    loading: true,
    authenticated: false,
    userName: '',
    stats: { running: 0, pending: 0, completed: 0 },
    shortcuts: [
      { key: 'pool', title: '待接任务', icon: 'orders-o', count: 0 },
      { key: 'tasks', title: '我的任务', icon: 'notes-o', count: 0 },
      { key: 'collaboration', title: '我的协同', icon: 'friends-o', count: 0 },
      { key: 'deliveries', title: '物料配送', icon: 'logistics', count: 0 },
      { key: 'receipts', title: '物料签收', icon: 'sign', count: 0 },
      { key: 'handover', title: '待交接', icon: 'exchange', count: 0 },
      { key: 'kitting', title: '待齐套', icon: 'passed', count: 0 }
    ],
    currentTasks: []
  },

  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },

  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ authenticated: false, loading: false, currentTasks: [] });
      return Promise.resolve();
    }
    const erpUser = wx.getStorageSync('erp_user') || {};
    this.setData({ authenticated: true, loading: true, userName: erpUser.nickname || erpUser.username || '当前员工' });
    const safe = (request) => request.catch(() => ({ data: [], total: 0 }));
    return Promise.all([
      safe(production.taskPool({ page: 1, per_page: 20 })),
      safe(production.myTasks({ page: 1, per_page: 20 })),
      safe(production.collaborations({ page: 1, per_page: 20 })),
      safe(production.deliveries({ status: 'READY', page: 1, per_page: 20 })),
      safe(production.deliveries({ status: 'IN_TRANSIT', page: 1, per_page: 20 })),
      safe(production.deliveries({ status: 'DELIVERED', page: 1, per_page: 20 })),
      safe(production.pendingHandovers({ page: 1, per_page: 20 }))
    ]).then(([pool, owned, collaboration, readyDeliveries, transitDeliveries, receipts, handovers]) => {
      const tasks = (owned.data || []).map(taskView);
      const running = tasks.filter((task) => ['IN_PROGRESS', 'PAUSED'].includes(task.targetStatus)).length;
      const completed = tasks.filter((task) => task.targetStatus === 'COMPLETED').length;
      const pending = tasks.filter((task) => !['IN_PROGRESS', 'PAUSED', 'COMPLETED'].includes(task.targetStatus)).length;
      const counts = {
        pool: pool.total || 0,
        tasks: owned.total || tasks.length,
        collaboration: collaboration.total || 0,
        deliveries: (readyDeliveries.total || 0) + (transitDeliveries.total || 0),
        receipts: receipts.total || 0,
        handover: handovers.total || (handovers.data || []).length,
        kitting: tasks.filter((task) => task.targetStatus === 'WAIT_MATERIAL').length
      };
      this.setData({
        stats: { running, pending, completed },
        shortcuts: this.data.shortcuts.map((item) => Object.assign({}, item, { count: counts[item.key] || 0 })),
        currentTasks: tasks.filter((task) => ['IN_PROGRESS', 'PAUSED', 'WAIT_MATERIAL', 'READY'].includes(task.targetStatus)).slice(0, 3),
        loading: false
      });
    });
  },

  openShortcut(event) {
    const key = event.currentTarget.dataset.key;
    if (key === 'tasks') return wx.navigateTo({ url: '/pages/production/tasks/index' });
    wx.navigateTo({ url: `/pages/production/queue/index?type=${key}` });
  },
  openTask(event) { wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` }); },
  openLogin() { util.BadgePopup(); }
});
