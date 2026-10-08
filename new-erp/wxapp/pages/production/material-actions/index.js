const production = require('../../../services/production');
const materialSelector = require('../../../components/material-selector/controller');
const warehouse = require('../../../utils/warehouse-page');
const materialWrites = require('../../../utils/material-preparation');

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

Page(Object.assign({}, materialSelector.pageMethods, {
  data: {
    taskId: 0, targetType: '', targetId: 0, loading: true, busy: false, task: null, target: null,
    activeTab: 'supplement',
    onsiteRows: [], onsitePage: 1, onsiteTotal: 0, onsiteLastPage: 1, onsiteLoading: false, onsiteError: '', onsitePending: [],
    onsiteSourceOpen: false, onsiteSourceMode: '', onsiteSourceQuery: {}, onsiteSourceSelected: [], onsiteSourceLineId: 0,
    supplementTypes: ['生产过程追加'],
    supplementReasons: SUPPLEMENT_REASONS, supplementReasonIndex: 0, blocking: true,
    supplementLines: [blankSupplementLine()], returnType: 'normal_return',
    returnReasons: RETURN_REASONS, returnReasonIndex: -1, returnLines: [blankReturnLine()],
  },

  onLoad(options) {
    this.setData({
      taskId: Number(options.taskId || 0), targetType: String(options.targetType || ''),
      targetId: Number(options.targetId || 0), activeTab: ['return', 'onsite'].includes(options.tab) ? options.tab : 'supplement',
      canOnsite: warehouse.permissions()('production.material_receipt.view'), canReceiveOnsite: warehouse.permissions()('production.material_receipt.confirm'),
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
    production.task(this.data.taskId).then((taskResponse) => {
      const task = taskResponse.data || {};
      const target = (task.target_details || []).find((row) => row.target_type === this.data.targetType && Number(row.target_id) === this.data.targetId);
      if (!target) throw new Error('任务中不存在当前生产目标');
      this.setData({ task, target, loading: false });
      if (this.data.activeTab === 'onsite') this.loadOnsite();
    }).catch((error) => {
      this.setData({ loading: false });
      wx.showToast({ title: error.message, icon: 'none', duration: 2600 });
    });
  },

  onUnload() {
    this.onsiteSequence = (this.onsiteSequence || 0) + 1;
    if (this.selectorController) this.selectorController.requestSequence += 1;
  },
  switchTab(event) { this.setData({ activeTab: event.currentTarget.dataset.tab }); if (this.data.activeTab === 'onsite') this.loadOnsite(); },
  loadOnsite() {
    const sequence = this.onsiteSequence = (this.onsiteSequence || 0) + 1;
    this.setData({ onsiteLoading: true, onsiteError: '', onsitePending: materialWrites.pendingList('production/material-picking-tasks/').filter(row => row.path.endsWith('/onsite-receive')) });
    return production.onsiteCollections({ production_target_type: this.data.targetType, production_target_id: this.data.targetId, page: this.data.onsitePage, per_page: 10 }).then(response => {
      if (sequence !== this.onsiteSequence) return;
      const result = warehouse.rows(response);
      this.setData({ onsiteRows: result.rows.map(row => Object.assign({}, row, { accepted: '', physicals: [], serials: [], remainingText: warehouse.quantity(row.remaining_qty) })), onsiteTotal: result.total, onsiteLastPage: result.lastPage, onsiteLoading: false });
    }).catch(error => { if (sequence === this.onsiteSequence) this.setData({ onsiteLoading: false, onsiteError: error.message }); });
  },
  onsitePage(event) { const page = this.data.onsitePage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.onsiteLastPage && !this.data.busy) { this.setData({ onsitePage: page }); this.loadOnsite(); } },
  onsiteQuantity(event) { const index = this.data.onsiteRows.findIndex(row => row.id === Number(event.currentTarget.dataset.id)); if (index >= 0) this.setData({ [`onsiteRows[${index}].accepted`]: event.detail.value }); },
  selectOnsiteSource(event) {
    const row = this.data.onsiteRows.find(row => row.id === Number(event.currentTarget.dataset.id)); const kind = event.currentTarget.dataset.kind;
    if (!row || this.data.busy) return;
    this.setData({ onsiteSourceOpen: true, onsiteSourceLineId: row.id, onsiteSourceMode: `onsite-${kind === 'physicals' ? 'physical' : 'serial'}`, onsiteSourceQuery: { picking_task_line_id: row.id }, onsiteSourceSelected: row[kind] });
  },
  acceptOnsiteSource(event) {
    const index = this.data.onsiteRows.findIndex(row => row.id === this.data.onsiteSourceLineId);
    const kind = this.data.onsiteSourceMode === 'onsite-physical' ? 'physicals' : 'serials';
    if (index >= 0) this.setData({ [`onsiteRows[${index}].${kind}`]: event.detail.rows, [`onsiteRows[${index}].accepted`]: String(event.detail.rows.length) });
    this.closeOnsiteSource();
  },
  closeOnsiteSource() { this.setData({ onsiteSourceOpen: false }); },
  recoverOnsite(event) {
    const pending = this.data.onsitePending.find(row => row.path === event.currentTarget.dataset.path);
    if (!pending || this.data.busy) return Promise.resolve();
    this.setData({ busy: true, onsiteError: '' });
    return materialWrites.submit(pending.path, {}).then(() => { wx.showToast({ title: '已核对上次领料', icon: 'success' }); return this.loadOnsite(); })
      .catch(error => this.setData({ onsiteError: error.message })).finally(() => this.setData({ busy: false }));
  },
  receiveOnsite(event) {
    const row = this.data.onsiteRows.find(row => row.id === Number(event.currentTarget.dataset.id));
    if (!row || this.data.busy || !this.data.canReceiveOnsite) return;
    if (!/^\d+(\.\d{1,8})?$/.test(row.accepted) || Number(row.accepted) <= 0 || Number(row.accepted) > Number(row.remaining_qty)) return wx.showToast({ title: '请填写有效领料数量', icon: 'none' });
    if ((row.material_management_mode === 'physical' && row.physicals.length !== Number(row.accepted)) || (row.serial_control_type !== 'none' && row.serials.length !== Number(row.accepted))) return wx.showToast({ title: '请选择对应实物或序列号', icon: 'none' });
    this.setData({ busy: true, onsiteError: '' });
    production.receiveOnsite(row.task_id, { expected_version: row.task_version, lines: [{ picking_task_line_id: row.id, accepted_qty: row.accepted, physical_material_ids: row.physicals.map(item => item.id), accepted_serial_ids: row.serials.map(item => item.id) }] })
      .then(response => { wx.showToast({ title: response.recoveredCommand ? '已确认上次领料结果' : '现场领料已确认', icon: 'success' }); return this.loadOnsite(); })
      .catch(error => this.setData({ onsiteError: error.message })).finally(() => this.setData({ busy: false }));
  },
  setBlocking(event) { this.setData({ blocking: event.currentTarget.dataset.value === 'true' }); },
  setReturnType(event) { this.setData({ returnType: event.currentTarget.dataset.value }); },
  onSupplementReason(event) { this.setData({ supplementReasonIndex: Number(event.detail.value) }); },
  onReturnReason(event) { this.setData({ returnReasonIndex: Number(event.detail.value) }); },

  openMaterialSelector() {
    const rows = this.data.activeTab === 'return' ? this.data.returnLines : this.data.supplementLines;
    if (!this.selectorController) this.selectorController = materialSelector.create(this);
    this.selectorController.properties = { taskId: this.data.taskId, targetType: this.data.targetType, targetId: this.data.targetId, mode: this.data.activeTab };
    this.selectorController.open(rows.map((row) => row.selection).filter(Boolean));
  },
  onMaterialsSelected(event) {
    const selected = event.detail.rows;
    if (event.detail.mode === 'supplement') {
      const previous = new Map(this.data.supplementLines.filter((row) => row.selection).map((row) => [row.selection.key, row]));
      const lines = selected.map((row) => Object.assign({}, previous.get(row.key) || blankSupplementLine(), {
        selection: row, component_item_id: row.component_item_id, materialLabel: `${row.code} ${row.name}`,
      }));
      this.setData({ supplementLines: lines.length ? lines : [blankSupplementLine()] });
    } else {
      const previous = new Map(this.data.returnLines.filter((row) => row.selection).map((row) => [row.selection.key, row]));
      const lines = selected.map((row) => Object.assign({}, previous.get(row.key) || blankReturnLine(), {
        selection: row, material_requirement_id: row.material_requirement_id, materialLabel: `${row.code} ${row.name}`,
        warehouse_id: row.warehouse_id, warehouseLabel: row.warehouse_name,
        location_id: row.location_id, locationLabel: row.location_name, batch_no: row.batch_no,
        received_base_qty: Number(row.received_base_qty), returnable_base_qty: Number(row.returnable_base_qty),
      }));
      this.setData({ returnLines: lines.length ? lines : [blankReturnLine()] });
    }
  },
  onSupplementQty(event) {
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ [`supplementLines[${index}].additional_base_qty`]: event.detail.value });
  },
  addSupplementLine() { this.openMaterialSelector(); },
  removeSupplementLine(event) {
    if (this.data.supplementLines.length === 1) return wx.showToast({ title: '至少保留一条补料明细', icon: 'none' });
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ supplementLines: this.data.supplementLines.filter((_, rowIndex) => rowIndex !== index) });
  },

  onReturnQty(event) { this.setData({ [`returnLines[${Number(event.currentTarget.dataset.index)}].return_base_qty`]: event.detail.value }); },
  addReturnLine() { this.openMaterialSelector(); },
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
    wx.switchTab({ url });
  },
}));
