const production = require('../../../services/production');
const cutting = require('../../../services/cutting');
const util = require('../../../utils/util');

const EMPTY_OVERVIEW = {
  total: '—', running: '—', waiting: '—', completed: '—', exception: '—', completionRate: '—'
};

function trendTicksFor(trend) {
  const peak = (trend || []).reduce((value, item) => Math.max(value, Number(item.activity || 0)), 0);
  if (peak <= 4) {
    const top = Math.max(1, Math.ceil(peak));
    return Array.from({ length: top + 1 }, (_, index) => top - index);
  }

  const rawStep = peak / 4;
  const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
  const normalized = rawStep / magnitude;
  const niceFactor = [1, 2, 2.5, 5, 10].find(candidate => normalized <= candidate) || 10;
  const step = Math.max(1, Math.ceil(niceFactor * magnitude));
  const top = Math.ceil(peak / step) * step;
  return Array.from({ length: top / step + 1 }, (_, index) => top - index * step);
}

function permissionsReader() {
  const permissions = wx.getStorageSync('erp_permissions') || [];
  return code => Array.isArray(permissions) ? permissions.includes(code) : permissions[code] === true;
}

Page({
  data: {
    loading: true,
    loaded: false,
    authenticated: false,
    overview: EMPTY_OVERVIEW,
    trend: [],
    trendTicks: [],
    shortcuts: [
      { key: 'orders', title: '生产工单', subtitle: '查看整体进度', icon: 'orders-o', count: null },
      { key: 'warehouse', title: '仓库管理', subtitle: '入库 / 发料 / 退料', icon: 'home-o', count: null },
      { key: 'picking', title: '配料订单', subtitle: '配送 / 领料 / 签收', icon: 'cluster-o', count: null },
      { key: 'tasks', title: '我的任务', subtitle: '接单 / 齐套 / 开工', icon: 'records', count: null },
      { key: 'cutting', title: '下料', subtitle: '自主下料 / 记录 / 流转', icon: 'coupon-o', count: null }
    ]
  },

  onReady() { this.drawTrendChart(); },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onUnload() { this.requestSequence = (this.requestSequence || 0) + 1; },

  load() {
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ authenticated: false, loaded: false, loading: false, overview: EMPTY_OVERVIEW, trend: [], trendTicks: [] });
      return Promise.resolve();
    }

    const can = permissionsReader();
    const optional = (allowed, request) => allowed ? request().catch(() => null) : Promise.resolve(null);
    this.setData({
      authenticated: true,
      loading: true,
      loaded: false,
      overview: EMPTY_OVERVIEW,
      trend: [],
      trendTicks: [],
      shortcuts: this.data.shortcuts.map(item => Object.assign({}, item, { count: null }))
    });

    return Promise.all([
      production.workbenchSummary(),
      optional(can('production.work_order.view'), () => production.masterOrders({ page: 1, per_page: 1 })),
      optional(can('production.task.view'), () => production.outputs({ status: 'WAIT_WAREHOUSE', page: 1, per_page: 1 })),
      optional(can('production.material_picking.view'), () => production.pickingTasks({ status_group: 'active', page: 1, per_page: 1 })),
      optional(can('production.cutting.view'), () => cutting.listTasks({ scope: 'mine', status_group: 'active', page: 1, per_page: 1 }))
    ]).then(([summaryResponse, orders, warehouse, picking, cuttingTasks]) => {
      if (sequence !== this.requestSequence) return;
      const summary = summaryResponse.data || {};
      const total = Number(summary.total || 0);
      const trend = (Array.isArray(summary.trend) ? summary.trend : []).map(item => Object.assign({}, item, {
        activity: Math.max(Number(item.assigned || 0), Number(item.completed || 0))
      }));
      const countByKey = {
        orders: orders ? Number(orders.total || 0) : null,
        warehouse: warehouse ? Number(warehouse.total || 0) : null,
        picking: picking ? Number(picking.total || 0) : null,
        tasks: total,
        cutting: cuttingTasks ? Number((cuttingTasks.meta || {}).total || 0) : null
      };
      this.setData({
        overview: {
          total,
          running: Number(summary.running || 0),
          waiting: Number(summary.waiting || 0),
          completed: Number(summary.completed || 0),
          exception: Number(summary.exception || 0),
          completionRate: `${Number(summary.completion_rate || 0).toFixed(1).replace(/\.0$/, '')}%`
        },
        trend,
        trendTicks: trendTicksFor(trend),
        shortcuts: this.data.shortcuts.map(item => Object.assign({}, item, { count: countByKey[item.key] })),
        loading: false,
        loaded: true
      });
      this.scheduleChartDraw();
    }).catch(error => {
      if (sequence !== this.requestSequence) return;
      this.setData({ loading: false, loaded: false, overview: EMPTY_OVERVIEW, trend: [], trendTicks: [] });
      wx.showToast({ title: error.message || '生产概况加载失败，请下拉重试', icon: 'none' });
    });
  },

  scheduleChartDraw() {
    if (typeof wx.nextTick === 'function') wx.nextTick(() => this.drawTrendChart());
    else this.drawTrendChart();
  },

  drawTrendChart() {
    if (!this.data.trend.length || typeof wx.createSelectorQuery !== 'function' || typeof wx.createCanvasContext !== 'function') return;
    wx.createSelectorQuery().in(this).select('#trendCanvas').boundingClientRect(rect => {
      if (!rect || !rect.width || !rect.height) return;
      const context = wx.createCanvasContext('trendChart', this);
      const width = rect.width;
      const height = rect.height;
      const values = this.data.trend.map(item => Number(item.activity || 0));
      const maxValue = Number(this.data.trendTicks[0]) || Math.max(1, ...values);
      const gap = width / this.data.trend.length;
      const top = 8;
      const bottom = height - 4;
      const plotHeight = bottom - top;

      context.setStrokeStyle('#e6eaf0');
      context.setLineWidth(1);
      context.setLineDash([3, 3], 0);
      this.data.trendTicks.forEach(tick => {
        const y = top + plotHeight * (1 - Number(tick) / maxValue);
        context.beginPath(); context.moveTo(0, y); context.lineTo(width, y); context.stroke();
      });
      context.setLineDash([], 0);

      const points = [];
      this.data.trend.forEach((item, index) => {
        const x = gap * index + gap / 2;
        const assignedValue = Number(item.assigned || 0);
        const completedValue = Number(item.completed || 0);
        const activityValue = Number(item.activity || 0);
        const assignedHeight = assignedValue > 0 ? Math.max(3, assignedValue / maxValue * plotHeight) : 0;
        const completedHeight = completedValue > 0 ? Math.max(2, completedValue / maxValue * plotHeight) : 0;
        const activityHeight = activityValue > 0 ? Math.max(2, activityValue / maxValue * plotHeight) : 0;
        const barWidth = Math.min(20, gap * 0.44);
        context.setFillStyle('rgba(232, 43, 25, 0.14)');
        context.fillRect(x - barWidth / 2, bottom - assignedHeight, barWidth, assignedHeight);
        context.setFillStyle('rgba(232, 43, 25, 0.48)');
        context.fillRect(x - barWidth / 2, bottom - completedHeight, barWidth, completedHeight);
        points.push([x, bottom - activityHeight]);
      });

      context.setStrokeStyle('#e52613');
      context.setLineWidth(2);
      context.beginPath();
      points.forEach((point, index) => index ? context.lineTo(point[0], point[1]) : context.moveTo(point[0], point[1]));
      context.stroke();
      points.forEach(point => {
        context.setFillStyle('#ffffff'); context.beginPath(); context.arc(point[0], point[1], 4, 0, Math.PI * 2); context.fill();
        context.setFillStyle('#e52613'); context.beginPath(); context.arc(point[0], point[1], 2.5, 0, Math.PI * 2); context.fill();
      });
      context.draw();
    }).exec();
  },

  openShortcut(event) {
    const key = event.currentTarget.dataset.key;
    if (key === 'orders') return wx.navigateTo({ url: '/pages/production/tasks/index' });
    if (key === 'tasks') return wx.navigateTo({ url: '/pages/production/my-tasks/index' });
    if (key === 'cutting') return wx.navigateTo({ url: '/pages/production/cutting-tasks/index' });
    if (key === 'picking') return wx.navigateTo({ url: '/pages/production/queue/index?type=picking' });
    if (key === 'warehouse') {
      wx.showActionSheet({
        itemList: ['生产入库', '半成品发料', '生产退料收货'],
        success: result => {
          const types = ['outputs_warehouse', 'internal_dispatch', 'return_receive'];
          wx.navigateTo({ url: `/pages/production/queue/index?type=${types[result.tapIndex]}` });
        }
      });
    }
  },

  goTasksWithFilter(event) {
    wx.navigateTo({ url: `/pages/production/my-tasks/index?execution_filter=${event.currentTarget.dataset.filter || 'all'}` });
  },

  openLogin() { util.BadgePopup(); }
});
