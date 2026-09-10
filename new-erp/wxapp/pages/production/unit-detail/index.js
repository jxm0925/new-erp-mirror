const production = require('../../../services/production');

function dateTime(value) {
  return value ? String(value).slice(0, 16).replace('T', ' ') : '—';
}

function elapsed(value) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
  const seconds = Math.max(0, Math.floor(Number(value)));
  const hours = String(Math.floor(seconds / 3600)).padStart(2, '0');
  const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
  const rest = String(seconds % 60).padStart(2, '0');
  return `${hours}:${minutes}:${rest}`;
}

function formatTimelineOperation(op, index) {
  const sequence = Number(op.sequence || (index + 1));
  const status = op.status || 'WAIT_PREVIOUS';
  let statusLabel = op.status_label || '待前序交接';
  let statusPillClass = 'pill-gray';
  let stepCircleClass = 'circle-gray';
  let stepLineClass = 'line-gray';
  let durationLabel = '';
  let durationText = '';
  let time1Label = '预计开始';
  let time1Value = dateTime(op.planned_start_at);
  let time2Label = '预计完成';
  let time2Value = dateTime(op.planned_end_at);

  if (status === 'COMPLETED') {
    statusPillClass = 'pill-green';
    stepCircleClass = 'circle-green';
    stepLineClass = 'line-green';
    durationLabel = '实际用时';
    durationText = op.actual_labor_minutes === null || op.actual_labor_minutes === undefined
      ? '—' : `${Number(op.actual_labor_minutes).toFixed(0)}分钟`;
    time1Label = '开始时间';
    time1Value = dateTime(op.started_at);
    time2Label = '完成时间';
    time2Value = dateTime(op.completed_at);
  } else if (status === 'IN_PROGRESS') {
    statusPillClass = 'pill-blue';
    stepCircleClass = 'circle-blue';
    stepLineClass = 'line-dashed';
    durationLabel = '已用时';
    durationText = elapsed(op.elapsed_seconds);
    time1Label = '开始时间';
    time1Value = dateTime(op.started_at);
  } else if (['REWORK', 'QUALITY_FAILED', 'HANDOVER_REJECTED'].includes(status)) {
    statusPillClass = 'pill-red';
    stepCircleClass = 'circle-gray';
    statusLabel = op.status_label || '异常';
  }

  return {
    id: op.id,
    sequence,
    operation_name: op.operation_name || '—',
    task_no: (op.task || {}).task_no || '—',
    status,
    statusLabel,
    statusPillClass,
    stepCircleClass,
    stepLineClass,
    durationLabel,
    durationText,
    time1Label,
    time1Value,
    time2Label,
    time2Value,
  };
}

function unitStatus(status) {
  if (status === 'COMPLETED') return { label: '已完成', className: 'pill-green' };
  if (status === 'PROCESSING' || status === 'IN_PROGRESS') return { label: '生产中', className: 'pill-green' };
  if (status === 'WAITING') return { label: '待生产', className: 'pill-orange' };
  return { label: '异常', className: 'pill-red' };
}

function statusBadge(value, kind) {
  if (kind === 'kitting') return {
    label: { CONFIRMED: '齐套', PARTIAL: '部分齐套', NOT_CONFIRMED: '未齐套', NOT_REQUIRED: '—' }[value.status] || value.label || '—',
    className: { CONFIRMED: 'badge-green', PARTIAL: 'badge-orange', NOT_CONFIRMED: 'badge-red' }[value.status] || 'badge-gray',
  };
  return {
    label: { RECEIVED: '已交接', WAIT_RECEIVE: '待交接', EXCEPTION: '交接异常', NOT_REQUIRED: '—' }[value.status] || value.label || '—',
    className: { RECEIVED: 'badge-green', WAIT_RECEIVE: 'badge-orange', EXCEPTION: 'badge-red' }[value.status] || 'badge-gray',
  };
}

function emptyUnit(unitNo = '—') {
  return {
    unit_no: unitNo, status: '', statusLabel: '—', statusClass: 'pill-gray', product_name: '—',
    current_operation_name: '—', current_pt_no: '—', kittingLabel: '—', kittingClass: 'badge-gray',
    handoverLabel: '—', handoverClass: 'badge-gray', assignee_name: '—', operations: [],
  };
}

Page({
  data: {
    unitId: 0,
    unitNo: '—',
    workOrderId: 0,
    workOrderNo: '—',
    masterId: 0,
    masterOrderNo: '—',
    loading: true,
    unit: emptyUnit(),
  },

  onLoad(options) {
    const unitId = Number(options.unitId || options.id || 0);
    const unitNo = options.unitNo ? decodeURIComponent(options.unitNo) : '—';
    const workOrderId = Number(options.workOrderId || 0);
    const workOrderNo = options.workOrderNo ? decodeURIComponent(options.workOrderNo) : '—';
    const masterId = Number(options.masterId || 0);
    const masterOrderNo = options.masterOrderNo ? decodeURIComponent(options.masterOrderNo) : '—';
    this.setData({ unitId, unitNo, workOrderId, workOrderNo, masterId, masterOrderNo, unit: emptyUnit(unitNo) });
    if (unitId > 0) this.load();
    else {
      this.setData({ loading: false });
      wx.showToast({ title: '生产单元参数无效', icon: 'none' });
    }
  },

  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },

  load() {
    if (!this.data.unitId) return Promise.resolve();
    this.setData({ loading: true });
    return production.unit(this.data.unitId).then((res) => {
      const unit = res.data || {};
      const execution = unit.execution || {};
      const operation = execution.current_operation || unit.current_operation || {};
      const task = execution.current_task || {};
      const product = unit.product || {};
      const status = unitStatus(unit.status);
      const kitting = statusBadge(execution.kitting || {}, 'kitting');
      const handover = statusBadge(execution.previous_handover || {}, 'handover');
      const operations = (unit.operations || []).map(formatTimelineOperation);
      this.setData({
        workOrderId: (unit.work_order || {}).id || this.data.workOrderId,
        workOrderNo: (unit.work_order || {}).work_order_no || this.data.workOrderNo,
        masterId: (unit.work_order || {}).production_master_order_id || this.data.masterId,
        unit: {
          unit_no: unit.unit_no || '—',
          status: unit.status || '',
          statusLabel: unit.status_label || status.label,
          statusClass: status.className,
          product_name: product.item_name || '—',
          current_operation_name: operation.name || '—',
          current_pt_no: task.task_no || '—',
          kittingLabel: kitting.label,
          kittingClass: kitting.className,
          handoverLabel: handover.label,
          handoverClass: handover.className,
          assignee_name: (task.owner || {}).display_name || '—',
          operations,
        },
        loading: false,
      });
    }).catch((error) => {
      this.setData({ unit: emptyUnit(this.data.unitNo), loading: false });
      wx.showToast({ title: error.message || '生产单元加载失败', icon: 'none' });
    });
  },

  navBackToMaster() {
    const pages = getCurrentPages();
    const masterIdx = pages && pages.findIndex(page => page.route && page.route.includes('master-detail'));
    if (pages && masterIdx >= 0) wx.navigateBack({ delta: pages.length - 1 - masterIdx });
    else if (this.data.masterId) wx.navigateTo({ url: `/pages/production/master-detail/index?id=${this.data.masterId}` });
  },

  navBackToWorkOrder() {
    const pages = getCurrentPages();
    const workOrderIdx = pages && pages.findIndex(page => page.route && page.route.includes('work-order-detail'));
    if (pages && workOrderIdx >= 0) wx.navigateBack({ delta: pages.length - 1 - workOrderIdx });
    else if (this.data.workOrderId) wx.navigateTo({
      url: `/pages/production/work-order-detail/index?masterId=${this.data.masterId}&masterOrderNo=${encodeURIComponent(this.data.masterOrderNo)}&workOrderId=${this.data.workOrderId}`,
    });
  },
});
