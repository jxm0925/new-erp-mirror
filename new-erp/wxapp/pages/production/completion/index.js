const production = require('../../../services/production');

const CHECK_LABELS = {
  labor_ended: '所有工时已结束',
  reports_settled: '报工数量满足',
  material_returns_settled: '余料退回已确认',
  terminal_outputs_ready: '终末产出已就绪',
};

function number(value) {
  const result = Number(value || 0);
  return Number.isFinite(result) ? result : 0;
}

function display(value) {
  const result = number(value);
  return Number.isInteger(result) ? String(result) : result.toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
}

Page({
  data: {
    workOrderId: 0, loading: true, submitting: false, showConfirm: false,
    preflight: null, product: {}, checks: [], outputs: [], selectedOutputs: [],
    unitName: '件', plannedQty: '0', reportedQty: '0', completionQty: '0',
    qualifiedQty: '0', unqualifiedQty: '0', scrappedQty: '0', defectiveQty: '0',
    selectedCount: 0, maxCount: 0, unitMode: false,
    defectReason: '', remark: '', clientCommandId: '',
  },

  onLoad(options) {
    this.setData({ workOrderId: Number(options.workOrderId || 0) });
    this.load();
  },

  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },

  load() {
    if (!this.data.workOrderId) {
      this.setData({ loading: false });
      wx.showToast({ title: '缺少工单信息', icon: 'none' });
      return Promise.resolve();
    }
    this.setData({ loading: true });
    return production.completionPreflight(this.data.workOrderId).then((response) => {
      const preflight = response.data || {};
      const outputs = preflight.terminal_outputs || [];
      const unitMode = preflight.production_execution_mode === 'unit'
        || (outputs.length > 0 && outputs.every(row => row.source_target_type === 'unit_operation'));
      const checks = (preflight.checks || []).map(row => Object.assign({}, row, {
        label: CHECK_LABELS[row.key] || row.key,
        resultText: row.passed ? '通过' : '未通过',
      }));
      this.setData({
        preflight, product: preflight.product || {}, checks, outputs,
        unitName: (preflight.quantity && preflight.quantity.base_unit_name) || '件',
        plannedQty: display(preflight.quantity && preflight.quantity.planned_base_qty),
        reportedQty: display(preflight.quantity && preflight.quantity.reported_base_qty),
        unitMode, maxCount: outputs.length, selectedCount: outputs.length,
        loading: false,
      });
      this.updateSelection(outputs.length);
    }).catch((error) => {
      this.setData({ loading: false });
      wx.showToast({ title: error.message || '完工检查加载失败', icon: 'none', duration: 3000 });
    });
  },

  updateSelection(count) {
    const safeCount = Math.max(0, Math.min(this.data.outputs.length, Number(count || 0)));
    const selected = this.data.outputs.slice(0, safeCount);
    const sum = key => selected.reduce((total, row) => total + number(row[key]), 0);
    const unqualified = sum('unqualified_base_qty');
    const scrapped = sum('scrapped_base_qty');
    this.setData({
      selectedCount: safeCount, selectedOutputs: selected,
      completionQty: display(sum('submitted_base_qty')),
      qualifiedQty: display(sum('qualified_base_qty')),
      unqualifiedQty: display(unqualified), scrappedQty: display(scrapped),
      defectiveQty: display(unqualified + scrapped),
    });
  },

  changeCompletion(event) {
    if (!this.data.unitMode) return;
    this.updateSelection(this.data.selectedCount + Number(event.currentTarget.dataset.delta || 0));
  },

  onDefectReason(event) { this.setData({ defectReason: event.detail.value }); },
  onRemark(event) { this.setData({ remark: event.detail.value }); },

  openConfirm() {
    if (!this.data.preflight || !this.data.preflight.passed) {
      return wx.showToast({ title: '请先处理完工前检查的阻断项', icon: 'none' });
    }
    if (!this.data.selectedOutputs.length || number(this.data.completionQty) <= 0) {
      return wx.showToast({ title: '请选择至少一条可完工产出', icon: 'none' });
    }
    if (number(this.data.defectiveQty) > 0 && !this.data.defectReason.trim()) {
      return wx.showToast({ title: '存在不良或报废时必须填写原因', icon: 'none' });
    }
    this.setData({ showConfirm: true });
  },

  closeConfirm() { if (!this.data.submitting) this.setData({ showConfirm: false }); },
  noop() {},

  submit() {
    if (this.data.submitting) return;
    const commandId = this.data.clientCommandId || production.newCommandId('work-order-completion');
    this.setData({ submitting: true, clientCommandId: commandId });
    production.submitCompletion(this.data.workOrderId, {
      client_command_id: commandId,
      expected_version: this.data.preflight.work_order_business_version,
      output_record_ids: this.data.selectedOutputs.map(row => row.output_record_id),
      defect_reason: this.data.defectReason.trim() || null,
      remark: this.data.remark.trim() || null,
    }).then((response) => {
      const result = response.data || {};
      this.setData({ submitting: false, showConfirm: false, clientCommandId: '' });
      wx.showModal({
        title: '完工已提交',
        content: `完工单号：${result.completion_no || '-'}\n状态：等待电脑端管理人员审核`,
        showCancel: false,
        success: () => wx.navigateBack(),
      });
    }).catch((error) => {
      this.setData({ submitting: false });
      if (error.errorCode !== 'network_error' && error.errorCode !== 'request_failed') {
        this.setData({ clientCommandId: '' });
      }
      wx.showToast({ title: error.message || '提交结果尚未确认，请使用原命令重试', icon: 'none', duration: 3000 });
    });
  },
});
