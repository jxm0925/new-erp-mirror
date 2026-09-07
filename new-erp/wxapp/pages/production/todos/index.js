const production = require('../../../services/production');

Page({
  data: {
    loading: true,
    erpToken: false,
    todoTotal: 0,
    groups: [
      { key: 'pool', title: '待接任务', description: '进入任务池自主接单', icon: 'orders-o', count: 0 },
      { key: 'deliveries', title: '物料配送', description: '处理待发出和配送中的配送单', icon: 'logistics', count: 0 },
      { key: 'receipts', title: '物料签收', description: '核对实送数量并确认签收', icon: 'sign', count: 0 },
      { key: 'handover', title: '待交接', description: '接收上一工序实物成果', icon: 'exchange', count: 0 },
      { key: 'kitting', title: '待齐套', description: '逐生产单元确认开工条件', icon: 'passed', count: 0 },
    ],
  },
  onShow() {
    if (typeof this.getTabBar === 'function' && this.getTabBar()) this.getTabBar().setData({ active: 2 });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, erpToken: false });
      return Promise.resolve();
    }
    this.setData({ loading: true, erpToken: true });
    const safe = (request) => request.catch(() => ({ data: [], total: 0 }));
    return Promise.all([
      safe(production.taskPool({ page: 1, per_page: 20 })),
      safe(production.deliveries({ status: 'READY', page: 1, per_page: 20 })),
      safe(production.deliveries({ status: 'IN_TRANSIT', page: 1, per_page: 20 })),
      safe(production.deliveries({ status: 'DELIVERED', page: 1, per_page: 20 })),
      safe(production.pendingHandovers({ page: 1, per_page: 20 })),
      safe(production.myTasks({ page: 1, per_page: 20 })),
    ]).then(([pool, readyDeliveries, transitDeliveries, receipts, handovers, tasks]) => {
      const counts = {
        pool: pool.total || 0,
        deliveries: (readyDeliveries.total || 0) + (transitDeliveries.total || 0),
        receipts: receipts.total || 0,
        handover: (handovers.data || []).length,
        kitting: (tasks.data || []).filter((task) => (task.target_details || []).some((target) => target.status === 'WAIT_MATERIAL')).length,
      };
      const groups = this.data.groups.map((item) => Object.assign({}, item, { count: counts[item.key] || 0 }));
      this.setData({ groups, todoTotal: groups.reduce((sum, item) => sum + item.count, 0), loading: false });
    });
  },
  open(event) { wx.navigateTo({ url: `/pages/production/queue/index?type=${event.currentTarget.dataset.key}` }); },
});
