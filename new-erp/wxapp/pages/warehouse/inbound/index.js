const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const LABELS = { confirm: '确认入库', reject: '驳回', cancel: '撤回申请', scrap: '报废余料', exception: '登记异常', resubmit: '更正后重新提交' };
const TITLES = { mfg_receipt: '生产入库确认', cutting_remnant: '余料入库确认', inventory_transfer: '调拨入库确认', material_physical_transfer: '实物调拨接收' };
Page({
  data: { kind: 'mfg_receipt', id: 0, loading: true, saving: false, error: '', formError: '', header: { serial_nos: [], allowed_location_purposes: [] }, lines: [], actions: {},
    page: 1, lastPage: 1, total: 0, checked: {}, checkedCount: 0, checkedPhysicals: {}, drafts: {}, resubmitting: false, locatorTarget: '', locator: {}, locatorOpen: false, remark: '', dialog: null, pendingActions: [], canViewAmount: false, isRemnant: false, isTransfer: false, isPhysical: false, confirmReady: false,
    manualOpen: false, manualRows: [], manualSelected: [], manualScope: '' },
  onLoad(options) { const kind = Object.keys(TITLES).includes(options.kind) ? options.kind : 'mfg_receipt'; this.setData({ id: Number(options.id), kind, isRemnant: kind === 'cutting_remnant', isTransfer: ['inventory_transfer', 'material_physical_transfer'].includes(kind), isPhysical: kind === 'material_physical_transfer' }); },
  onShow() { if (!page.resumingScan(this)) this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    if (!this.data.id) { this.setData({ loading: false, error: '入库单不存在' }); return Promise.resolve(); }
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '', manualOpen: false });
    return warehouse.document(this.data.kind, this.data.id, { page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return;
      const body = response.data || response; const header = body.header || {}; const result = page.rows(body.lines);
      Object.keys(header).filter(key => key.endsWith('_at')).forEach(key => { header[key] = page.dateTime(header[key]); });
      const changed = this.version && this.version !== Number(header.business_version);
      if (changed) this.setData({ checked: {}, checkedCount: 0, checkedPhysicals: {}, drafts: {}, locator: {}, formError: '单据已变化，请重新核对库位和实物' });
      this.version = Number(header.business_version);
      const actions = {}; (body.actions || []).forEach(action => { actions[action.split('.')[1]] = true; });
      if (header.can_resubmit) actions.resubmit = true;
      const locator = this.data.locator; const drafts = Object.assign({}, this.data.drafts);
      (header.confirmation_lines || []).forEach(row => { const key = this.data.isRemnant ? row.source_key : String(row.id); if (!drafts[key]) drafts[key] = { measure_qty: page.quantity(row.measure_qty), location_id: null, location_code: '', warehouse_id: this.data.isTransfer ? header.target_warehouse_id : null }; });
      const canViewAmount = page.permissions()('inventory.amount.view');
      this.setData({ header: Object.assign({}, header, { quantityText: page.quantity(header.qty),
        number: header.receipt_no || header.transfer_no, status_label: header.status_label || header.status,
        sourceWarehouseName: header.source_warehouse_name || (header.source_warehouse || {}).warehouse_name,
        sourceLocationCode: header.source_location_code || (header.source_location || {}).location_code,
        targetWarehouseName: header.target_warehouse_name || (header.target_warehouse || {}).warehouse_name,
        costText: header.cost_status === 'pending_confirmation' ? '待仓库确认' : page.money(header.cost_amount),
        sourceText: header.confirmation_source === 'auto_handoff' ? '系统交接' : header.confirmation_source === 'legacy_posted' ? '历史已入库' : '' }),
        actions, drafts, lines: result.rows.map(row => Object.assign({}, row, { rowKey: row.source_key || String(row.id), measureUnitText: row.measure_unit === 'm2' ? 'm²' : '米' })), loading: false, page: result.page, lastPage: result.lastPage, total: result.total, canViewAmount,
        locator, pendingActions: Object.keys(LABELS).filter(action => warehouse.pending(this.data.kind + '.' + action, this.data.id)).concat(this.data.isRemnant && warehouse.pending('cutting_batch.request', header.cutting_batch_id) ? ['resubmit'] : []) });
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.load(); }
      this.updateReady(); wx.setNavigationBarTitle({ title: TITLES[this.data.kind] });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  turn(event) {
    const target = this.data.page + Number(event.currentTarget.dataset.delta);
    if (this.data.saving || target < 1 || target > this.data.lastPage) return;
    this.setData({ page: target }); this.load();
  },
  input(event) { this.setData({ remark: event.detail.value }); },
  openManual() {
    if (this.data.loading || this.data.saving || this.data.pendingActions.length || !this.data.actions.confirm) return;
    const rows = (this.data.header.serial_nos || []).map(no => ({ id: 'serial:' + no, title: no, subtitle: this.data.header.item_name || '', detail: '本次入库申请编号', serial_no: no, kind: 'serial' }));
    if (this.data.isPhysical) (this.data.header.confirmation_lines || []).forEach(row => rows.push({ id: 'physical:' + row.physical_material_id, title: row.item_name || (row.item || {}).item_name || row.physical_no, physical_no: row.physical_no, detail: row.physical_no, dimensions: row.dimensions, physical_id: row.physical_material_id, kind: 'physical' }));
    this.setData({ manualRows: rows, manualSelected: rows.filter(row => row.kind === 'serial' ? this.data.checked[row.serial_no] : this.data.checkedPhysicals[row.physical_id]), manualOpen: true, manualScope: [this.data.kind, this.data.id, this.data.header.business_version].join(':') });
  },
  closeManual() { this.setData({ manualOpen: false }); },
  confirmManual(event) {
    if (!this.data.manualOpen || this.data.loading || this.data.saving || this.data.pendingActions.length || !this.data.actions.confirm) return;
    if (this.data.manualScope !== [this.data.kind, this.data.id, this.data.header.business_version].join(':')) return this.setData({ manualOpen: false, formError: '单据已变化，请重新核对' });
    const checked = {}; const checkedPhysicals = {};
    for (const row of event.detail.rows) {
      if (row.kind === 'serial' && (this.data.header.serial_nos || []).includes(row.serial_no)) checked[row.serial_no] = true;
      else if (row.kind === 'physical' && (this.data.header.physical_ids || []).some(id => Number(id) === Number(row.physical_id))) checkedPhysicals[row.physical_id] = true;
      else return this.setData({ formError: '所选编号不属于本单，请重新核对', manualOpen: false });
    }
    this.setData({ checked, checkedCount: Object.keys(checked).length, checkedPhysicals, manualOpen: false, formError: '' }); this.updateReady();
  },
  scanSerial(event) {
    if (this.data.saving || this.data.pendingActions.length || !this.data.actions.confirm) return;
    const code = String(event.detail.code || '').trim().replace(/^ERP:SN:/i, '');
    if (!(this.data.header.serial_nos || []).includes(code)) return this.setData({ formError: '该序列号不属于本次入库申请' });
    if (this.data.checked[code]) return this.setData({ formError: '该序列号已核对，请继续扫描其他实物' });
    const checked = Object.assign({}, this.data.checked, { [code]: true });
    this.setData({ checked, checkedCount: Object.keys(checked).length, formError: '' });
    this.updateReady();
  },
  scanPhysical(event) {
    if (this.data.saving || this.data.pendingActions.length) return;
    const code = String(event.detail.code || '').trim().replace(/^ERP:PHY:/i, ''); const rows = this.data.header.confirmation_lines || [];
    const row = rows.find(row => String(row.physical_material_id) === code || row.physical_no === code);
    if (!row) return this.setData({ formError: '该实物不属于本次调拨' });
    const checkedPhysicals = Object.assign({}, this.data.checkedPhysicals, { [row.physical_material_id]: true }); this.setData({ checkedPhysicals, formError: '' }); this.updateReady();
  },
  measureInput(event) { const key = event.currentTarget.dataset.key; this.setData({ ['drafts.' + key + '.measure_qty']: event.detail.value }); this.updateReady(); },
  updateReady() {
    const all = this.data.header.confirmation_lines || []; let ready = false;
    if (this.data.isRemnant) ready = all.length > 0 && all.every(row => { const draft = this.data.drafts[row.source_key] || {}; return /^\d+(\.\d{1,8})?$/.test(draft.measure_qty || '') && Number(draft.measure_qty) <= Number(row.max_remnant_measure) && (Number(draft.measure_qty) === 0 || draft.location_id); });
    else if (this.data.isTransfer) ready = all.length > 0 && (this.data.isPhysical ? Boolean(this.data.locator.location_id) && (this.data.header.physical_ids || []).every(id => this.data.checkedPhysicals[id]) : all.every(row => (this.data.drafts[String(row.id)] || {}).location_id)) && (!this.data.header.requires_serial_check || this.data.checkedCount === (this.data.header.serial_nos || []).length);
    else ready = Boolean(this.data.locator.location_id) && (!this.data.header.requires_serial_check || this.data.checkedCount === (this.data.header.serial_nos || []).length);
    this.setData({ confirmReady: !!ready });
  },
  async scanLocator(event) {
    if (this.data.saving || this.data.pendingActions.length) return;
    const result = event.detail.result; const purposes = this.data.header.allowed_location_purposes || []; const sequence = this.sequence;
    if (this.data.isTransfer && Number(result.warehouse_id) !== Number(this.data.header.target_warehouse_id)) return this.setData({ formError: '请在原目标仓库内选择接收库位' });
    try {
      const response = await warehouse.locators({ mode: 'location', warehouse_id: result.warehouse_id, id: result.location_id,
        location_purposes: purposes, page: 1, per_page: 10 });
      const row = page.rows(response).rows.find(row => Number(row.id) === Number(result.location_id));
      if (sequence !== this.sequence) return;
      if (!row) throw new Error('该库位不适用于当前入库，请重新选择');
      this.setLocator({ warehouse_id: result.warehouse_id, warehouse_name: result.warehouse_name, location_id: row.id, location_code: row.code, location_name: row.name }, event.currentTarget.dataset.key || '');
    } catch (error) { this.setData({ formError: page.errorText(error) }); }
  },
  locationInput(event) { if (event.detail.value !== this.data.locator.location_code) { this.setData({ locator: {} }); this.updateReady(); } },
  startResubmit() { if (!this.data.saving && !this.data.pendingActions.length) this.setData({ resubmitting: true }); },
  setLocator(locator, key) { if (key) this.setData({ ['drafts.' + key]: Object.assign({}, this.data.drafts[key], locator), formError: '' }); else this.setData({ locator, formError: '' }); this.updateReady(); },
  openLocator(event) { if (!this.data.saving && !this.data.pendingActions.length) this.setData({ locatorTarget: event.currentTarget.dataset.key || '', locatorOpen: true }); },
  closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) { this.setLocator(event.detail, this.data.locatorTarget); this.setData({ locatorOpen: false }); },
  requestAction(event) {
    if (this.data.saving || this.data.pendingActions.length) return;
    const action = event.currentTarget.dataset.action;
    if (!this.data.actions[action]) return;
    if (action === 'confirm') {
      if (!this.data.confirmReady) return this.setData({ formError: '请核对全部明细并选择实际接收库位' });
      if (this.data.header.requires_serial_check && this.data.checkedCount !== (this.data.header.serial_nos || []).length)
        return this.setData({ formError: '请逐个核对本单全部序列号' });
    }
    const facts = action === 'scrap' ? (this.data.header.confirmation_lines || []).map(row => ({ name: row.physical_no || row.source_key, original: page.quantity(row.input_measure), actual: (this.data.drafts[row.source_key] || {}).measure_qty, unit: row.measure_unit === 'm2' ? 'm²' : '米' })) : [];
    this.setData({ dialog: { action, title: action === 'reject' ? '驳回入库申请' : action === 'cancel' ? '撤回入库申请' : LABELS[action],
      reason: '', error: '', facts, required: ['reject', 'scrap', 'exception'].includes(action), message: action === 'reject' ? '驳回后退回登记人更正，保留本次记录。' : action === 'cancel' ? '撤回本次申请，保留原记录。' : action === 'exception' ? '只登记异常，单据保持在途，不改变数量和成本。' : action === 'scrap' ? '确认本批实际废余料量，回收为零，成本保留在制造净耗。' : '确认本单实物已核对并存放于所选库位？' } });
  },
  dialogInput(event) { this.setData({ 'dialog.reason': event.detail.value }); },
  closeDialog() { if (!this.data.saving) this.setData({ dialog: null }); },
  confirmAction() {
    const dialog = this.data.dialog;
    if (!dialog || this.data.saving) return;
    if (dialog.required && !dialog.reason.trim()) return this.setData({ 'dialog.error': '请填写原因' });
    const payload = { expected_version: dialog.action === 'resubmit' ? this.data.header.batch_business_version : this.data.header.business_version };
    if (dialog.action === 'confirm' || dialog.action === 'scrap' || dialog.action === 'resubmit') {
      if (this.data.isRemnant) { const rows = (this.data.header.confirmation_lines || []).map(row => { const draft = this.data.drafts[row.source_key] || {}; return { source_key: row.source_key, measure_qty: String(draft.measure_qty || ''), warehouse_id: draft.warehouse_id || undefined, location_id: draft.location_id || undefined }; }); if (dialog.action === 'resubmit') Object.assign(payload, { confirmed: true, remnants: rows }); else payload.lines = rows; }
      else if (this.data.isPhysical) Object.assign(payload, { target_location_id: this.data.locator.location_id, physical_ids: Object.keys(this.data.checkedPhysicals).map(Number) });
      else if (this.data.isTransfer) Object.assign(payload, { lines: (this.data.header.confirmation_lines || []).map(row => ({ line_id: row.id, target_location_id: (this.data.drafts[String(row.id)] || {}).location_id })), serial_nos: Object.keys(this.data.checked) });
      else Object.assign(payload, { location_id: this.data.locator.location_id, serial_nos: Object.keys(this.data.checked), remark: this.data.remark || undefined });
    }
    if (dialog.required) payload.reason = dialog.reason.trim();
    if (dialog.action === 'confirm' && this.data.remark.trim()) payload.remark = this.data.remark.trim();
    this.submit(dialog.action, payload);
  },
  recover(event) { this.submit(event.currentTarget.dataset.action); },
  async submit(action, payload) {
    if (this.data.saving) return;
    this.setData({ saving: true, formError: '' });
    try {
      const result = await warehouse.submit(action === 'resubmit' ? 'cutting_batch.request' : this.data.kind + '.' + action, action === 'resubmit' ? this.data.header.cutting_batch_id : this.data.id, payload || {});
      if (action === 'resubmit') { this.setData({ resubmitting: false }); if (result.remnant_receipt && result.remnant_receipt.id) this.setData({ id: Number(result.remnant_receipt.id) }); }
      this.setData({ dialog: null, checked: {}, checkedCount: 0 });
      wx.showToast({ title: action === 'confirm' ? '入库已确认' : action === 'reject' ? '已驳回' : action === 'exception' ? '已登记异常，仍在途' : action === 'scrap' ? '余料已报废' : '申请已撤回', icon: 'none' });
      await this.load();
    } catch (error) {
      this.setData({ formError: page.errorText(error), dialog: null,
        pendingActions: Object.keys(LABELS).filter(key => warehouse.pending(this.data.kind + '.' + key, this.data.id)).concat(this.data.isRemnant && warehouse.pending('cutting_batch.request', this.data.header.cutting_batch_id) ? ['resubmit'] : []) });
      if (!error.pendingCommand && [409, 422].includes(error.statusCode)) await this.load();
    } finally { this.setData({ saving: false }); }
  },
  stop() {},
});
