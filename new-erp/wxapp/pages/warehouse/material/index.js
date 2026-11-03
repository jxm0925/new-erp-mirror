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
    checks: {}, checkedLocations: {}, checkedSerials: {}, dispositionDrafts: {}, pendingActions: [], planReviewRequired: false, canConfirmInbound: false, recallConfirmInbound: false,
    manualOpen: false, manualRows: [], manualSelected: [], manualScope: '', manualTitle: '',
    selections: [], pickerOpen: false, pickerLine: {}, pickerOtherPicks: [], pickerScan: null, pickerError: '',
    expandedLines: {}, shortages: [], canPurchase: false, purchaseCompleted: false, purchaseUncertain: false, purchaseMessage: '',
    dispositionOptions: [{ value: 'release', label: '判定可用' }, { value: 'scrap', label: '报废' }, { value: 'supplier_return', label: '退供应商' }] },
  onLoad(options) {
    const kind = ['material_preparation', 'material_delivery', 'material_return'].includes(options.kind) ? options.kind : '';
    this.setData({ kind, id: Number(options.id), parent: Number(options.parent || 0) });
    wx.setNavigationBarTitle({ title: kind === 'material_preparation' ? '配料预览' : kind === 'material_return' ? '退料接收' : '配料单详情' });
  },
  onShow() { if (!page.resumingScan(this) && !this.data.saving) this.load(); }, onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { if (this.data.saving) return wx.stopPullDownRefresh(); this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    if (!this.data.kind || !this.data.id) { this.setData({ loading: false, error: '单据参数不完整' }); return Promise.resolve(); }
    const sequence = this.sequence = (this.sequence || 0) + 1; const token = wx.getStorageSync('erp_token');
    const permissions = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    const sameAccount = () => token === wx.getStorageSync('erp_token') && permissions === JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    this.setData({ loading: true, error: '', manualOpen: false, pickerOpen: false });
    return warehouse.document(this.data.kind, this.data.id, { page: this.data.page, per_page: 10, units: this.data.units || undefined }).then(async response => {
      if (sequence !== this.sequence) return;
      if (!sameAccount()) return this.setData({ loading: false, error: '账号或权限已变化，请重新加载' });
      const body = response.data || response;
      if (this.data.kind === 'material_preparation' && this.data.selections.length) {
        const selected = await warehouse.deliverySelectionPreview(body.header.work_order_id, this.data.id, { units: this.data.units || undefined, selections: this.data.selections });
        if (sequence !== this.sequence) return;
        if (!sameAccount()) return this.setData({ loading: false, error: '账号或权限已变化，请重新加载' });
        const preview = selected.data || selected;
        body.preview = preview; body.header.source_revision = preview.source_revision;
        body.lines = this.previewPage(preview);
      }
      if (!sameAccount()) return this.setData({ loading: false, error: '账号或权限已变化，请重新加载' });
      const result = page.rows(body.lines); const actions = {};
      if (this.returnVersion && this.returnVersion !== body.header.business_version && this.data.kind === 'material_return') this.setData({ checks: {}, locator: {}, formError: '退料单已变化，请重新核对实物与库位' });
      this.returnVersion = body.header.business_version;
      const documentKey = this.data.kind + ':' + this.data.id;
      if (this.pickDocument !== documentKey || (this.sourceRevision && this.sourceRevision !== body.header.source_revision)) {
        if (this.pickDocument === documentKey) this.setData({ checkedLocations: {}, checkedSerials: {}, formError: this.data.kind === 'material_preparation' ? '配料来源已变化，请重新选择批次和数量' : '拣货来源已变化，请查看最新配料明细' });
        this.pickDocument = documentKey; this.pickLines = {}; this.pickFingerprints = {};
      }
      this.sourceRevision = body.header.source_revision;
      let sourcesChanged = false;
      result.rows.forEach(line => {
        const fingerprint = JSON.stringify(line.picks || []);
        if (this.pickFingerprints[line.id] && this.pickFingerprints[line.id] !== fingerprint) sourcesChanged = true;
        this.pickFingerprints[line.id] = fingerprint; this.pickLines[line.id] = line;
      });
      if (sourcesChanged) this.setData({ checkedLocations: {}, checkedSerials: {}, formError: this.data.kind === 'material_preparation' ? '配料来源已变化，请重新选择批次和数量' : '拣货来源已变化，请查看最新配料明细' });
      (body.actions || []).forEach(action => { actions[action.split('.')[1]] = true; });
      const lines = result.rows.map(row => this.decorateLine(row, this.data.selections, (body.preview || {}).lines));
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.load(); }
      this.setData({ header: body.header || {}, lines, preview: body.preview || null, actions, loading: false, page: result.page,
        lastPage: result.lastPage, total: result.total, shortages: this.shortageRows(body.preview), canPurchase: !!actions.request_purchase,
        planReviewRequired: !!body.plan_review_required, canConfirmInbound: page.permissions()('inventory.inbound.confirm'),
        pendingActions: Object.keys(LABELS).filter(action => this.data.kind === 'material_preparation' ? action === 'material_delivery.prepare' : this.data.kind === 'material_return' ? action.startsWith('material_return.') : action.startsWith('material_delivery.') && action !== 'material_delivery.prepare')
          .filter(action => warehouse.pending(action, this.data.id)).map(action => ({ action, label: LABELS[action] })) });
      this.updatePickChecks();
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: sameAccount() ? page.errorText(error) : '账号或权限已变化，请重新加载' }); });
  },
  selectedPicks(itemId, selections, lines, exceptMaterialId) {
    const materialIds = new Set((lines || []).filter(line => Number(line.component_item_id) === Number(itemId)
      && Number(line.material_id || line.id) !== Number(exceptMaterialId || 0)).map(line => Number(line.material_id || line.id)));
    return (selections || []).filter(line => materialIds.has(Number(line.material_id))).flatMap(line => line.picks || []);
  },
  decorateLine(row, selections, lines) {
    const id = row.id || row.material_id;
    const selected = (selections || this.data.selections).some(line => Number(line.material_id) === Number(id));
    const displayedPicks = this.data.kind === 'material_preparation' && !selected ? [] : (row.picks || []);
    const available = row.stock_available_qty === null || row.stock_available_qty === undefined || row.stock_available_qty === '' ? NaN : Number(row.stock_available_qty);
    const picks = this.selectedPicks(row.component_item_id, selections || this.data.selections, lines || (this.data.preview || {}).lines);
    const picked = picks.reduce((sum, pick) => pick.qty === null || pick.qty === undefined || pick.qty === '' ? NaN : sum + Number(pick.qty), 0);
    return Object.assign({}, row, { id, qtyText: page.quantity(row.qty), heldText: page.quantity(row.held_qty),
      remainingText: Number.isFinite(available - picked) ? page.quantity(Math.max(0, available - picked).toFixed(4)) : '—',
      picks: displayedPicks.map(pick => Object.assign({}, pick, { qtyText: page.quantity(pick.qty), availableText: page.quantity(pick.quantity_available), serialText: (pick.serial_nos || []).join('、') })),
      disposition: this.data.dispositionDrafts[id] || { line_id: id, action: 'release', qty: page.quantity(row.held_qty) },
    });
  },
  previewPage(preview) {
    const rows = preview.lines || []; const last = Math.max(1, Math.ceil(rows.length / 10)); const current = Math.min(this.data.page, last);
    return { data: rows.slice((current - 1) * 10, current * 10).map(row => Object.assign({}, row, { id: row.material_id })), current_page: current, last_page: last, total: rows.length };
  },
  shortageRows(preview) {
    const grouped = {};
    ((preview || {}).lines || []).forEach(line => {
      const id = Number(line.component_item_id); const row = grouped[id] || (grouped[id] = { item_id: id, name: line.component_item_name, unit: line.unit_name || '', qty: 0, available: 0, short: 0 });
      row.qty += Number(line.qty); row.available += Math.max(0, Number(line.qty) - Number(line.short_qty)); row.short += Number(line.short_qty);
    });
    return Object.values(grouped).filter(row => row.short > 0.00001).map(row => Object.assign({}, row, { qtyText: page.quantity(row.qty.toFixed(4)), availableText: page.quantity(row.available.toFixed(4)), shortText: page.quantity(row.short.toFixed(4)) }));
  },
  toggleLine(event) { const id = event.currentTarget.dataset.line; this.setData({ ['expandedLines.' + id]: !this.data.expandedLines[id] }); },
  async purchaseLines() {
    const sequence = this.sequence;
    const response = await warehouse.deliveryPurchasePreview(this.data.header.work_order_id, this.data.id,
      { units: this.data.units || undefined, selections: this.data.selections });
    if (sequence !== this.sequence) throw new Error('配料预览已变化，请重新核对采购缺口');
    const body = response.data || response;
    return body.lines || [];
  },
  async requestPurchase() {
    if (this.data.kind !== 'material_preparation' || !this.data.canPurchase || !this.data.shortages.length || this.data.loading || this.data.saving || this.data.pendingActions.length || this.data.purchaseCompleted || this.data.purchaseUncertain) return;
    const sequence = this.sequence; const token = wx.getStorageSync('erp_token'); const permissions = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    const sameAccount = () => token === wx.getStorageSync('erp_token') && permissions === JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    this.setData({ saving: true, purchaseMessage: '' });
    try {
      const lines = await this.purchaseLines();
      if (sequence !== this.sequence || !sameAccount()) return;
      if (!lines.length) return this.setData({ purchaseMessage: '当前缺料已由采购需求或在途覆盖，或属于自制物料，无需重复生成采购需求单。' });
      if (lines.length > 100) return this.setData({ purchaseMessage: '本次缺料超过采购需求单的物料上限，请由采购分批办理。' });
      this.purchaseContext = { lines, sourceRevision: this.sourceRevision, token, permissions };
      this.setData({ dialog: { action: 'purchase_request', title: '生成采购需求单', required: false, reason: '', error: '',
        message: lines.map(row => row.name + ' ' + page.quantity(row.qty) + row.unit + (Number(row.transit) > 0 ? '（本单在途 ' + row.transit + row.unit + '）' : '')).join('；') + '。确认生成采购需求草稿？' } });
    } catch (error) { if (sequence === this.sequence) this.setData({ purchaseMessage: sameAccount() ? page.errorText(error) : '账号或权限已变化，请重新加载' }); }
    finally { if (sequence === this.sequence) this.setData({ saving: false }); }
  },
  async confirmPurchase() {
    const context = this.purchaseContext; if (!context) return this.setData({ dialog: null, purchaseMessage: '请重新核对采购缺口' });
    const sequence = this.sequence; let posting = false;
    const sameAccount = () => context.token === wx.getStorageSync('erp_token') && context.permissions === JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    this.setData({ saving: true, purchaseMessage: '' });
    try {
      const fresh = await this.purchaseLines();
      if (sequence !== this.sequence || context.sourceRevision !== this.sourceRevision || !sameAccount()) throw new Error('配料预览或账号已变化，请重新核对采购缺口');
      const lines = context.lines.map(row => {
        const now = fresh.find(line => line.item_id === row.item_id); if (!now) return null;
        if (now.purchase_unit_id !== row.purchase_unit_id) throw new Error(row.name + ' 采购单位已变化，请重新核对');
        return { item_id: row.item_id, qty: Math.min(row.qty, now.qty), purchase_unit_id: now.purchase_unit_id };
      }).filter(Boolean);
      if (!lines.length) return this.setData({ dialog: null, purchaseMessage: '本工序当前已无可生成的采购缺口，请重新核对供给安排。' });
      posting = true;
      const response = await warehouse.createDeliveryPurchaseRequest(this.data.header.work_order_id, this.data.id,
        { units: this.data.units || undefined, selections: this.data.selections, lines });
      if (sequence !== this.sequence || !sameAccount()) return;
      const result = response.data || response;
      this.setData({ dialog: null, purchaseCompleted: true, purchaseMessage: '已生成采购需求 ' + result.request_no + '（草稿），请采购确认。' });
      wx.showToast({ title: '采购需求已生成', icon: 'none' });
    } catch (error) {
      if (sequence !== this.sequence) return;
      // 超时等未知结果先核对需求单，不能自动重发采购写入。
      const uncertain = posting && ![401, 403, 404, 409, 422].includes(error.statusCode);
      this.setData({ dialog: null, purchaseUncertain: uncertain, purchaseMessage: !sameAccount() ? '账号或权限已变化，请重新加载' : uncertain ? '生成结果尚未确认，请先到采购需求核对，避免重复生成。' : page.errorText(error) });
    } finally { this.purchaseContext = null; if (sequence === this.sequence) this.setData({ saving: false }); }
  },
  openPicker(event) {
    if (this.data.kind !== 'material_preparation' || !this.data.actions.prepare || this.data.saving || this.data.loading || this.data.pendingActions.length) return;
    const line = this.data.lines.find(row => String(row.id) === String(event.currentTarget.dataset.line)); if (!line) return;
    const otherPicks = this.selectedPicks(line.component_item_id, this.data.selections, (this.data.preview || {}).lines, line.material_id || line.id);
    this.setData({ pickerOpen: true, pickerLine: line, pickerOtherPicks: otherPicks, pickerScan: event.detail && event.detail.result || null, pickerError: '', formError: '' });
  },
  closePicker() { if (!this.data.saving) this.setData({ pickerOpen: false, pickerScan: null, pickerError: '' }); },
  async confirmPicker(event) {
    if (!this.data.pickerOpen || this.data.saving || this.data.loading || this.data.pendingActions.length) return;
    const materialId = Number(this.data.pickerLine.material_id || this.data.pickerLine.id);
    const selections = this.data.selections.filter(row => Number(row.material_id) !== materialId).concat({ material_id: materialId, picks: event.detail.picks });
    const sequence = this.sequence; const token = wx.getStorageSync('erp_token');
    const permissions = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    const sameAccount = () => token === wx.getStorageSync('erp_token') && permissions === JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    this.setData({ saving: true, pickerError: '' });
    try {
      const response = await warehouse.deliverySelectionPreview(this.data.header.work_order_id, this.data.id, { units: this.data.units || undefined, selections });
      if (sequence !== this.sequence || !this.data.pickerOpen) return;
      if (!sameAccount()) return this.setData({ pickerError: '账号或权限已变化，请关闭选料并重新加载' });
      const preview = response.data || response; const result = page.rows(this.previewPage(preview));
      // 数量或来源变化后，旧扫码核对不能继续算数；本次手动确认的来源使用服务端复核结果。
      const checkedLocations = {}; const checkedSerials = {};
      (preview.lines || []).forEach(line => {
        const old = (this.data.preview.lines || []).find(row => Number(row.material_id) === Number(line.material_id));
        (line.picks || []).forEach((pick, index) => {
          const key = line.material_id + '_' + index; const previous = old && (old.picks || [])[index];
          const same = previous && Number(previous.balance_id) === Number(pick.balance_id) && Number(previous.qty) === Number(pick.qty) && JSON.stringify(previous.serial_ids) === JSON.stringify(pick.serial_ids);
          if (Number(line.material_id) === materialId || (same && this.data.checkedLocations[key])) checkedLocations[key] = true;
          (pick.serial_nos || []).forEach(no => { if (Number(line.material_id) === materialId || (same && Object.values(this.data.checkedSerials).includes(no))) checkedSerials[no] = no; });
        });
      });
      this.sourceRevision = preview.source_revision; this.pickFingerprints = {}; this.pickLines = {};
      this.setData({ preview, selections, 'header.source_revision': preview.source_revision, lines: result.rows.map(row => this.decorateLine(row, selections, preview.lines)),
        page: result.page, lastPage: result.lastPage, total: result.total, shortages: this.shortageRows(preview), pickerOpen: false, pickerScan: null, pickerError: '', checkedLocations, checkedSerials });
      this.updatePickChecks();
    } catch (error) { if (sequence === this.sequence) this.setData({ pickerError: sameAccount() ? page.errorText(error) : '账号或权限已变化，请关闭选料并重新加载' }); }
    finally { if (sequence === this.sequence) this.setData({ saving: false }); }
  },
  resetSelection() {
    if (this.data.saving) return;
    this.setData({ selections: [], checkedLocations: {}, checkedSerials: {}, formError: '' }); this.load();
  },
  input(event) { this.setData({ [event.currentTarget.dataset.field]: event.detail.value }); },
  changeUnits(event) {
    if (this.data.saving || this.data.pendingActions.length) return;
    const value = event.detail.value; if (value !== '' && (!Number.isInteger(Number(value)) || Number(value) < 1 || Number(value) > 100000)) return this.setData({ units: value, formError: '配料台数必须为 1 至 100000 的整数' });
    if (value === this.data.units) return;
    this.setData({ units: value, page: 1, checks: {}, checkedLocations: {}, checkedSerials: {}, selections: [], formError: '配料台数已变化，请按新的用量重新选择批次和数量' }); this.load();
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
  openManual(event) {
    if (this.data.saving || this.data.loading || this.data.pendingActions.length) return;
    const line = this.data.lines.find(row => String(row.id) === String(event.currentTarget.dataset.line)); if (!line) return;
    const rows = []; const selected = []; const isReturn = this.data.kind === 'material_return';
    const pickIndex = Number(event.currentTarget.dataset.pick || 0); const pick = isReturn ? null : (line.picks || [])[pickIndex];
    if (isReturn) {
      if (!this.data.actions.approve) return;
      const checks = this.data.checks[line.id] || {};
      rows.push({ id: 'item', title: line.component_item_name, subtitle: [line.component_item_code, line.spec].filter(Boolean).join(' · '), detail: '物料与实际退回物品一致', kind: 'item' });
      if (checks.item) selected.push(rows[0]);
      if (line.serial_no) { rows.push({ id: 'serial:' + line.serial_no, title: line.serial_no, detail: '本单退回编号', serial_no: line.serial_no, kind: 'serial' }); if (checks.serial) selected.push(rows[rows.length - 1]); }
    } else {
      if (!pick || !(this.data.actions.recall && this.data.canConfirmInbound)) return;
      rows.push({ id: 'location', title: pick.location_code, subtitle: line.component_item_name || line.item_name, detail: [pick.warehouse_name, pick.batch_no, pick.qtyText + ' ' + (line.unit_name || '')].filter(Boolean).join(' · '), kind: 'location' });
      if (this.data.checkedLocations[String(line.id) + '_' + pickIndex]) selected.push(rows[0]);
      (pick.serial_nos || []).forEach(no => { const row = { id: 'serial:' + no, title: no, subtitle: line.component_item_name || line.item_name, detail: '本次拣货来源编号', serial_no: no, kind: 'serial' }; rows.push(row); if (Object.values(this.data.checkedSerials).includes(no)) selected.push(row); });
    }
    this.manualTarget = { lineId: line.id, pickIndex, fingerprint: JSON.stringify(isReturn ? [line.component_item_id, line.serial_no] : pick) };
    this.setData({ manualOpen: true, manualRows: rows, manualSelected: selected, manualTitle: isReturn ? '手动核对退料物品' : '手动核对配料来源', manualScope: [this.data.kind, this.data.id, this.data.header.business_version, this.sourceRevision, line.id, pickIndex].join(':') });
  },
  closeManual() { this.setData({ manualOpen: false }); },
  confirmManual(event) {
    if (!this.data.manualOpen || this.data.saving || this.data.loading || this.data.pendingActions.length) return;
    const target = this.manualTarget; const line = this.data.lines.find(row => String(row.id) === String(target.lineId));
    const isReturn = this.data.kind === 'material_return'; const pick = line && (line.picks || [])[target.pickIndex];
    if (!line || JSON.stringify(isReturn ? [line.component_item_id, line.serial_no] : pick) !== target.fingerprint) return this.setData({ manualOpen: false, formError: '单据明细已变化，请重新核对' });
    const chosen = event.detail.rows; const ids = new Set(chosen.map(row => row.id));
    if (chosen.some(row => !this.data.manualRows.some(candidate => candidate.id === row.id))) return;
    if (isReturn) {
      const item = ids.has('item'); const serial = !!line.serial_no && ids.has('serial:' + line.serial_no);
      this.setData({ ['checks.' + line.id]: { item, serial, complete: item && (!line.serial_no || serial) } });
    } else {
      const checkedSerials = Object.assign({}, this.data.checkedSerials);
      Object.keys(checkedSerials).forEach(key => { if ((pick.serial_nos || []).includes(checkedSerials[key])) delete checkedSerials[key]; });
      chosen.filter(row => row.kind === 'serial').forEach(row => { checkedSerials[row.id] = row.serial_no; });
      this.setData({ checkedSerials, ['checkedLocations.' + line.id + '_' + target.pickIndex]: ids.has('location') }); this.updatePickChecks();
    }
    this.setData({ manualOpen: false, formError: '' });
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
      const unselected = this.data.preview.lines.filter(line => Number(line.qty) > 0 && !this.data.selections.some(selected =>
        Number(selected.material_id) === Number(line.material_id || line.id) && (selected.picks || []).length));
      if (unselected.length) return this.setData({ formError: '请先为 ' + unselected.map(line => line.component_item_name || line.item_name).join('、') + ' 手动选择批次和本次数量' });
      payload = { work_order_id: this.data.header.work_order_id, remark: this.data.remark, dispatch,
        expected_source_revision: this.data.header.source_revision, selections: this.data.selections };
      if (this.data.units !== '') payload.units = Number(this.data.units);
    } else if (action === 'material_delivery.dispatch') {
      if ((this.data.header.source_problems || []).length) return this.setData({ formError: '拣货来源已失效，请先重新拣货' });
      payload = { expected_source_revision: this.data.header.source_revision };
    } else if (action === 'material_return.approve') {
      if (!this.data.locator.location_id) return this.setData({ formError: '请扫描或选择入库库位' });
      if (Object.values(this.data.checks).filter(row => row.complete).length < this.data.total) return this.setData({ formError: '请扫码或手动核对全部退料物料和序列号' });
      const serials = (this.data.header.serial_nos || []).filter(no => Object.values(this.pickLines || {}).some(row => row.serial_no === no && (this.data.checks[row.id] || {}).serial));
      if (serials.length !== (this.data.header.serial_nos || []).length) return this.setData({ formError: '请逐个核对全部退料序列号' });
      payload = { location_id: this.data.locator.location_id, remark: this.data.remark, serial_nos: serials };
    } else if (action === 'material_return.dispose') {
      const lines = this.data.lines.filter(line => Number(line.held_qty) > 0).map(line => this.data.dispositionDrafts[line.id] || line.disposition);
      if (!lines.length) return this.setData({ formError: '当前页没有待处置明细' });
      for (const line of lines) { const row = this.data.lines.find(row => Number(row.id) === Number(line.line_id)); if (!(Number(line.qty) > 0) || Number(line.qty) > Number(row.held_qty)) return this.setData({ formError: '处置数量须大于零且不超过待处置数量' }); }
      payload = { lines, remark: this.data.remark };
    } else if (action === 'material_delivery.redeliver') payload = { remark: this.data.remark };
    if (action.startsWith('material_return.')) payload.expected_version = this.data.header.business_version;
    const required = action === 'material_delivery.recall' || action === 'material_return.reject';
    const messages = {
      'material_delivery.prepare': dispatch ? '配好后直接发出，将按拣货来源扣减库存。' : '确认按当前预览生成配料单？',
      'material_delivery.dispatch': '确认已照单拣好物料并发出？',
      'material_delivery.repick': '已拣出的物料请放回原位，重新分配后按新来源取料。',
      'material_delivery.cancel': '取消配料单，不改变库存。',
      'material_delivery.redeliver': '确认已将本单全部物料重新送达车间？',
      'material_delivery.recall': '收回后生成待仓库确认的退料单；核全实物和实际库位可同时接收入库。',
      'material_return.approve': '确认实物核对无误并按所选库位接收入库？',
      'material_return.reject': '驳回后物料仍留在原工序。',
      'material_return.dispose': '按当前页选择的处置方式处理退料，确认后改变库存。',
      'material_return.cancel': '确认撤回这张退料申请？',
    };
    this.setData({ formError: '', dialog: { action, payload, title: LABELS[action], message: messages[action], required, reason: '', error: '', confirmInbound: action === 'material_delivery.recall' && this.data.recallConfirmInbound } });
  },
  dialogInput(event) { this.setData({ 'dialog.reason': event.detail.value }); }, closeDialog() { if (!this.data.saving) { this.purchaseContext = null; this.setData({ dialog: null }); } },
  toggleRecallReceive() { if (!this.data.saving) this.setData({ recallConfirmInbound: !this.data.recallConfirmInbound }); },
  confirmAction() {
    const dialog = this.data.dialog; if (!dialog || this.data.saving) return;
    if (dialog.action === 'purchase_request') return this.confirmPurchase();
    if (dialog.required && !dialog.reason.trim()) return this.setData({ 'dialog.error': '请输入原因' });
    const payload = Object.assign({}, dialog.payload);
    if (dialog.action === 'material_delivery.recall') {
      payload.reason = dialog.reason.trim();
      if (dialog.confirmInbound) {
        if (Object.keys(this.pickLines || {}).length !== this.data.total) return this.setData({ 'dialog.error': '请逐页核对本单全部明细' });
        const expected = Object.values(this.pickLines || {}).flatMap(row => (row.picks || []).flatMap(pick => pick.serial_nos || []));
        const checked = Object.values(this.data.checkedSerials);
        if (!this.data.locator.location_id) return this.setData({ 'dialog.error': '请选择实际接收库位' });
        if (!expected.every(no => checked.includes(no))) return this.setData({ 'dialog.error': '请逐个核对本单全部序列号' });
        Object.assign(payload, { confirm_inbound: true, location_id: this.data.locator.location_id, serial_nos: expected });
      }
    }
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
      wx.showToast({ title: action === 'material_delivery.recall' ? (result.return_status === 'approved' ? '已收回并接收入库' : '已收回，等待仓库确认') : '操作成功', icon: 'none' });
      if (action === 'material_delivery.prepare') {
        this.setData({ kind: 'material_delivery', id: Number(result.id), page: 1, units: '', selections: [] }); wx.setNavigationBarTitle({ title: '配料单详情' });
      }
      await this.load();
    } catch (error) { this.setData({ formError: page.errorText(error), dialog: null }); await this.load(); }
    finally { this.setData({ saving: false }); }
  },
  stop() {},
});
