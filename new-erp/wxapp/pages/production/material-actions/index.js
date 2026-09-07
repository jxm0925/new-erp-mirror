const production = require('../../../services/production');

const SUPPLEMENT_REASONS = ['返工追加', '损耗超标', '物料损坏', '工艺异常', '临时追加'];
const RETURN_REASONS = ['生产余料退回', '用料调整', '物料异常', '工序取消', '其他'];

function blankSupplementLine() {
  return { requirementIndex: -1, component_item_id: 0, materialLabel: '', additional_base_qty: '' };
}

function blankReturnLine() {
  return {
    requirementIndex: -1, warehouseIndex: -1, locationIndex: -1,
    material_requirement_id: 0, materialLabel: '', warehouse_id: 0, warehouseLabel: '',
    location_id: 0, locationLabel: '', batch_no: '', received_base_qty: 0,
    returnable_base_qty: 0, return_base_qty: '', warehouseOptions: [], locationOptions: [],
  };
}

function uniqueBy(rows, keyBuilder) {
  const found = {};
  return rows.filter((row) => {
    const key = keyBuilder(row);
    if (found[key]) return false;
    found[key] = true;
    return true;
  });
}

Page({
  data: {
    taskId: 0, targetType: '', targetId: 0, loading: true, busy: false, task: null, target: null,
    activeTab: 'supplement', requirements: [], supplementOptions: [], returnOptions: [],
    supplementTypes: ['生产过程追加'],
    supplementReasons: SUPPLEMENT_REASONS, supplementReasonIndex: 0, blocking: true,
    supplementLines: [blankSupplementLine()], returnType: 'normal_return',
    returnReasons: RETURN_REASONS, returnReasonIndex: -1, returnLines: [blankReturnLine()],
  },

  onLoad(options) {
    this.setData({
      taskId: Number(options.taskId || 0), targetType: String(options.targetType || ''),
      targetId: Number(options.targetId || 0), activeTab: options.tab === 'return' ? 'return' : 'supplement',
    });
    this.load();
  },

  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false });
      wx.showToast({ title: '请先登录统一账号', icon: 'none' });
      return;
    }
    this.setData({ loading: true });
    Promise.all([
      production.task(this.data.taskId),
      production.kittingRequirements(this.data.taskId, this.data.targetType, this.data.targetId),
    ]).then(([taskResponse, requirementResponse]) => {
      const task = taskResponse.data || {};
      const target = (task.target_details || []).find((row) => row.target_type === this.data.targetType && Number(row.target_id) === this.data.targetId);
      if (!target) throw new Error('任务中不存在当前生产目标');
      const requirements = (requirementResponse.data || []).map((row) => Object.assign({}, row, {
        materialLabel: `${row.component_item_code || ''} ${row.component_item_name || ''}`.trim(),
      }));
      const supplementOptions = requirements.filter((row) => row.material_requirement_id).map((row) => ({
        value: row.component_item_id, label: row.materialLabel, requirement: row,
      }));
      const returnOptions = requirements.filter((row) => (row.return_sources || []).length).map((row) => ({
        value: row.material_requirement_id, label: row.materialLabel, requirement: row,
      }));
      this.setData({ task, target, requirements, supplementOptions, returnOptions, loading: false });
    }).catch((error) => {
      this.setData({ loading: false });
      wx.showToast({ title: error.message, icon: 'none', duration: 2600 });
    });
  },

  switchTab(event) { this.setData({ activeTab: event.currentTarget.dataset.tab }); },
  setBlocking(event) { this.setData({ blocking: event.currentTarget.dataset.value === 'true' }); },
  setReturnType(event) { this.setData({ returnType: event.currentTarget.dataset.value }); },
  onSupplementReason(event) { this.setData({ supplementReasonIndex: Number(event.detail.value) }); },
  onReturnReason(event) { this.setData({ returnReasonIndex: Number(event.detail.value) }); },

  onSupplementMaterial(event) {
    const lineIndex = Number(event.currentTarget.dataset.index);
    const requirementIndex = Number(event.detail.value);
    const option = this.data.supplementOptions[requirementIndex];
    const lines = this.data.supplementLines.slice();
    lines[lineIndex] = Object.assign({}, lines[lineIndex], {
      requirementIndex, component_item_id: option.value, materialLabel: option.label,
    });
    this.setData({ supplementLines: lines });
  },
  onSupplementQty(event) {
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ [`supplementLines[${index}].additional_base_qty`]: event.detail.value });
  },
  addSupplementLine() { this.setData({ supplementLines: this.data.supplementLines.concat([blankSupplementLine()]) }); },
  removeSupplementLine(event) {
    if (this.data.supplementLines.length === 1) return wx.showToast({ title: '至少保留一条补料明细', icon: 'none' });
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ supplementLines: this.data.supplementLines.filter((_, rowIndex) => rowIndex !== index) });
  },

  onReturnMaterial(event) {
    const lineIndex = Number(event.currentTarget.dataset.index);
    const requirementIndex = Number(event.detail.value);
    const option = this.data.returnOptions[requirementIndex];
    if (!option) return;
    const sources = option.requirement.return_sources || [];
    const warehouseOptions = uniqueBy(sources, (row) => row.warehouse_id).map((row) => ({
      value: row.warehouse_id, label: `${row.warehouse_code || ''} ${row.warehouse_name || ''}`.trim(),
    }));
    const onlyWarehouse = warehouseOptions.length === 1 ? warehouseOptions[0] : null;
    const warehouseSources = onlyWarehouse
      ? sources.filter((row) => Number(row.warehouse_id) === Number(onlyWarehouse.value))
      : [];
    const locationOptions = uniqueBy(warehouseSources, (row) => row.location_id).map((row) => ({
      value: row.location_id, label: `${row.location_code || ''} ${row.location_name || ''}`.trim(),
    }));
    const onlyLocation = locationOptions.length === 1 ? locationOptions[0] : null;
    const locationSources = onlyLocation
      ? warehouseSources.filter((row) => Number(row.location_id) === Number(onlyLocation.value))
      : [];
    const onlySource = locationSources.length === 1 ? locationSources[0] : null;
    const lines = this.data.returnLines.slice();
    lines[lineIndex] = Object.assign(blankReturnLine(), {
      requirementIndex, material_requirement_id: option.value, materialLabel: option.label,
      received_base_qty: Number(option.requirement.work_order_received_base_qty || 0), warehouseOptions,
      warehouseIndex: onlyWarehouse ? 0 : -1,
      warehouse_id: onlyWarehouse ? onlyWarehouse.value : 0,
      warehouseLabel: onlyWarehouse ? onlyWarehouse.label : '',
      locationOptions,
      locationIndex: onlyLocation ? 0 : -1,
      location_id: onlyLocation ? onlyLocation.value : 0,
      locationLabel: onlyLocation ? onlyLocation.label : '',
      batch_no: onlySource ? (onlySource.batch_no || '') : '',
      returnable_base_qty: onlySource
        ? Number(onlySource.returnable_base_qty || 0)
        : locationSources.reduce((sum, row) => sum + Number(row.returnable_base_qty || 0), 0),
    });
    this.setData({ returnLines: lines });
  },
  onReturnWarehouse(event) {
    const lineIndex = Number(event.currentTarget.dataset.index);
    const warehouseIndex = Number(event.detail.value);
    const line = this.data.returnLines[lineIndex];
    const option = line && line.warehouseOptions[warehouseIndex];
    const returnOption = line && this.data.returnOptions[line.requirementIndex];
    if (!line || !option || !returnOption) return;
    const requirement = returnOption.requirement;
    const sources = (requirement.return_sources || []).filter((row) => Number(row.warehouse_id) === Number(option.value));
    const locationOptions = uniqueBy(sources, (row) => row.location_id).map((row) => ({
      value: row.location_id, label: `${row.location_code || ''} ${row.location_name || ''}`.trim(),
    }));
    this.setData({
      [`returnLines[${lineIndex}].warehouseIndex`]: warehouseIndex,
      [`returnLines[${lineIndex}].warehouse_id`]: option.value,
      [`returnLines[${lineIndex}].warehouseLabel`]: option.label,
      [`returnLines[${lineIndex}].locationIndex`]: -1,
      [`returnLines[${lineIndex}].location_id`]: 0,
      [`returnLines[${lineIndex}].locationLabel`]: '',
      [`returnLines[${lineIndex}].locationOptions`]: locationOptions,
      [`returnLines[${lineIndex}].batch_no`]: '',
      [`returnLines[${lineIndex}].returnable_base_qty`]: 0,
    });
  },
  onReturnLocation(event) {
    const lineIndex = Number(event.currentTarget.dataset.index);
    const locationIndex = Number(event.detail.value);
    const line = this.data.returnLines[lineIndex];
    const option = line && line.locationOptions[locationIndex];
    const returnOption = line && this.data.returnOptions[line.requirementIndex];
    if (!line || !option || !returnOption) return;
    const requirement = returnOption.requirement;
    const sources = (requirement.return_sources || []).filter((row) => Number(row.warehouse_id) === Number(line.warehouse_id) && Number(row.location_id) === Number(option.value));
    const onlySource = sources.length === 1 ? sources[0] : null;
    this.setData({
      [`returnLines[${lineIndex}].locationIndex`]: locationIndex,
      [`returnLines[${lineIndex}].location_id`]: option.value,
      [`returnLines[${lineIndex}].locationLabel`]: option.label,
      [`returnLines[${lineIndex}].batch_no`]: onlySource ? (onlySource.batch_no || '') : '',
      [`returnLines[${lineIndex}].returnable_base_qty`]: onlySource ? Number(onlySource.returnable_base_qty || 0) : sources.reduce((sum, row) => sum + Number(row.returnable_base_qty || 0), 0),
    });
  },
  onReturnBatch(event) {
    const index = Number(event.currentTarget.dataset.index);
    const value = event.detail.value;
    const line = this.data.returnLines[index];
    const option = this.data.returnOptions[line.requirementIndex];
    const source = option && (option.requirement.return_sources || []).find((row) => Number(row.warehouse_id) === Number(line.warehouse_id) && Number(row.location_id) === Number(line.location_id) && String(row.batch_no || '') === String(value || ''));
    this.setData({ [`returnLines[${index}].batch_no`]: value, [`returnLines[${index}].returnable_base_qty`]: source ? Number(source.returnable_base_qty || 0) : 0 });
  },
  onReturnQty(event) { this.setData({ [`returnLines[${Number(event.currentTarget.dataset.index)}].return_base_qty`]: event.detail.value }); },
  addReturnLine() { this.setData({ returnLines: this.data.returnLines.concat([blankReturnLine()]) }); },
  removeReturnLine(event) {
    if (this.data.returnLines.length === 1) return wx.showToast({ title: '至少保留一条退料明细', icon: 'none' });
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ returnLines: this.data.returnLines.filter((_, rowIndex) => rowIndex !== index) });
  },

  submitSupplement() {
    const lines = this.data.supplementLines.map((row) => ({ component_item_id: row.component_item_id, additional_base_qty: Number(row.additional_base_qty) }));
    if (lines.some((row) => !row.component_item_id || !(row.additional_base_qty > 0))) return wx.showToast({ title: '请选择物料并填写正确追加数量', icon: 'none' });
    const itemIds = lines.map((row) => row.component_item_id);
    if (new Set(itemIds).size !== itemIds.length) return wx.showToast({ title: '同一物料不能重复添加', icon: 'none' });
    this.run(() => production.requestSupplement({
      expected_version: this.data.target.business_version, task_id: this.data.taskId,
      target_type: this.data.targetType, target_id: this.data.targetId,
      blocking: this.data.blocking, reason: this.data.supplementReasons[this.data.supplementReasonIndex], lines,
    }), '补料申请已提交');
  },
  submitReturn() {
    if (this.data.returnReasonIndex < 0) return wx.showToast({ title: '请选择退料原因', icon: 'none' });
    const lines = this.data.returnLines.map((row) => ({
      material_requirement_id: row.material_requirement_id, warehouse_id: row.warehouse_id,
      location_id: row.location_id, batch_no: row.batch_no || null, return_base_qty: Number(row.return_base_qty),
    }));
    if (lines.some((row) => !row.material_requirement_id || !row.warehouse_id || !row.location_id || !(row.return_base_qty > 0))) return wx.showToast({ title: '请完整填写退料明细', icon: 'none' });
    if (this.data.returnLines.some((row) => Number(row.return_base_qty) > Number(row.returnable_base_qty))) return wx.showToast({ title: '退料数量不能超过可退数量', icon: 'none' });
    this.run(() => production.requestReturn({
      expected_version: this.data.task.business_version, task_id: this.data.taskId,
      target_type: this.data.targetType, target_id: this.data.targetId,
      return_type: this.data.returnType, reason: this.data.returnReasons[this.data.returnReasonIndex], lines,
    }), '生产退料已提交');
  },
  run(factory, message) {
    if (this.data.busy) return;
    this.setData({ busy: true });
    factory().then(() => {
      wx.showToast({ title: message, icon: 'success' });
      setTimeout(() => wx.navigateBack(), 900);
    }).catch((error) => wx.showToast({ title: error.message, icon: 'none', duration: 2600 }))
      .finally(() => this.setData({ busy: false }));
  },
  switchRoot(event) {
    const url = event.currentTarget.dataset.url;
    if (url === '/pages/production/workbench/index') return wx.redirectTo({ url });
    wx.switchTab({ url });
  },
});
