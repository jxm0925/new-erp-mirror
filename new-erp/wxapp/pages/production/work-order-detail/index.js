const production = require('../../../services/production');

const PU_STATUS_MAP = {
  IN_PROGRESS: { label: '生产中', class: 'pill-green' },
  WAIT_MATERIAL: { label: '待齐套', class: 'pill-orange' },
  WAIT_HANDOVER: { label: '待前序交接', class: 'pill-gray' },
  WAITING: { label: '待生产', class: 'pill-orange' },
  COMPLETED: { label: '已完成', class: 'pill-green' },
  EXCEPTION: { label: '异常', class: 'pill-red' },
};

function unitStatus(unit) {
  const execution = unit.execution || {};
  const operation = execution.current_operation || unit.current_operation || {};
  const handover = execution.previous_handover || {};
  if (unit.status === 'COMPLETED') return 'COMPLETED';
  if (['REWORK', 'QUALITY_FAILED', 'HANDOVER_REJECTED'].includes(operation.status)) return 'EXCEPTION';
  if (operation.status === 'WAIT_MATERIAL') return 'WAIT_MATERIAL';
  if (handover.status === 'WAIT_RECEIVE' || ['WAIT_PREVIOUS', 'WAIT_PREDECESSOR', 'WAIT_HANDOVER'].includes(operation.status)) return 'WAIT_HANDOVER';
  if (['PROCESSING', 'IN_PROGRESS'].includes(unit.status) || ['IN_PROGRESS', 'PAUSED', 'WAIT_QUALITY', 'WAIT_WAREHOUSE'].includes(operation.status)) return 'IN_PROGRESS';
  return 'WAITING';
}

function badge(value, kind) {
  if (kind === 'kitting') {
    return {
      label: { CONFIRMED: '齐套', PARTIAL: '部分齐套', NOT_CONFIRMED: '未齐套', NOT_REQUIRED: '—' }[value.status] || value.label || '—',
      className: { CONFIRMED: 'badge-green', PARTIAL: 'badge-orange', NOT_CONFIRMED: 'badge-red' }[value.status] || 'badge-gray',
    };
  }
  return {
    label: { RECEIVED: '已交接', WAIT_RECEIVE: '待交接', EXCEPTION: '交接异常', NOT_REQUIRED: '—' }[value.status] || value.label || '—',
    className: { RECEIVED: 'badge-blue', WAIT_RECEIVE: 'badge-orange', EXCEPTION: 'badge-red' }[value.status] || 'badge-gray',
  };
}

function formatUnit(unit) {
  const execution = unit.execution || {};
  const operation = execution.current_operation || unit.current_operation || {};
  const task = execution.current_task || {};
  const statusKey = unitStatus(unit);
  const status = PU_STATUS_MAP[statusKey] || PU_STATUS_MAP.WAITING;
  const kitting = badge(execution.kitting || {}, 'kitting');
  const handover = badge(execution.previous_handover || {}, 'handover');
  return {
    id: unit.id,
    unit_no: unit.unit_no || '—',
    status: statusKey,
    statusLabel: status.label,
    statusClass: status.class,
    current_operation_name: operation.name || '—',
    seq_text: operation.sequence && operation.total ? `工序 ${operation.sequence}/${operation.total}` : '工序 —',
    current_pt_no: task.task_no || '—',
    kittingLabel: kitting.label,
    kittingClass: kitting.className,
    handoverLabel: handover.label,
    handoverClass: handover.className,
  };
}

function emptyWorkOrder() {
  return { work_order_no: '—', product_name: '—', product_model: '—', target_qty: '—', unit_name: '', product_image: '' };
}

function emptyMetrics() {
  return { planned: 0, completed: 0, in_progress: 0, waiting: 0, exception: 0, tasks_completed: 0, tasks_total: 0 };
}

Page({
  data: {
    masterId: 0,
    masterOrderNo: '—',
    workOrderId: 0,
    loading: true,
    loadingMore: false,
    unitPage: 1,
    unitHasMore: false,
    workOrder: emptyWorkOrder(),
    metrics: emptyMetrics(),
    units: [],
    filteredUnits: [],
    currentFilter: 'all',
    activeFilterLabel: '',
    showFilterModal: false,
    filterOptions: [
      { key: 'all', label: '全部' },
      { key: 'IN_PROGRESS', label: '生产中' },
      { key: 'WAIT_MATERIAL', label: '待齐套' },
      { key: 'WAIT_HANDOVER', label: '待前序交接' },
      { key: 'COMPLETED', label: '已完成' },
    ],
  },

  onLoad(options) {
    const masterId = Number(options.masterId || 0);
    const workOrderId = Number(options.workOrderId || options.id || 0);
    const masterOrderNo = options.masterOrderNo ? decodeURIComponent(options.masterOrderNo) : '—';
    this.setData({ masterId, masterOrderNo, workOrderId });
    if (masterId > 0 && workOrderId > 0) this.load();
    else {
      this.setData({ loading: false });
      wx.showToast({ title: '生产工单参数无效', icon: 'none' });
    }
  },

  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },

  onReachBottom() {
    if (this.data.unitHasMore && !this.data.loadingMore) this.loadMoreUnits();
  },

  load() {
    if (!this.data.workOrderId || !this.data.masterId) return Promise.resolve();
    this.setData({ loading: true, unitPage: 1 });
    return Promise.all([
      production.workOrder(this.data.workOrderId),
      production.masterOrderUnits(this.data.masterId, { work_order_id: this.data.workOrderId, page: 1, per_page: 20 }),
    ]).then(([woRes, unitsRes]) => {
      const woData = woRes.data || {};
      const execution = woData.execution_summary || {};
      const quantity = execution.quantity || {};
      const tasks = execution.tasks || {};
      const product = woData.product || {};
      const formattedUnits = (unitsRes.data || []).map(formatUnit);
      this.setData({
        workOrder: {
          work_order_no: woData.work_order_no || '—',
          product_name: product.item_name || product.name || '—',
          product_model: product.specification || product.item_code || '—',
          target_qty: quantity.planned_qty === undefined ? '—' : quantity.planned_qty,
          unit_name: quantity.unit_name || '',
          product_image: '',
        },
        metrics: {
          planned: Number(quantity.planned_qty || 0),
          completed: Number(quantity.completed_qty || 0),
          in_progress: Number(quantity.in_progress_qty || 0),
          waiting: Number(quantity.waiting_qty || 0),
          exception: Number(quantity.exception_qty || 0),
          tasks_completed: Number(tasks.completed || 0),
          tasks_total: Number(tasks.total || 0),
        },
        units: formattedUnits,
        filteredUnits: this.filterUnits(formattedUnits, this.data.currentFilter),
        unitPage: Number(unitsRes.current_page || 1),
        unitHasMore: Number(unitsRes.current_page || 1) < Number(unitsRes.last_page || 1),
        loading: false,
      });
    }).catch((error) => {
      this.setData({ workOrder: emptyWorkOrder(), metrics: emptyMetrics(), units: [], filteredUnits: [], unitHasMore: false, loading: false });
      wx.showToast({ title: error.message || '生产工单加载失败', icon: 'none' });
    });
  },

  loadMoreUnits() {
    const page = this.data.unitPage + 1;
    this.setData({ loadingMore: true });
    return production.masterOrderUnits(this.data.masterId, { work_order_id: this.data.workOrderId, page, per_page: 20 })
      .then((response) => {
        const units = this.data.units.concat((response.data || []).map(formatUnit));
        this.setData({
          units,
          filteredUnits: this.filterUnits(units, this.data.currentFilter),
          unitPage: Number(response.current_page || page),
          unitHasMore: Number(response.current_page || page) < Number(response.last_page || page),
          loadingMore: false,
        });
      }).catch((error) => {
        this.setData({ loadingMore: false });
        wx.showToast({ title: error.message || '生产单元加载失败', icon: 'none' });
      });
  },

  filterUnits(units, key) {
    return key && key !== 'all' ? units.filter(unit => unit.status === key) : units;
  },

  navBackToMaster() {
    const pages = getCurrentPages();
    if (pages && pages.length > 1) wx.navigateBack();
    else if (this.data.masterId) wx.navigateTo({ url: `/pages/production/master-detail/index?id=${this.data.masterId}` });
  },

  openUnit(event) {
    const unit = event.currentTarget.dataset.unit;
    if (!unit || !unit.id) return;
    wx.navigateTo({
      url: `/pages/production/unit-detail/index?masterId=${this.data.masterId}&masterOrderNo=${encodeURIComponent(this.data.masterOrderNo)}&workOrderId=${this.data.workOrderId}&workOrderNo=${encodeURIComponent(this.data.workOrder.work_order_no)}&unitId=${unit.id}&unitNo=${encodeURIComponent(unit.unit_no)}`,
    });
  },

  openFilterPicker() { this.setData({ showFilterModal: true }); },
  closeFilterPicker() { this.setData({ showFilterModal: false }); },
  stopBubble() {},
  applyFilter(event) {
    const key = event.currentTarget.dataset.key || 'all';
    const label = event.currentTarget.dataset.label || '';
    this.setData({
      currentFilter: key,
      activeFilterLabel: key === 'all' ? '' : label,
      filteredUnits: this.filterUnits(this.data.units, key),
      showFilterModal: false,
    });
  },
});
