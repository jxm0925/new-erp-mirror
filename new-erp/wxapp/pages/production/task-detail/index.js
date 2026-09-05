const production = require('../../../services/production');

const LABELS = { WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待开工', IN_PROGRESS: '加工中', PAUSED: '已暂停', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成', CANCELLED: '已取消' };

function stamp(value) { return value ? String(value).replace('T', ' ').slice(0, 19) : '-'; }
function targetView(row) {
  return Object.assign({}, row, {
    statusLabel: LABELS[row.status] || row.status,
    claimedText: stamp(row.claimed_at), kittingText: stamp(row.kitting_confirmed_at),
    startedText: stamp(row.started_at), completedText: stamp(row.completed_at),
    laborText: `${Number(row.actual_labor_minutes || 0).toFixed(1)} 分钟`,
    quantityValue: row.remaining_base_qty || '',
  });
}

Page({
  data: { id: 0, loading: true, busy: false, task: null, targets: [], materials: {}, userId: 0 },
  onLoad(options) { this.setData({ id: Number(options.id || 0), userId: Number((wx.getStorageSync('erp_user') || {}).legacy_id || 0) }); },
  onShow() { if (this.data.id) this.load(); },
  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, task: null });
      wx.showToast({ title: '请先在“我的”登录 ERP', icon: 'none' });
      return Promise.resolve();
    }
    this.setData({ loading: true });
    return production.task(this.data.id).then((response) => {
      const task = response.data || {};
      const item = (task.work_order && task.work_order.output_item) || {};
      task.productName = item.item_name || item.name || '-';
      task.workOrderNo = (task.work_order && task.work_order.work_order_no) || '-';
      task.claimedText = stamp(task.claimed_at);
      this.setData({ task, targets: (task.target_details || []).map(targetView), loading: false });
      this.loadMaterials();
    }).catch((error) => { this.setData({ loading: false }); wx.showToast({ title: error.message, icon: 'none' }); });
  },
  loadMaterials() {
    this.data.targets.forEach((target) => {
      if (!target.kitting_required) return;
      production.kittingRequirements(this.data.id, target.target_type, target.target_id).then((response) => {
        this.setData({ [`materials.${target.target_id}`]: response.data || [] });
      }).catch(() => null);
    });
  },
  claim() { this.run(() => production.claimTask(this.data.id, this.data.task.business_version), '接单成功'); },
  confirmKitting(event) {
    const t = this.findTarget(event);
    const workstationRows = (this.data.materials[t.target_id] || []).filter((row) => row.source_facts && row.source_facts.supply_mode === 'workstation_stock');
    this.collectWorkstationStock(workstationRows, 0, []).then((confirmations) => {
      this.run(() => production.confirmKitting(this.data.id, t.target_type, t.target_id, {
        expected_version: t.business_version,
        workstation_stock_confirmations: confirmations,
      }), '已齐套并开始计时');
    }).catch(() => null);
  },
  collectWorkstationStock(rows, index, result) {
    if (index >= rows.length) return Promise.resolve(result);
    const row = rows[index];
    return new Promise((resolve, reject) => {
      wx.showModal({
        title: `核对工位常备料 ${index + 1}/${rows.length}`,
        content: `${row.component_item_name}，需要 ${row.required_base_qty}。请输入现场可用数量。`,
        editable: true,
        placeholderText: '现场可用数量',
        success: (modal) => {
          if (!modal.confirm) return reject(new Error('cancelled'));
          const quantity = Number(modal.content);
          if (!Number.isFinite(quantity) || quantity < Number(row.required_base_qty)) {
            wx.showToast({ title: '现场数量不足，不能确认齐套', icon: 'none' });
            return reject(new Error('insufficient'));
          }
          resolve(this.collectWorkstationStock(rows, index + 1, result.concat([{
            requirement_id: row.id,
            onsite_available_base_qty: quantity,
          }])));
        },
        fail: reject,
      });
    });
  },
  start(event) { const t = this.findTarget(event); this.run(() => production.start(this.data.id, t.target_type, t.target_id, { expected_version: t.business_version }), '已开始加工'); },
  pause(event) { const t = this.findTarget(event); this.run(() => production.pause(this.data.id, t.target_type, t.target_id, { expected_version: t.business_version }), '已暂停加工'); },
  resume(event) { const t = this.findTarget(event); this.run(() => production.resume(this.data.id, t.target_type, t.target_id, { expected_version: t.business_version }), '已继续加工'); },
  complete(event) {
    const t = this.findTarget(event);
    const payload = { expected_version: t.business_version, disposition: 'direct_handover' };
    if (t.target_type === 'quantity_operation') {
      const qty = Number(t.quantityValue || 0);
      if (qty <= 0) return wx.showToast({ title: '请输入本次完成数量', icon: 'none' });
      payload.completed_base_qty = qty; payload.scrapped_base_qty = 0;
    }
    wx.showModal({ title: '确认完成工序', content: '完成后将生成正式工序产出并推进后续交接或质检。', success: (result) => { if (result.confirm) this.run(() => production.complete(this.data.id, t.target_type, t.target_id, payload), '工序已完成'); } });
  },
  onQuantity(event) { const id = Number(event.currentTarget.dataset.id); this.setData({ targets: this.data.targets.map((row) => row.target_id === id ? Object.assign({}, row, { quantityValue: event.detail.value }) : row) }); },
  openHandover() { wx.navigateTo({ url: '/pages/production/queue/index?type=handover' }); },
  findTarget(event) { return this.data.targets.find((row) => row.target_id === Number(event.currentTarget.dataset.id)); },
  run(factory, message) {
    if (this.data.busy) return;
    this.setData({ busy: true });
    factory().then(() => { wx.showToast({ title: message, icon: 'success' }); return this.load(); })
      .catch((error) => { wx.showToast({ title: error.message, icon: 'none', duration: 2500 }); return this.load(); })
      .finally(() => this.setData({ busy: false }));
  },
});
