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
      { key: 'picking', title: '拣货执行', description: '分配、拣货并确认正式库存过账', icon: 'cluster-o', count: 0 },
      { key: 'outputs_quality', title: '产出质检', description: '处理工序完工后的生产质检', icon: 'certificate', count: 0 },
      { key: 'outputs_warehouse', title: '产出入库', description: '选择真实仓库、库位并完成入库', icon: 'shop-o', count: 0 },
      { key: 'internal_dispatch', title: '半成品发料', description: '仓库向下一工序交付半成品', icon: 'send-gift-o', count: 0 },
      { key: 'internal_receive', title: '半成品接收', description: '下一工序负责人确认接收', icon: 'sign', count: 0 },
      { key: 'supplements', title: '补料审批', description: '审批生产现场追加物料申请', icon: 'add-o', count: 0 },
      { key: 'return_receive', title: '退料收货', description: '仓库接收生产退料', icon: 'logistics', count: 0 },
      { key: 'return_quality', title: '退料质检', description: '处理质量退料检验', icon: 'shield-o', count: 0 },
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
    const permissions = wx.getStorageSync('erp_permissions') || [];
    const can = code => Array.isArray(permissions) ? permissions.includes(code) : permissions[code] === true;
    const read = (permission, request) => can(permission) ? request() : Promise.resolve(null);
    return Promise.all([
      read('production.task.view', () => production.taskPool({ page: 1, per_page: 20 })),
      read('production.material_delivery.view', () => production.deliveries({ status: 'READY', page: 1, per_page: 20 })),
      read('production.material_delivery.view', () => production.deliveries({ status: 'IN_TRANSIT', page: 1, per_page: 20 })),
      read('production.material_receipt.view', () => production.deliveries({ status: 'DELIVERED', page: 1, per_page: 20 })),
      read('production.handover.view', () => production.pendingHandovers({ page: 1, per_page: 20 })),
      read('production.task.view', () => production.myTasks({ page: 1, per_page: 20 })),
      read('production.material_picking.view', () => production.pickingTasks({ status_group: 'active', page: 1, per_page: 20 })),
      read('production.task.view', () => production.outputs({ status: 'WAIT_QUALITY', page: 1, per_page: 20 })),
      read('production.task.view', () => production.outputs({ status: 'WAIT_WAREHOUSE', page: 1, per_page: 20 })),
      read('production.task.view', () => production.internalIssues({ status: 'WAIT_ISSUE', page: 1, per_page: 20 })),
      read('production.task.view', () => production.internalIssues({ status: 'ISSUED', page: 1, per_page: 20 })),
      read('production.task.view', () => production.supplements({ status: 'SUBMITTED', page: 1, per_page: 20 })),
      read('production.task.view', () => production.materialReturns({ status: 'SUBMITTED', page: 1, per_page: 20 })),
      read('production.task.view', () => production.materialReturns({ status: 'WAIT_QUALITY', page: 1, per_page: 20 })),
    ]).then(([pool, readyDeliveries, transitDeliveries, receipts, handovers, tasks, picking, outputQuality, outputWarehouse, internalDispatch, internalReceive, supplements, returnReceive, returnQuality]) => {
      const counts = {
        pool: pool ? Number(pool.total || 0) : null,
        deliveries: readyDeliveries && transitDeliveries ? Number(readyDeliveries.total || 0) + Number(transitDeliveries.total || 0) : null,
        receipts: receipts ? Number(receipts.total || 0) : null,
        handover: handovers ? Number(handovers.total ?? (handovers.data || []).length) : null,
        kitting: tasks ? (tasks.data || []).filter((task) => (task.target_details || []).some((target) => target.status === 'WAIT_MATERIAL')).length : null,
        picking: picking ? Number(picking.total || 0) : null,
        outputs_quality: outputQuality ? (outputQuality.data || []).filter(row => row.allowed_actions && row.allowed_actions.quality_inspect).length : null,
        outputs_warehouse: outputWarehouse ? (outputWarehouse.data || []).filter(row => row.allowed_actions && row.allowed_actions.warehouse).length : null,
        internal_dispatch: internalDispatch ? (internalDispatch.data || []).filter(row => row.allowed_actions && row.allowed_actions.dispatch).length : null,
        internal_receive: internalReceive ? (internalReceive.data || []).filter(row => row.allowed_actions && row.allowed_actions.receive).length : null,
        supplements: supplements ? (supplements.data || []).filter(row => row.allowed_actions && row.allowed_actions.decide).length : null,
        return_receive: returnReceive ? (returnReceive.data || []).filter(row => row.allowed_actions && row.allowed_actions.receive).length : null,
        return_quality: returnQuality ? (returnQuality.data || []).filter(row => row.allowed_actions && row.allowed_actions.quality).length : null,
      };
      const groups = this.data.groups.map((item) => Object.assign({}, item, { count: counts[item.key] }));
      this.setData({ groups, todoTotal: groups.reduce((sum, item) => sum + Number(item.count || 0), 0), loading: false });
    }).catch(error => {
      this.setData({ loading: false });
      wx.showToast({ title: error.message || '待办加载失败，请下拉重试', icon: 'none', duration: 2500 });
    });
  },
  open(event) { wx.navigateTo({ url: `/pages/production/queue/index?type=${event.currentTarget.dataset.key}` }); },
});
