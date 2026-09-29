const warehouse = require('../../../services/warehouse');
const ui = require('../../../utils/warehouse-page');
const logic = require('../../../utils/warehouse-picking');
const { getErpApiBaseUrl } = require('../../../config/erp');
const actions = ['picking.assign', 'picking.start', 'picking.confirm', 'picking.cancel'];
Page({
  data: { mode: 'detail', id: 0, target: null, warehouse: null, task: null, rows: [], page: 1, lastPage: 1, total: 0, loading: false, busy: false, error: '', canCreate: false,
    canAssign: false, canStart: false, canConfirm: false, canCancel: false, canDelivery: false, readOnly: false, checkedCount: 0, selectedDemandCount: 0,
    pickerOpen: false, pickerTitle: '', pickerMode: '', pickerQuery: {}, pickerSelected: [], pickerMultiple: false, pickerMaxCount: 0, pickerQuantity: false,
    cancelOpen: false, cancelReason: '', cancelError: '', pendingAction: '', resultOpen: false, resultMessage: '', conflict: false },
  onLoad(options) {
    this.drafts = {}; this.pickDrafts = {}; this.lineCache = {};
    const id = Number(options.id || 0); const mode = id > 0 ? 'detail' : 'create';
    this.setData({ id, mode, canCreate: ui.permissions()('production.material_picking.create') });
    wx.setNavigationBarTitle({ title: mode === 'create' ? '生产配料' : '配料单详情' });
    if (id > 0) this.load();
    else {
      const saved = wx.getStorageSync(this.draftKey());
      if (saved && saved.target && this.data.canCreate) {
        this.drafts = saved.drafts || {}; this.setData({ target: saved.target, warehouse: saved.warehouse || null }); this.loadDemands();
      }
    }
  },
  draftKey() { const user = wx.getStorageSync('erp_user') || {}; return `erp_warehouse_picking_draft:${encodeURIComponent(getErpApiBaseUrl())}:${Number(user.legacy_id || user.id || 0)}`; },
  saveDraft() {
    // The work-order context survives a lost create response even when its demands disappear.
    // It is isolated by API environment and account, like the shared immutable command.
    if (this.data.mode === 'create') wx.setStorageSync(this.draftKey(), { target: this.data.target, warehouse: this.data.warehouse, drafts: this.drafts });
  },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { Promise.resolve(this.load()).finally(() => wx.stopPullDownRefresh()); },
  findPending() {
    const id = this.data.mode === 'create' ? this.data.target && this.data.target.work_order_id : this.data.id;
    const names = this.data.mode === 'create' ? ['picking.create'] : actions;
    return id ? names.find(action => warehouse.pending(action, id)) || '' : '';
  },
  load() { return this.data.mode === 'create' ? this.loadDemands() : this.loadTask(); },
  loadDemands() {
    const target = this.data.target; if (!target) return Promise.resolve();
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '', pendingAction: this.findPending() });
    const query = { work_order_id: target.work_order_id, target_routing_operation_id: target.target_routing_operation_id,
      production_target_type: target.production_target_type, production_target_id: target.production_target_id, page: this.data.page, per_page: 10 };
    return warehouse.get('production/material-preparation-demands', query).then(response => {
      if (sequence !== this.sequence) return;
      const result = ui.rows(response);
      result.rows.forEach(row => { if (this.drafts[row.id]) this.drafts[row.id].demand = row; });
      if (result.rows.length) this.setData({ target: Object.assign({}, target, { work_order_version: result.rows[0].work_order_version }) });
      this.setData({ rows: result.rows, page: result.page, lastPage: result.lastPage, total: result.total, loading: false }); this.syncDemands();
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: ui.errorText(error), rows: [] }); });
  },
  syncDemands() {
    const rows = this.data.rows.map(row => {
      const draft = this.drafts[row.id]; const sources = draft ? draft.sources : [];
      return Object.assign({}, row, { sources, requiredText: ui.quantity(row.required_qty), remainingText: ui.quantity(row.remaining_to_prepare),
        preparedText: ui.quantity(Math.max(0, Number(row.required_qty) - Number(row.remaining_to_prepare)).toFixed(8)), selectedQty: logic.sumQty(sources.map(source => source.selected_qty)) });
    });
    this.setData({ rows, selectedDemandCount: Object.values(this.drafts).filter(draft => draft.sources.length).length }); this.saveDraft();
  },
  loadTask() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '', pendingAction: this.findPending() });
    return warehouse.get(`production/material-picking-tasks/${this.data.id}`, { line_page: this.data.page, line_per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return;
      const task = response.data || response; const meta = task.line_meta || {}; let changed = false;
      if (this.currentVersion && this.currentVersion !== Number(task.business_version)) { this.pickDrafts = {}; this.lineCache = {}; changed = true; }
      this.currentVersion = Number(task.business_version);
      const allowed = task.allowed_actions || [];
      task.statusText = logic.statuses[task.status] || task.status;
      task.operationText = (task.lines || []).map(line => [line.target_operation_code_snapshot, line.target_operation_name_snapshot].filter(Boolean).join(' - ')).filter((value, index, arr) => value && arr.indexOf(value) === index).join('、') || task.production_location_name_snapshot;
      (task.lines || []).forEach(line => { this.lineCache[line.id] = line; if (this.pickDrafts[line.id]) this.pickDrafts[line.id].line = line; });
      this.setData({ task, rows: task.lines || [], page: Number(meta.current_page || 1), lastPage: Number(meta.last_page || 1), total: Number(meta.total || (task.lines || []).length), loading: false,
        canAssign: allowed.includes('picking.assign'), canStart: allowed.includes('picking.start'), canConfirm: allowed.includes('picking.confirm'), canCancel: allowed.includes('picking.cancel'), canDelivery: allowed.includes('delivery.create'),
        readOnly: !['production.material_picking.assign', 'production.material_picking.pick', 'production.material_picking.cancel', 'production.material_delivery.create'].some(code => ui.permissions()(code)),
        error: changed ? '配料单已更新，请重新核对本次实拣数量和实物' : '' });
      this.syncPicks(); wx.setNavigationBarTitle({ title: allowed.includes('picking.confirm') ? '实拣确认' : '配料单详情' });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, task: null, rows: [], error: ui.errorText(error) }); });
  },
  syncPicks() { this.setData({ rows: this.data.rows.map(line => logic.lineView(line, this.pickDrafts[line.id])), checkedCount: Object.values(this.pickDrafts).filter(draft => logic.validQty(draft.actual_pick_qty)).length }); },
  turnPage(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && page > 0 && page <= this.data.lastPage) { this.setData({ page }); return this.load(); } },
  locked() { return this.data.busy || !!this.data.pendingAction; },
  showPicker(mode, title, query, selected, multiple, quantity, maxCount) {
    if (this.locked()) return;
    this.setData({ pickerOpen: true, pickerMode: mode, pickerTitle: title, pickerQuery: query || {}, pickerSelected: selected || [], pickerMultiple: !!multiple, pickerQuantity: !!quantity, pickerMaxCount: maxCount || 0 });
  },
  chooseTarget() { if (this.data.canCreate) this.showPicker('target', '选择工单和目标工序', {}, this.data.target ? [this.data.target] : [], false); },
  chooseWarehouse() { if (this.data.canCreate) this.showPicker('warehouse', '选择仓库', {}, this.data.warehouse ? [this.data.warehouse] : [], false); },
  chooseSources(event) {
    if (!this.data.warehouse) return this.setData({ error: '请先选择仓库' });
    const id = Number(event.currentTarget.dataset.id); const demand = this.data.rows.find(row => Number(row.id) === id); if (!demand || !this.data.canCreate) return;
    this.activeDemand = demand;
    this.showPicker('stock', '选择库存', { target_material_requirement_id: id, warehouse_id: this.data.warehouse.id }, (this.drafts[id] || {}).sources || [], true, true, 100);
  },
  assign() { if (this.data.canAssign) this.showPicker('people', '选择拣货员', {}, [], false); },
  draftFor(line) { return this.pickDrafts[line.id] || (this.pickDrafts[line.id] = { line, actual_pick_qty: '', physicalRows: [], serialRows: [] }); },
  pickInput(event) {
    if (this.locked() || !this.data.canConfirm) return;
    const line = this.lineCache[event.currentTarget.dataset.id]; if (!line) return;
    const draft = this.draftFor(line); draft.actual_pick_qty = String(event.detail.value);
    if (logic.validQty(draft.actual_pick_qty) && Number(draft.actual_pick_qty) === 0) { draft.physicalRows = []; draft.serialRows = []; }
    this.syncPicks();
  },
  chooseIdentity(event) {
    if (this.locked() || !this.data.canConfirm) return;
    const line = this.lineCache[event.currentTarget.dataset.id]; if (!line) return;
    const draft = this.draftFor(line); const view = logic.lineView(line, draft); const qty = Number(draft.actual_pick_qty);
    if (!logic.validQty(draft.actual_pick_qty) || !Number.isInteger(qty) || qty <= 0) return this.setData({ error: '请先填写大于0的整数实拣数量' });
    this.activeLineId = line.id;
    this.showPicker(view.physical ? 'physical' : 'serial', `${view.physical ? '选择实物' : '选择序列号'}（${view.batchNo || '无批次'}）`,
      { picking_task_id: this.data.id, picking_task_line_id: line.id }, view.physical ? draft.physicalRows : draft.serialRows, true, false, qty);
  },
  pickerConfirm(event) {
    if (this.locked()) return;
    const rows = event.detail.rows || []; const mode = this.data.pickerMode; this.setData({ pickerOpen: false, error: '' });
    if (mode === 'target' && rows[0]) {
      if (!this.data.target || logic.keyOf(this.data.target, 'target') !== logic.keyOf(rows[0], 'target')) { this.drafts = {}; this.sequence = (this.sequence || 0) + 1; this.setData({ rows: [], selectedDemandCount: 0 }); }
      this.setData({ target: rows[0], page: 1 }); this.saveDraft(); return this.loadDemands();
    }
    if (mode === 'warehouse' && rows[0]) { if (!this.data.warehouse || Number(this.data.warehouse.id) !== Number(rows[0].id)) this.drafts = {}; this.setData({ warehouse: rows[0] }); this.syncDemands(); }
    if (mode === 'stock' && this.activeDemand) { this.drafts[this.activeDemand.id] = { demand: this.activeDemand, sources: rows.map(logic.sourceView) }; this.syncDemands(); }
    if (mode === 'people' && rows[0]) return this.execute('picking.assign', { expected_version: this.data.task.business_version, assigned_picker_legacy_id: Number(rows[0].id) });
    if (mode === 'physical' || mode === 'serial') { const draft = this.pickDrafts[this.activeLineId]; if (draft) draft[mode === 'physical' ? 'physicalRows' : 'serialRows'] = rows; this.syncPicks(); }
  },
  closePicker() { this.setData({ pickerOpen: false }); },
  removeSource(event) { if (this.locked()) return; const draft = this.drafts[event.currentTarget.dataset.id]; if (!draft) return; draft.sources = draft.sources.filter(row => Number(row.id) !== Number(event.currentTarget.dataset.source)); this.syncDemands(); },
  submitCreate() {
    if (this.locked() || !this.data.canCreate) return;
    try { return this.execute('picking.create', logic.createPayload(this.data.target, this.data.warehouse && this.data.warehouse.id, this.drafts)); }
    catch (error) { this.setData({ error: ui.errorText(error) }); }
  },
  start() { if (!this.locked() && this.data.canStart) return this.execute('picking.start', { expected_version: this.data.task.business_version }); },
  confirm() {
    if (this.locked() || !this.data.canConfirm) return;
    try { return this.execute('picking.confirm', logic.confirmPayload(this.data.task, this.pickDrafts, this.data.total)); }
    catch (error) { this.setData({ error: ui.errorText(error) }); }
  },
  openCancel() { if (!this.locked() && this.data.canCancel) this.setData({ cancelOpen: true, cancelReason: '', cancelError: '' }); },
  closeCancel() { if (!this.data.busy) this.setData({ cancelOpen: false, cancelReason: '', cancelError: '' }); },
  reasonInput(event) { this.setData({ cancelReason: event.detail.value }); },
  cancel() {
    if (this.locked() || !this.data.canCancel) return;
    if (!this.data.cancelReason.trim()) return this.setData({ cancelError: '请填写取消原因' });
    return this.execute('picking.cancel', { expected_version: this.data.task.business_version, reason: this.data.cancelReason.trim() });
  },
  execute(action, payload) {
    if (this.data.busy) return;
    const id = action === 'picking.create' ? this.data.target.work_order_id : this.data.id;
    this.setData({ busy: true, error: '', resultOpen: false });
    // All retries enter the shared immutable command recovery, never a newly constructed write.
    return warehouse.submit(action, id, payload).then(result => {
      this.setData({ pendingAction: '', cancelOpen: false, cancelReason: '', cancelError: '', conflict: false });
      this.pickDrafts = {}; this.lineCache = {}; this.currentVersion = null;
      if (action === 'picking.create') { wx.removeStorageSync(this.draftKey()); this.drafts = {}; const task = result.data || result; this.setData({ id: Number(task.id), mode: 'detail', page: 1, target: null, warehouse: null }); }
      return this.loadTask();
    }).catch(error => {
      this.setData({ pendingAction: error.pendingCommand ? action : '', resultOpen: !!error.pendingCommand || error.statusCode === 409,
        conflict: error.statusCode === 409 && !error.pendingCommand, resultMessage: ui.errorText(error), error: ui.errorText(error) });
    }).finally(() => this.setData({ busy: false }));
  },
  retry() { const action = this.data.pendingAction || this.findPending(); if (action) return this.execute(action, {}); },
  closeResult() { this.setData({ resultOpen: false }); },
  refreshConflict() { this.setData({ resultOpen: false, conflict: false }); return this.load(); },
  createDelivery() { if (!this.locked() && this.data.canDelivery) wx.navigateTo({ url: `/pages/warehouse/delivery/index?mode=create&picking_id=${this.data.id}` }); },
  back: ui.back, stop() {},
});
