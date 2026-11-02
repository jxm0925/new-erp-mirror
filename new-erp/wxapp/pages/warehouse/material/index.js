const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const LABELS = {
  'material_delivery.prepare': '生成配料单', 'material_delivery.dispatch': '确认发出', 'material_delivery.repick': '重新拣货',
  'material_delivery.cancel': '取消配料单', 'material_delivery.redeliver': '重新送达', 'material_delivery.recall': '整单收回',
  'material_return.approve': '确认接收入库', 'material_return.reject': '驳回', 'material_return.dispose': '确认处置', 'material_return.cancel': '撤回申请',
};
Page({
  data: { kind: '', id: 0, parent: 0, loading: true, saving: false, error: '', formError: '', header: {}, lines: [], preview: null,
    actions: {}, page: 1, lastPage: 1, total: 0, units: '', remark: '', locator: {}, locatorOpen: false, dialog: null,
    checks: {}, checkedLocations: {}, checkedSerials: {}, dispositionDrafts: {}, pendingActions: [], planReviewRequired: false,
    dispositionOptions: [{ value: 'release', label: '判定可用' }, { value: 'scrap', label: '报废' }, { value: 'supplier_return', label: '退供应商' }] },
  onLoad(options) {
    const kind = ['material_preparation', 'material_delivery', 'material_return'].includes(options.kind) ? options.kind : '';
    this.setData({ kind, id: Number(options.id), parent: Number(options.parent || 0) });
    wx.setNavigationBarTitle({ title: kind === 'material_preparation' ? '配料预览' : kind === 'material_return' ? '退料接收' : '配料单详情' });
  },
  onShow() { if (!page.resumingScan(this)) this.load(); }, onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    if (!this.data.kind || !this.data.id) { this.setData({ loading: false, error: '单据参数不完整' }); return Promise.resolve(); }
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    return warehouse.document(this.data.kind, this.data.id, { page: this.data.page, per_page: 10, units: this.data.units || undefined }).then(response => {
      if (sequence !== this.sequence) return;
      const body = response.data || response; const result = page.rows(body.lines); const actions = {};
      const documentKey = this.data.kind + ':' + this.data.id;
      if (this.pickDocument !== documentKey || (this.sourceRevision && this.sourceRevision !== body.header.source_revision)) {
        if (this.pickDocument === documentKey) this.setData({ checkedLocations: {}, checkedSerials: {}, formError: '拣货来源已变化，请重新核对库位和序列号' });
        this.pickDocument = documentKey; this.pickLines = {}; this.pickFingerprints = {};
      }
      this.sourceRevision = body.header.source_revision;
      let sourcesChanged = false;
      result.rows.forEach(line => {
        const fingerprint = JSON.stringify(line.picks || []);
        if (this.pickFingerprints[line.id] && this.pickFingerprints[line.id] !== fingerprint) sourcesChanged = true;
        this.pickFingerprints[line.id] = fingerprint; this.pickLines[line.id] = line;
      });
      if (sourcesChanged) this.setData({ checkedLocations: {}, checkedSerials: {}, formError: '拣货来源已变化，请重新核对库位和序列号' });
      (body.actions || []).forEach(action => { actions[action.split('.')[1]] = true; });
      const lines = result.rows.map(row => Object.assign({}, row, { qtyText: page.quantity(row.qty), heldText: page.quantity(row.held_qty),
        picks: (row.picks || []).map(pick => Object.assign({}, pick, { qtyText: page.quantity(pick.qty), serialText: (pick.serial_nos || []).join('、') })),
        disposition: this.data.dispositionDrafts[row.id] || { line_id: row.id, action: 'release', qty: page.quantity(row.held_qty) },
      }));
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.load(); }
      this.setData({ header: body.header || {}, lines, preview: body.preview || null, actions, loading: false, page: result.page,
        lastPage: result.lastPage, total: result.total, planReviewRequired: !!body.plan_review_required,
        pendingActions: Object.keys(LABELS).filter(action => this.data.kind === 'material_preparation' ? action === 'material_delivery.prepare' : this.data.kind === 'material_return' ? action.startsWith('material_return.') : action.startsWith('material_delivery.') && action !== 'material_delivery.prepare')
          .filter(action => warehouse.pending(action, this.data.id)).map(action => ({ action, label: LABELS[action] })) });
      this.updatePickChecks();
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  input(event) { this.setData({ [event.currentTarget.dataset.field]: event.detail.value }); },
  changeUnits(event) {
    const value = event.detail.value; if (value !== '' && (!Number.isInteger(Number(value)) || Number(value) < 1 || Number(value) > 100000)) return this.setData({ units: value, formError: '配料台数必须为 1 至 100000 的整数' });
    this.setData({ units: value, page: 1, checks: {}, checkedLocations: {}, checkedSerials: {}, formError: '' }); this.load();
  },
  turn(event) { const target = this.data.page + Number(event.currentTarget.dataset.delta); if (target > 0 && target <= this.data.lastPage && !this.data.loading && !this.data.saving) { this.setData({ page: target }); this.load(); } },
  verifyPick(event) {
    const result = event.detail.result; const line = this.data.lines.find(row => String(row.id) === String(event.currentTarget.dataset.line));
    const pick = line && line.picks[Number(event.currentTarget.dataset.pick)]; if (!pick) return;
    const key = String(line.id) + '_' + String(event.currentTarget.dataset.pick);
    if (result.type === 'location') {
      if (String(result.location_code) !== String(pick.location_code)) return this.setData({ formError: '扫描库位与当前拣货来源不一致，请重新核对' });
      this.setData({ ['checkedLocations.' + key]: true, formError: '' });
    } else if (result.type === 'serial') {
      if (!(pick.serial_nos || []).includes(result.serial_no) || (result.inventory_balance_id && Number(result.inventory_balance_id) !== Number(pick.balance_id))) return this.setData({ formError: '序列号不属于该拣货来源' });
      this.setData({ ['checkedSerials.' + String(result.inventory_serial_id || result.serial_no)]: result.serial_no, formError: '' });
    }
    this.updatePickChecks();
  },
  updatePickChecks() {
    this.setData({ lines: this.data.lines.map(line => Object.assign({}, line, { picks: (line.picks || []).map((pick, index) => {
      const key = String(line.id) + '_' + index; const checked = Object.values(this.data.checkedSerials);
      return Object.assign({}, pick, { locationChecked: !!this.data.checkedLocations[key], serialChecked: (pick.serial_nos || []).every(no => checked.includes(no)) });
    }) })) });
  },
  verifyReturn(event) {
    const result = event.detail.result; const line = this.data.lines.find(row => Number(row.id) === Number(event.currentTarget.dataset.line)); if (!line) return;
    if (result.type === 'item' && Number(result.item_id) === Number(line.component_item_id)) this.setData({ ['checks.' + line.id + '.item']: true, formError: '' });
    else if (result.type === 'serial' && result.serial_no === line.serial_no && Number(result.item_id) === Number(line.component_item_id)) this.setData({ ['checks.' + line.id + '.serial']: true, formError: '' });
    else this.setData({ formError: '编码与该退料明细不一致，请核对实物' });
    const checks = this.data.checks[line.id] || {};
    if (checks.item && (!line.serial_no || checks.serial)) this.setData({ ['checks.' + line.id + '.complete']: true });
  },
  openLocator() { this.setData({ locatorOpen: true }); }, closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) { this.setData({ locator: event.detail, locatorOpen: false, formError: '' }); },
  async scanLocator(event) {
    const result = event.detail.result;
    try {
      const response = await warehouse.locators({ mode: 'location', warehouse_id: result.warehouse_id, id: result.location_id, per_page: 10, page: 1 });
      const row = page.rows(response).rows.find(row => Number(row.id) === Number(result.location_id));
      if (!row) throw new Error('该库位不可用于退料入库');
      this.setData({ locator: { warehouse_id: result.warehouse_id, warehouse_name: result.warehouse_name, location_id: row.id, location_code: row.code, location_name: row.name }, formError: '' });
    } catch (error) { this.setData({ formError: page.errorText(error) }); }
  },
  disposition(event) {
    const id = Number(event.currentTarget.dataset.id); const line = this.data.lines.find(row => Number(row.id) === id); if (!line) return;
    const draft = Object.assign({}, this.data.dispositionDrafts[id] || line.disposition);
    draft[event.currentTarget.dataset.field] = event.currentTarget.dataset.value !== undefined ? event.currentTarget.dataset.value : event.detail.value;
    this.setData({ ['dispositionDrafts.' + id]: draft, lines: this.data.lines.map(row => Number(row.id) === id ? Object.assign({}, row, { disposition: draft }) : row) });
  },
  requestAction(event) {
    const action = event.currentTarget.dataset.action; const dispatch = event.currentTarget.dataset.dispatch === true || event.currentTarget.dataset.dispatch === 'true';
    if (this.data.saving || this.data.loading || this.data.pendingActions.length) return;
    let payload = {};
    if (action === 'material_delivery.prepare') {
      if (this.data.units !== '' && (!Number.isInteger(Number(this.data.units)) || Number(this.data.units) < 1 || Number(this.data.units) > 100000)) return this.setData({ formError: '配料台数必须为 1 至 100000 的整数' });
      if (!this.data.preview || !(this.data.preview.lines || []).length) return this.setData({ formError: '当前没有可配物料' });
      if ((this.data.preview.short || []).length) return this.setData({ formError: this.data.preview.short.join('；') });
      payload = { work_order_id: this.data.header.work_order_id, remark: this.data.remark, dispatch, expected_source_revision: this.data.header.source_revision };
      if (this.data.units !== '') payload.units = Number(this.data.units);
      if (dispatch && !this.picksVerified(this.data.preview.lines || [])) return;
    } else if (action === 'material_delivery.dispatch') {
      if ((this.data.header.source_problems || []).length) return this.setData({ formError: '拣货来源已失效，请先重新拣货' });
      if (Object.keys(this.pickLines || {}).length !== this.data.total) return this.setData({ formError: '请逐页核对全部配料物料后发出' });
      if (!this.picksVerified(Object.values(this.pickLines || {}))) return;
      payload = { expected_source_revision: this.data.header.source_revision };
    } else if (action === 'material_return.approve') {
      if (!this.data.locator.location_id) return this.setData({ formError: '请扫描或选择入库库位' });
      if (Object.values(this.data.checks).filter(row => row.complete).length < this.data.total) return this.setData({ formError: '请逐项扫描物料和序列号，核对全部退料明细' });
      payload = { location_id: this.data.locator.location_id, remark: this.data.remark };
    } else if (action === 'material_return.dispose') {
      const lines = this.data.lines.filter(line => Number(line.held_qty) > 0).map(line => this.data.dispositionDrafts[line.id] || line.disposition);
      if (!lines.length) return this.setData({ formError: '当前页没有待处置明细' });
      for (const line of lines) { const row = this.data.lines.find(row => Number(row.id) === Number(line.line_id)); if (!(Number(line.qty) > 0) || Number(line.qty) > Number(row.held_qty)) return this.setData({ formError: '处置数量须大于零且不超过待处置数量' }); }
      payload = { lines, remark: this.data.remark };
    } else if (action === 'material_delivery.redeliver') payload = { remark: this.data.remark };
    const required = action === 'material_delivery.recall' || action === 'material_return.reject';
    const messages = {
      'material_delivery.prepare': dispatch ? '配好后直接发出，将按拣货来源扣减库存。' : '确认按当前预览生成配料单？',
      'material_delivery.dispatch': '确认已照单拣好物料并发出？',
      'material_delivery.repick': '已拣出的物料请放回原位，重新分配后按新来源取料。',
      'material_delivery.cancel': '取消配料单，不改变库存。',
      'material_delivery.redeliver': '确认已将本单全部物料重新送达车间？',
      'material_delivery.recall': '本单全部物料退回原库存位置。',
      'material_return.approve': '确认实物核对无误并按所选库位接收入库？',
      'material_return.reject': '驳回后物料仍留在原工序。',
      'material_return.dispose': '按当前页选择的处置方式处理退料，确认后改变库存。',
      'material_return.cancel': '确认撤回这张退料申请？',
    };
    this.setData({ formError: '', dialog: { action, payload, title: LABELS[action], message: messages[action], required, reason: '', error: '' } });
  },
  picksVerified(lines) {
    const serials = Object.values(this.data.checkedSerials);
    for (const line of lines) for (let index = 0; index < (line.picks || []).length; index++) {
      const pick = line.picks[index]; const key = String(line.id || line.material_id) + '_' + index;
      if (!this.data.checkedLocations[key] || !(pick.serial_nos || []).every(no => serials.includes(no))) { this.setData({ formError: '请扫描全部来源库位并逐个核对配料序列号' }); return false; }
    }
    return true;
  },
  dialogInput(event) { this.setData({ 'dialog.reason': event.detail.value }); }, closeDialog() { if (!this.data.saving) this.setData({ dialog: null }); },
  confirmAction() {
    const dialog = this.data.dialog; if (!dialog || this.data.saving) return;
    if (dialog.required && !dialog.reason.trim()) return this.setData({ 'dialog.error': '请输入原因' });
    const payload = Object.assign({}, dialog.payload);
    if (dialog.action === 'material_delivery.recall') payload.reason = dialog.reason.trim();
    if (dialog.action === 'material_return.reject') payload.remark = dialog.reason.trim();
    return this.execute(dialog.action, payload);
  },
  recover(event) { return this.execute(event.currentTarget.dataset.action, {}); },
  async execute(action, payload) {
    if (this.data.saving) return;
    this.setData({ saving: true, formError: '' });
    try {
      const result = await warehouse.submit(action, this.data.id, payload);
      this.setData({ dialog: null, remark: '', checks: {}, checkedLocations: {}, checkedSerials: {}, dispositionDrafts: {} });
      wx.showToast({ title: '操作成功', icon: 'success' });
      if (action === 'material_delivery.prepare') {
        this.setData({ kind: 'material_delivery', id: Number(result.id), page: 1, units: '' }); wx.setNavigationBarTitle({ title: '配料单详情' });
      }
      await this.load();
    } catch (error) { this.setData({ formError: page.errorText(error), dialog: null }); await this.load(); }
    finally { this.setData({ saving: false }); }
  },
  stop() {},
});
