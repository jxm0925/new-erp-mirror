const warehouse = require('../../../services/warehouse');
const ui = require('../../../utils/warehouse-page');
const logic = require('../../../utils/warehouse-picking');
const writes = require('../../../utils/material-preparation');
const { getErpApiBaseUrl } = require('../../../config/erp');
const statuses = { WAIT_PICK: '待拣货', PICKING: '拣货中', PREPARED: '已配料', PARTIALLY_PREPARED: '部分配料', CANCELLED: '已取消' };

Page({
  data: { id: 0, view: 'tasks', rows: [], page: 1, lastPage: 1, total: 0, keyword: '', loading: false, busy: false, error: '',
    task: null, warehouse: null, selected: [], selectedCount: 0, pendingCreate: false, pendingAction: '',
    pickerOpen: false, pickerMode: '', pickerTitle: '', pickerQuery: {}, pickerSelected: [], pickerMultiple: false, pickerQuantity: false,
    cancelOpen: false, cancelReason: '' },
  onLoad(options) {
    this.drafts = {}; this.lineCache = {}; this.actuals = {}; this.tracked = {};
    const can = ui.permissions(); const user = wx.getStorageSync('erp_user') || {};
    const saved = wx.getStorageSync(this.draftKey()) || {};
    this.drafts = saved.drafts || {};
    this.setData({ id: Number(options.id || 0), view: can('production.material_requirement.view') ? 'demands' : 'tasks', userId: Number(user.legacy_id || user.id || 0), canViewDemands: can('production.material_requirement.view'), canCreate: can('production.material_picking.create'),
      canPick: can('production.material_picking.pick'), canAssign: can('production.material_picking.assign'), canCancel: can('production.material_picking.cancel'), canProcure: can('production.material_procurement.create'), warehouse: saved.warehouse || null });
    this.syncSelection(); this.load();
  },
  draftKey() { const user = wx.getStorageSync('erp_user') || {}; return `erp_public_material_draft:${encodeURIComponent(getErpApiBaseUrl())}:${Number(user.legacy_id || user.id || 0)}`; },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '', pendingCreate: !!writes.pending('production/public-material-preparations') });
    const path = this.data.id ? `production/public-material-preparations/${this.data.id}` : this.data.view === 'demands' ? 'production/material-preparation-demands' : 'production/public-material-preparations';
    return warehouse.get(path, { page: this.data.page, per_page: 10, keyword: this.data.keyword }).then(response => {
      if (sequence !== this.sequence) return;
      if (this.data.id) {
        const task = response.data || response; const lines = task.lines;
        if (this.version && this.version !== task.business_version) { this.actuals = {}; this.tracked = {}; this.lineCache = {}; }
        this.version = task.business_version;
        lines.data.forEach(line => { this.lineCache[line.id] = line; });
        task.statusText = statuses[task.status] || task.status;
        const pendingAction = ['claim', 'assign', 'start', 'confirm', 'cancel'].find(action => writes.pending(`production/public-material-preparations/${task.id}/${action}`)) || '';
        this.setData({ task, rows: lines.data, total: lines.total, lastPage: lines.last_page, loading: false, pendingAction }); this.syncActuals();
      } else {
        const result = ui.rows(response);
        this.setData({ rows: result.rows.map(row => Object.assign({}, row, { statusText: statuses[row.status], fulfillmentText: row.fulfillment_mode === 'onsite_cutting' ? '现场领料' : '工序配送', remainingText: ui.quantity(row.remaining_to_prepare) })), total: result.total, lastPage: result.lastPage, loading: false });
      }
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: ui.errorText(error) }); });
  },
  switchView(event) { this.setData({ view: event.currentTarget.dataset.view, page: 1, keyword: '' }); this.load(); },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  turnPage(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && page > 0 && page <= this.data.lastPage) { this.setData({ page }); this.load(); } },
  openTask(event) { wx.navigateTo({ url: `/pages/warehouse/public-preparation/index?id=${Number(event.currentTarget.dataset.id)}` }); },
  openChild(event) { wx.navigateTo({ url: `/pages/warehouse/picking/index?id=${Number(event.currentTarget.dataset.id)}&publicDetail=1` }); },
  openProcurement() { wx.navigateTo({ url: '/pages/warehouse/material-procurement/index' }); },
  picker(mode, title, query, selected, multiple, quantity) { if (!this.data.busy) this.setData({ pickerOpen: true, pickerMode: mode, pickerTitle: title, pickerQuery: query || {}, pickerSelected: selected || [], pickerMultiple: !!multiple, pickerQuantity: !!quantity }); },
  chooseWarehouse() { this.picker('warehouse', '选择配料仓库', {}, this.data.warehouse ? [this.data.warehouse] : [], false); },
  chooseStock(event) {
    if (!this.data.warehouse) return wx.showToast({ title: '请先选择仓库', icon: 'none' });
    const row = this.data.rows.find(item => item.id === Number(event.currentTarget.dataset.id)); if (!row) return;
    this.selectedDemand = row;
    this.picker('stock', '选择真实库存来源', { target_material_requirement_id: row.id, warehouse_id: this.data.warehouse.id }, this.drafts[row.id] ? this.drafts[row.id].sources : [], true, true);
  },
  assign() { this.picker('people', '分配拣货人', {}, [], false); },
  chooseTracked(event) {
    const row = this.lineCache[Number(event.currentTarget.dataset.id)]; const kind = event.currentTarget.dataset.kind;
    this.trackedLine = row; this.trackedKind = kind;
    this.picker(kind, kind === 'physical' ? '选择实际板材' : '选择序列号', { picking_task_id: row.task_id, picking_task_line_id: row.id }, (this.tracked[row.id] || {})[kind] || [], true);
  },
  pickerConfirm(event) {
    const rows = event.detail.rows; const mode = this.data.pickerMode;
    this.closePicker();
    if (mode === 'people') return this.action('assign', { assigned_picker_legacy_id: rows[0].id });
    if (mode === 'warehouse') {
      const apply = () => { if (this.data.warehouse && this.data.warehouse.id !== rows[0].id) this.drafts = {}; this.setData({ warehouse: rows[0] }); this.syncSelection(); };
      if (this.data.warehouse && this.data.warehouse.id !== rows[0].id && this.data.selectedCount) return wx.showModal({ title: '更换仓库', content: '更换仓库会清除已选库存来源。', success: result => { if (result.confirm) apply(); } });
      return apply();
    }
    if (mode === 'stock') { this.drafts[this.selectedDemand.id] = { demand: this.selectedDemand, sources: rows }; return this.syncSelection(); }
    if (!this.tracked[this.trackedLine.id]) this.tracked[this.trackedLine.id] = {};
    this.tracked[this.trackedLine.id][mode] = rows; this.actuals[this.trackedLine.id] = String(rows.length); this.syncActuals();
  },
  closePicker() { this.setData({ pickerOpen: false }); },
  syncSelection() { const selected = Object.values(this.drafts).filter(row => row.sources.length).map(row => ({ id: row.demand.id, label: `${row.demand.work_order_no} · ${row.demand.item_name}`, quantity: logic.sumQty(row.sources.map(source => source.selected_qty)) })); this.setData({ selected, selectedCount: selected.length }); wx.setStorageSync(this.draftKey(), { drafts: this.drafts, warehouse: this.data.warehouse }); },
  removeSelection(event) { delete this.drafts[event.currentTarget.dataset.id]; this.syncSelection(); },
  create() {
    if (this.data.pendingCreate) return this.run('production/public-material-preparations', {}, response => this.created(response));
    try {
      if (!this.data.warehouse) throw new Error('请选择配料仓库');
      const lines = []; const versions = {};
      Object.values(this.drafts).forEach(row => {
        if (Number(logic.sumQty(row.sources.map(source => source.selected_qty))) > Number(row.demand.remaining_to_prepare)) throw new Error('本次配料超过对应待配需求');
        versions[row.demand.work_order_id] = row.demand.work_order_version;
        row.sources.forEach(source => { if (!logic.validQty(source.selected_qty) || Number(source.selected_qty) <= 0) throw new Error('请填写有效配料数量'); lines.push({ target_material_requirement_id: row.demand.id, inventory_balance_id: source.id, planned_pick_qty: source.selected_qty }); });
      });
      if (!lines.length || lines.length > 100) throw new Error('单次配料请选择1至100条库存来源');
      this.run('production/public-material-preparations', { warehouse_id: this.data.warehouse.id, work_order_versions: versions, lines }, response => this.created(response));
    } catch (error) { this.setData({ error: error.message }); }
  },
  created(response) { this.drafts = {}; this.syncSelection(); this.setData({ id: response.data.id, page: 1, task: null }); return this.load(); },
  quantityInput(event) { this.actuals[event.currentTarget.dataset.id] = String(event.detail.value); },
  syncActuals() { this.setData({ rows: this.data.rows.map(row => Object.assign({}, row, { actualInput: this.actuals[row.id] || '', physicalCount: ((this.tracked[row.id] || {}).physical || []).length, serialCount: ((this.tracked[row.id] || {}).serial || []).length, fulfillmentText: row.fulfillment_mode_snapshot === 'onsite_cutting' ? '现场领料' : '工序配送' })) }); },
  action(action, extra) {
    const task = this.data.task; const body = Object.assign({ expected_version: task.business_version, child_versions: Object.fromEntries(task.children.map(row => [row.id, row.business_version])) }, extra || {});
    return this.run(`production/public-material-preparations/${task.id}/${action}`, body, () => this.load());
  },
  simpleAction(event) { return this.action(event.currentTarget.dataset.action); },
  recoverAction() { if (this.data.pendingAction) return this.action(this.data.pendingAction); },
  confirm() {
    const lines = Object.entries(this.actuals).map(([id, qty]) => {
      const row = this.lineCache[id]; const selected = this.tracked[id] || {};
      return { picking_task_line_id: Number(id), actual_pick_qty: qty, serial_ids: (selected.serial || []).map(item => item.id), physical_material_ids: (selected.physical || []).map(item => item.id), maximum: row.planned_pick_qty };
    });
    if (lines.length !== this.data.total || lines.some(row => !logic.validQty(row.actual_pick_qty) || Number(row.actual_pick_qty) > Number(row.maximum)) || !lines.some(row => Number(row.actual_pick_qty) > 0)) return this.setData({ error: '请核对各页并逐条填写实拣数量，未拣填写0；至少有一条大于0。' });
    return this.action('confirm', { lines: lines.map(({ maximum, ...line }) => line) });
  },
  openCancel() { this.setData({ cancelOpen: true, cancelReason: '' }); },
  cancelInput(event) { this.setData({ cancelReason: event.detail.value }); },
  closeCancel() { if (!this.data.busy) this.setData({ cancelOpen: false }); },
  cancel() { const reason = this.data.cancelReason.trim(); if (!reason) return this.setData({ error: '请填写取消原因' }); return this.action('cancel', { reason }).then(() => this.setData({ cancelOpen: false })); },
  run(path, body, success) {
    if (this.data.busy) return Promise.resolve(); this.setData({ busy: true, error: '' });
    return writes.submit(path, body).then(response => { wx.showToast({ title: response.recoveredCommand ? '已核对上次提交' : '操作成功', icon: 'success' }); return success(response); })
      .catch(error => this.setData({ error: error.message })).finally(() => { this.setData({ busy: false, pendingCreate: !!writes.pending('production/public-material-preparations') }); });
  },
  stop() {},
});
