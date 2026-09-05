const production = require('../../../services/production');

const TITLES = { pool: '待接任务', collaboration: '我的协同', receipts: '待收料', handover: '待交接', kitting: '待齐套', trace: '扫码追溯' };
const STATUS = { WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待开工', IN_PROGRESS: '加工中', PAUSED: '已暂停', DELIVERED: '待收料' };

Page({
  data: { type: '', title: '生产待办', loading: true, busy: false, rows: [], keyword: '', trace: null },
  onLoad(options) {
    const type = options.type || 'pool';
    this.setData({ type, title: TITLES[type] || '生产待办', keyword: decodeURIComponent(options.keyword || '') });
    wx.setNavigationBarTitle({ title: this.data.title });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, rows: [] });
      wx.showToast({ title: '请先在“我的”登录 ERP', icon: 'none' });
      return Promise.resolve();
    }
    this.setData({ loading: true });
    let promise;
    if (this.data.type === 'pool') promise = production.taskPool({ page: 1, per_page: 50 });
    else if (this.data.type === 'collaboration') promise = production.collaborations({ page: 1, per_page: 50 });
    else if (this.data.type === 'kitting') promise = production.myTasks({ page: 1, per_page: 100 });
    else if (this.data.type === 'receipts') promise = production.deliveries({ status: 'DELIVERED', page: 1, per_page: 50 });
    else if (this.data.type === 'handover') promise = production.pendingHandovers({ page: 1, per_page: 50 });
    else promise = production.trace(this.data.keyword);

    return promise.then((response) => {
      if (this.data.type === 'trace') { this.setData({ trace: response.data || response, loading: false }); return; }
      let rows = response.data || [];
      if (this.data.type === 'kitting') rows = rows.filter((task) => (task.target_details || []).some((target) => target.status === 'WAIT_MATERIAL'));
      rows = rows.map((row) => {
        const target = (row.target_details && row.target_details[0]) || {};
        const workOrder = row.work_order || {};
        return Object.assign({}, row, {
          statusLabel: STATUS[target.status || row.status] || target.status || row.status,
          workOrderNo: workOrder.work_order_no || '-',
          productName: (workOrder.output_item && (workOrder.output_item.item_name || workOrder.output_item.name)) || '-',
          unitLabel: target.production_unit_no || `${(row.target_details || []).length} 个执行目标`,
        });
      });
      this.setData({ rows, loading: false });
    }).catch((error) => { this.setData({ rows: [], loading: false }); wx.showToast({ title: error.message, icon: 'none' }); });
  },
  openTask(event) { wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` }); },
  claim(event) {
    if (this.data.busy) return;
    const task = this.data.rows.find((row) => row.id === Number(event.currentTarget.dataset.id));
    this.setData({ busy: true });
    production.claimTask(task.id, task.business_version).then(() => { wx.showToast({ title: '接单成功', icon: 'success' }); return this.load(); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
  acceptHandover(event) {
    if (this.data.busy) return;
    const row = this.data.rows.find((item) => item.id === Number(event.currentTarget.dataset.id));
    this.setData({ busy: true });
    production.acceptHandover(row.id, { expected_version: row.business_version, completeness: { complete: true } })
      .then(() => { wx.showToast({ title: '交接接收成功', icon: 'success' }); return this.load(); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
  rejectHandover(event) {
    const row = this.data.rows.find((item) => item.id === Number(event.currentTarget.dataset.id));
    wx.showModal({ title: '拒收工序交接', editable: true, placeholderText: '必须填写拒收原因', success: (result) => {
      if (!result.confirm || !String(result.content || '').trim()) return;
      production.rejectHandover(row.id, { expected_version: row.business_version, reason: result.content.trim() })
        .then(() => { wx.showToast({ title: '已拒收并退回返工', icon: 'none' }); this.load(); })
        .catch((error) => wx.showToast({ title: error.message, icon: 'none' }));
    } });
  },
});
