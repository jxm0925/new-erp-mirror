const production = require('../../../services/production');

function asNumber(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
}

function formatDuration(minutes) {
  const seconds = Math.max(0, Math.round(asNumber(minutes) * 60));
  const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
  const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
  const s = String(seconds % 60).padStart(2, '0');
  return `${h}:${m}:${s}`;
}

Page({
  data: {
    taskId: 0, targetType: 'quantity_operation', targetId: 0,
    loading: true, submitting: false, showConfirm: false,
    task: null, target: null, unitName: '件',
    plannedQty: 0, reportedQty: 0, remainingQty: 0,
    qualifiedQty: 1, unqualifiedQty: 0, scrappedQty: 0, reportQty: 1,
    defectReason: '', remark: '', endLabor: false, laborText: '00:00:00',
    clientCommandId: '',
  },

  onLoad(options) {
    this.setData({
      taskId: Number(options.taskId || 0),
      targetType: options.targetType || 'quantity_operation',
      targetId: Number(options.targetId || 0),
    });
    this.load();
  },

  load() {
    this.setData({ loading: true });
    return production.task(this.data.taskId).then(response => {
      const task = response.data || {};
      const target = (task.target_details || []).find(row =>
        Number(row.target_id) === this.data.targetId && row.target_type === this.data.targetType);
      if (!target) throw new Error('当前任务中不存在该报工目标');
      if (target.target_type !== 'quantity_operation') throw new Error('逐件任务请使用单件完工');
      const remaining = asNumber(target.remaining_base_qty);
      const initial = remaining > 0 ? Math.min(1, remaining) : 0;
      const workOrder = task.work_order || {};
      const unit = workOrder.base_unit || (workOrder.output_item && workOrder.output_item.unit) || {};
      this.setData({
        task, target, loading: false,
        unitName: unit.unit_name || unit.name || '件',
        plannedQty: asNumber(target.planned_base_qty),
        reportedQty: asNumber(target.reported_base_qty),
        remainingQty: remaining,
        qualifiedQty: initial, unqualifiedQty: 0, scrappedQty: 0, reportQty: initial,
        laborText: formatDuration(target.actual_labor_minutes),
      });
    }).catch(error => {
      this.setData({ loading: false });
      wx.showToast({ title: error.message || '报工信息加载失败', icon: 'none' });
    });
  },

  changeTotal(event) {
    const next = Math.max(0, Math.min(this.data.remainingQty, this.data.reportQty + Number(event.currentTarget.dataset.delta || 0)));
    this.setData({ reportQty: next, qualifiedQty: next, unqualifiedQty: 0, scrappedQty: 0 });
  },

  changeKind(event) {
    const kind = event.currentTarget.dataset.kind;
    const delta = Number(event.currentTarget.dataset.delta || 0);
    const field = `${kind}Qty`;
    const current = asNumber(this.data[field]);
    if (delta > 0 && this.data.reportQty >= this.data.remainingQty) return;
    const next = Math.max(0, current + delta);
    const patch = {}; patch[field] = next;
    patch.reportQty = this.data.qualifiedQty + this.data.unqualifiedQty + this.data.scrappedQty - current + next;
    this.setData(patch);
  },

  onDefectReason(event) { this.setData({ defectReason: event.detail.value }); },
  onRemark(event) { this.setData({ remark: event.detail.value }); },
  onEndLabor(event) { this.setData({ endLabor: Boolean(event.detail.value) }); },

  confirmReport() {
    if (this.data.reportQty <= 0) return wx.showToast({ title: '本次报工数量必须大于 0', icon: 'none' });
    if (this.data.reportQty > this.data.remainingQty) return wx.showToast({ title: '本次报工不能超过剩余可报数量', icon: 'none' });
    if ((this.data.unqualifiedQty > 0 || this.data.scrappedQty > 0) && !this.data.defectReason.trim()) {
      return wx.showToast({ title: '存在不良或报废时必须填写原因', icon: 'none' });
    }
    this.setData({ showConfirm: true });
  },

  closeConfirm() { if (!this.data.submitting) this.setData({ showConfirm: false }); },
  noop() {},

  submit() {
    if (this.data.submitting) return;
    const commandId = this.data.clientCommandId || production.newCommandId('production-report');
    this.setData({ submitting: true, clientCommandId: commandId });
    production.report(this.data.taskId, this.data.targetType, this.data.targetId, {
      client_command_id: commandId,
      expected_version: this.data.target.business_version,
      qualified_base_qty: this.data.qualifiedQty,
      unqualified_base_qty: this.data.unqualifiedQty,
      scrapped_base_qty: this.data.scrappedQty,
      defect_reason: this.data.defectReason.trim() || null,
      remark: this.data.remark.trim() || null,
      end_labor: this.data.endLabor,
    }).then(response => {
      const result = response.data || {};
      this.setData({ showConfirm: false, submitting: false, clientCommandId: '' });
      wx.showModal({
        title: '报工已提交',
        content: `报工单号：${result.report_no || '-'}\n剩余可报：${result.remaining_base_qty || 0} ${this.data.unitName}`,
        showCancel: false,
        success: () => wx.navigateBack(),
      });
    }).catch(error => {
      this.setData({ submitting: false });
      if (error.errorCode !== 'network_error' && error.errorCode !== 'request_failed') {
        this.setData({ clientCommandId: '' });
      }
      wx.showToast({ title: error.message || '提交结果尚未确认，请重试查询', icon: 'none', duration: 3000 });
    });
  },
});
