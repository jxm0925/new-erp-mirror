const production = require('../../../services/production');
const util = require('../../../utils/util');

const STATUS_LABELS = {
  WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接',
  READY: '待开工', IN_PROGRESS: '进行中', PAUSED: '已暂停', WAIT_QUALITY: '待质检',
  WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成'
};

function taskView(task, target) {
  const workOrder = task.work_order || {};
  const item = workOrder.output_item || {};
  return Object.assign({}, task, {
    targetStatus: target.status || task.status,
    statusLabel: STATUS_LABELS[target.status || task.status] || target.status || task.status || '-',
    workOrderNo: workOrder.work_order_no || '-',
    productName: item.item_name || item.name || workOrder.output_item_name_snapshot || '-',
    operationLabel: `${task.sequence_no_snapshot || '-'} - ${task.operation_name_snapshot || '-'}`,
    unitNo: target.production_unit_no || '—',
    targetKey: `${task.id}-${target.target_type}-${target.target_id}`,
    targetType: target.target_type,
    targetId: target.target_id,
    laborText: `${Number(target.actual_labor_minutes || 0).toFixed(1)} 分钟`,
    shortageText: '—'
  });
}

function parseDepartmentNames(user) {
  if (!user || !user.department_names) return [];
  if (Array.isArray(user.department_names)) return user.department_names;
  try {
    const parsed = JSON.parse(user.department_names);
    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    return [];
  }
}

Page({
  data: {
    loading: true,
    loaded: false,
    authenticated: false,
    userName: '',
    userDisplayName: '当前操作员',
    stats: { running: '—', pending: '—', completed: '—' },
    shortcuts: [
      { key: 'orders', title: '生产工单', icon: 'orders-o', count: null },
      { key: 'pool', title: '待接任务', icon: 'records', count: 0 },
      { key: 'tasks', title: '我的任务', icon: 'notes-o', count: 0 },
      { key: 'collaboration', title: '我的协同', icon: 'friends-o', count: 0 },
      { key: 'receipts', title: '物料签收', icon: 'sign', count: 0 },
      { key: 'handover', title: '工序交接', icon: 'exchange', count: 0 },
      { key: 'kitting', title: '待齐套', icon: 'passed', count: 0 }
    ],
    currentTasks: []
  },

  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onUnload() { this.requestSequence = (this.requestSequence || 0) + 1; },

  load() {
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ authenticated: false, loaded: false, loading: false, currentTasks: [] });
      return Promise.resolve();
    }
    const erpUser = wx.getStorageSync('erp_user') || {};
    const name = erpUser.nickname || erpUser.username || '当前操作员';
    const depts = parseDepartmentNames(erpUser);
    const userDisplayName = depts.length ? `${name} · ${depts[0]}` : name;

    this.setData({
      authenticated: true,
      loading: true,
      loaded: false,
      currentTasks: [],
      stats: { running: '—', pending: '—', completed: '—' },
      shortcuts: this.data.shortcuts.map(item => Object.assign({}, item, { count: null })),
      userName: name,
      userDisplayName
    });

    // 无权限不发请求；有权限但请求失败必须保留错误，不能把网络错误转成零待办。
    const permissions = wx.getStorageSync('erp_permissions') || [];
    const can = code => Array.isArray(permissions) ? permissions.includes(code) : permissions[code] === true;
    const read = (permission, request) => can(permission) ? request() : Promise.resolve(null);

    return Promise.all([
      read('production.work_order.view', () => production.masterOrders({ page: 1, per_page: 1 })),
      read('production.task.view', () => production.taskPool({ page: 1, per_page: 20 })),
      read('production.task.view', () => production.myTasks({ page: 1, per_page: 20, execution_filter: 'current', include_stats: 1 })),
      read('production.task.view', () => production.collaborations({ page: 1, per_page: 20 })),
      read('production.material_delivery.view', () => production.deliveries({ status: 'DELIVERED', page: 1, per_page: 20 })),
      read('production.handover.view', () => production.pendingHandovers({ page: 1, per_page: 20 }))
    ]).then(([orders, pool, owned, collaboration, receipts, handovers]) => {
      if (sequence !== this.requestSequence) return;
      const tasks = ((owned && owned.data) || []).reduce((rows, task) => rows.concat((task.target_details || [])
        .filter(target => ['IN_PROGRESS', 'PAUSED', 'WAIT_MATERIAL', 'READY'].includes(target.status)).map(target => taskView(task, target))), []).slice(0, 3);
      const stats = owned && owned.stats;
      const total = response => response ? response.total : null;
      const counts = {
        orders: total(orders),
        pool: total(pool),
        tasks: stats ? stats.total : null,
        collaboration: total(collaboration),
        receipts: total(receipts),
        handover: total(handovers),
        kitting: stats ? stats.kitting : null
      };

      this.setData({
        stats: stats ? { running: stats.running, pending: stats.waiting, completed: stats.completed_today } : { running: '—', pending: '—', completed: '—' },
        shortcuts: this.data.shortcuts.map((item) => Object.assign({}, item, { count: counts[item.key] })),
        currentTasks: tasks,
        loading: false,
        loaded: !!owned
      });

      if (can('production.kitting.view')) tasks.forEach((task, index) => {
        if (task.targetStatus !== 'WAIT_MATERIAL') return;
        production.kittingRequirements(task.id, task.targetType, task.targetId).then(response => {
          if (sequence !== this.requestSequence) return;
          const count = (response.data || []).filter(row => Number(row.shortage_base_qty) > 0).length;
          this.setData({ [`currentTasks[${index}].shortageText`]: count ? `缺 ${count} 项物料` : '物料已满足' });
        }).catch(error => {
          if (sequence === this.requestSequence) wx.showToast({ title: error.message, icon: 'none' });
        });
      });
    }).catch(error => {
      if (sequence !== this.requestSequence) return;
      this.setData({ loading: false, loaded: false, authenticated: !!wx.getStorageSync('erp_token') });
      wx.showToast({ title: error.message || '加载失败，请下拉重试', icon: 'none' });
    });
  },

  openShortcut(event) {
    const key = event.currentTarget.dataset.key;
    if (key === 'orders') return wx.navigateTo({ url: '/pages/production/tasks/index' });
    if (key === 'tasks') return wx.navigateTo({ url: '/pages/production/my-tasks/index' });
    wx.navigateTo({ url: `/pages/production/queue/index?type=${key}` });
  },

  goTasksWithFilter(event) {
    const filter = event.currentTarget.dataset.filter || 'all';
    wx.navigateTo({ url: `/pages/production/my-tasks/index?execution_filter=${filter}` });
  },

  goAllTasks() {
    wx.navigateTo({ url: '/pages/production/my-tasks/index' });
  },

  openTask(event) {
    wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` });
  },

  onTaskAction(event) {
    const id = event.currentTarget.dataset.id;
    wx.navigateTo({ url: `/pages/production/task-detail/index?id=${id}` });
  },

  openLogin() {
    util.BadgePopup();
  }
});
