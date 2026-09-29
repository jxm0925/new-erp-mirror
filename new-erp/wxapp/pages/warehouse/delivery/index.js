const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const delivery = require('../../../utils/warehouse-delivery');
const clone = value => JSON.parse(JSON.stringify(value));
Page({
  data: { id: 0, pickingId: 0, mode: 'detail', header: null, picking: null, rows: [], page: 1, lastPage: 1, total: 0, loading: true, busy: false, error: '',
    canCreate: false, canReceive: false, canDispatch: false, canDeliver: false, canCancel: false, canRedelivery: false, statusText: '',
    person: null, personError: '', remark: '', pickerOpen: false, pickerMode: 'people', pickerTitle: '', pickerQuery: {}, pickerSelected: [], pickerMultiple: false, pickerMax: 1,
    receiptOpen: false, receiptLineId: 0, receiptSelected: [], receiptUnit: '', cancelOpen: false, cancelReason: '', cancelError: '', conflictOpen: false, pendingAction: '' },
  onLoad(options) {
    this.entries = {};
    if (options.recover) {
      const saved = delivery.creationStore().pending(warehouse.pending).find(row => Number(row.pickingId) === Number(options.recover));
      if (saved) options = Object.assign({}, options, { mode: saved.mode, picking_id: saved.pickingId, source_delivery_id: saved.sourceDeliveryId || 0 });
      else { this.setData({ loading: false, error: '原提交已处理，请返回配送列表查看' }); return; }
    }
    const mode = ['create', 'redelivery'].includes(options.mode) ? options.mode : 'detail';
    this.setData({ mode, id: Number(options.source_delivery_id || options.id || 0), pickingId: Number(options.picking_id || 0) });
    wx.setNavigationBarTitle({ title: mode === 'create' ? '创建配送' : mode === 'redelivery' ? '拒收与补送' : '配送详情' });
    this.load();
  },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { return this.load().finally(() => wx.stopPullDownRefresh()); },
  isLocked() { return this.data.busy || !!this.data.pendingAction || this.data.loading; },
  findPending() {
    const actions = this.data.mode === 'detail' ? ['delivery.dispatch', 'delivery.deliver', 'delivery.receive', 'delivery.cancel'] : ['delivery.create'];
    const id = this.data.mode === 'detail' ? this.data.id : this.data.pickingId;
    return actions.find(action => warehouse.pending(action, id)) || '';
  },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    const mode = this.data.mode;
    this.setData({ loading: true, error: '', pendingAction: this.findPending() });
    const query = { line_page: this.data.page, line_per_page: 10 };
    const path = mode === 'create' ? `production/material-picking-tasks/${this.data.pickingId}` : `production/material-deliveries/${this.data.id}`;
    return warehouse.get(path, query).then(async response => {
      const header = response.data || response;
      let picking = mode === 'create' ? header : header.picking_task || {};
      if (mode === 'redelivery') {
        const pickResponse = await warehouse.get(`production/material-picking-tasks/${header.picking_task_id}`, { line_page: 1, line_per_page: 1 });
        picking = pickResponse.data || pickResponse;
      }
      if (sequence !== this.sequence) return;
      const actions = header.allowed_actions || [];
      const pickActions = picking.allowed_actions || [];
      const meta = header.line_meta || {};
      const lines = (header.lines || []).map(line => delivery.lineView(line, mode));
      const version = mode === 'detail' ? header.business_version : picking.business_version;
      // Preserve cross-page edits only while their source version is unchanged. Refreshing a
      // changed document must not pair stale quantities with a newly accepted version.
      const changed = this.loadedVersion !== undefined && (this.loadedVersion !== version || (mode === 'redelivery' && this.loadedSourceVersion !== header.business_version));
      if (changed) this.entries = {};
      this.loadedVersion = version;
      this.loadedSourceVersion = header.business_version;
      lines.forEach(line => { const old = this.entries[line.id]; this.entries[line.id] = { line, draft: old ? old.draft : delivery.emptyDraft() }; });
      this.setData({ header, picking, pickingId: mode === 'create' ? picking.id : header.picking_task_id, loading: false, statusText: delivery.statuses[header.status] || header.status,
        total: Number(meta.total || lines.length), page: Number(meta.current_page || this.data.page), lastPage: Math.max(1, Number(meta.last_page || 1)),
        canCreate: mode !== 'detail' && pickActions.includes('delivery.create') && (mode !== 'redelivery' || actions.includes('delivery.create')),
        canReceive: mode === 'detail' && actions.includes('delivery.receive'), canDispatch: actions.includes('delivery.dispatch'), canDeliver: actions.includes('delivery.deliver'),
        canCancel: actions.includes('delivery.cancel'), canRedelivery: actions.includes('delivery.create') && (header.has_remaining_redelivery === true || lines.some(line => Number(line.remaining_redelivery_qty) > 0)),
        error: changed ? '单据已变化，本次数量已清空，请重新核对' : '', rows: lines });
      this.setData({ pendingAction: this.findPending() }); this.syncRows();
      if (this.data.canReceive) wx.setNavigationBarTitle({ title: '收料确认' });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, header: null, rows: [], error: page.errorText(error) }); });
  },
  syncRows() { this.setData({ rows: this.data.rows.map(line => Object.assign({}, line, { draft: clone(this.entries[line.id].draft) })) }); },
  turnPage(event) { const next = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && !this.data.busy && next > 0 && next <= this.data.lastPage) { this.setData({ page: next }); this.load(); } },
  input(event) {
    if (this.isLocked()) return;
    const { id, field } = event.currentTarget.dataset;
    if (!['delivery_qty', 'accepted_qty', 'rejected_qty', 'reject_reason'].includes(field) || !this.entries[id]) return;
    this.entries[id].draft[field] = event.detail.value; this.setData({ error: '' }); this.syncRows();
  },
  remarkInput(event) { if (!this.isLocked()) this.setData({ remark: event.detail.value }); },
  choosePerson() { if (!this.isLocked()) this.setData({ pickerOpen: true, pickerMode: 'people', pickerTitle: '选择配送员', pickerQuery: {}, pickerSelected: this.data.person ? [this.data.person] : [], pickerMultiple: false, pickerMax: 1 }); },
  chooseSerial(event) {
    if (this.isLocked()) return;
    const id = Number(event.currentTarget.dataset.id), entry = this.entries[id];
    if (!entry) return;
    this.activeLineId = id;
    const query = { picking_task_id: this.data.pickingId, picking_task_line_id: entry.line.pickingId };
    if (this.data.mode === 'redelivery') query.source_delivery_id = this.data.id;
    this.setData({ pickerOpen: true, pickerMode: 'serial', pickerTitle: '选择配送序列号', pickerQuery: query, pickerSelected: clone(entry.draft.serialRows), pickerMultiple: true, pickerMax: Math.floor(entry.line.remaining) });
  },
  closePicker() { this.setData({ pickerOpen: false }); },
  pickerConfirm(event) {
    if (this.isLocked()) return;
    const rows = event.detail.rows || [];
    if (this.data.pickerMode === 'people') this.setData({ person: rows[0] || null, personError: '' });
    else if (this.entries[this.activeLineId]) { const draft = this.entries[this.activeLineId].draft; draft.serialRows = clone(rows); draft.delivery_qty = String(rows.length); this.syncRows(); }
    this.closePicker();
  },
  openReceiptSerial(event) {
    if (this.isLocked() || !this.data.canReceive) return;
    const id = Number(event.currentTarget.dataset.id), entry = this.entries[id];
    if (entry) this.setData({ receiptOpen: true, receiptLineId: id, receiptSelected: clone(entry.draft.receiptRows), receiptUnit: entry.line.unit });
  },
  closeReceiptSerial() { this.setData({ receiptOpen: false }); },
  receiptConfirm(event) {
    if (this.isLocked()) return;
    const entry = this.entries[this.data.receiptLineId];
    if (!entry) return;
    try {
      const rows = event.detail.rows || [], values = delivery.receiptSelection(rows);
      entry.draft = Object.assign({}, entry.draft, values, { receiptRows: clone(rows) });
      this.setData({ receiptOpen: false, error: '' }); this.syncRows();
    } catch (error) { this.setData({ error: error.message }); }
  },
  acceptAll() {
    if (this.isLocked() || !this.data.canReceive) return;
    let serial = false;
    this.data.rows.forEach(line => {
      if (line.serial) { if (line.remaining > 0) serial = true; return; }
      Object.assign(this.entries[line.id].draft, { accepted_qty: String(line.remaining), rejected_qty: '0', reject_reason: '' });
    });
    this.syncRows(); this.setData({ error: serial ? '序列管理物料请逐个核对序列号，核对结果保持不变' : '' });
  },
  submit() {
    if (this.data.busy || this.data.loading) return;
    if (this.data.pendingAction) return this.execute(this.data.pendingAction, {});
    if (this.data.mode === 'detail' && !this.data.canReceive) return;
    if (this.data.mode !== 'detail' && !this.data.canCreate) return;
    if (this.data.mode !== 'detail' && !this.data.person) return this.setData({ personError: '请选择配送员' });
    try {
      const lines = delivery.payloadLines(this.entries, this.data.mode);
      const payload = { expected_version: this.loadedVersion, lines, remark: this.data.remark };
      if (this.data.mode !== 'detail') {
        payload.delivery_type = this.data.mode === 'redelivery' ? 'redelivery' : 'standard'; payload.delivery_user_legacy_id = Number(this.data.person.id);
        if (this.data.mode === 'redelivery') payload.source_delivery_id = this.data.id;
      }
      return this.execute(this.data.mode === 'detail' ? 'delivery.receive' : 'delivery.create', payload);
    } catch (error) { this.setData({ error: error.message }); }
  },
  transition(event) {
    if (this.isLocked()) return;
    const action = event.currentTarget.dataset.action;
    if (!(this.data.header.allowed_actions || []).includes(action) || !['delivery.dispatch', 'delivery.deliver'].includes(action)) return;
    return this.execute(action, { expected_version: this.data.header.business_version });
  },
  openCancel() { if (!this.isLocked() && this.data.canCancel) this.setData({ cancelOpen: true, cancelReason: '', cancelError: '' }); },
  closeCancel() { if (!this.data.busy) this.setData({ cancelOpen: false, cancelReason: '', cancelError: '' }); },
  cancelReasonInput(event) { this.setData({ cancelReason: event.detail.value, cancelError: '' }); },
  confirmCancel() {
    if (this.isLocked() || !this.data.canCancel) return;
    if (!this.data.cancelReason.trim()) return this.setData({ cancelError: '请填写取消原因' });
    return this.execute('delivery.cancel', { expected_version: this.data.header.business_version, reason: this.data.cancelReason.trim() });
  },
  execute(action, payload) {
    if (this.data.busy) return;
    this.setData({ busy: true, error: '', conflictOpen: false });
    const id = action === 'delivery.create' ? this.data.pickingId : this.data.id;
    let recovery;
    if (action === 'delivery.create') {
      recovery = delivery.creationStore();
      // Store the route before sending: after an unknown success, the source can disappear
      // from available-delivery queries, but the original immutable command still needs recovery.
      try {
        recovery.remember({ pickingId: id, mode: this.data.mode, sourceDeliveryId: this.data.mode === 'redelivery' ? this.data.id : 0,
          pickingNo: this.data.picking && this.data.picking.task_no || '', target: this.data.header && this.data.header.work_order && this.data.header.work_order.work_order_no || '' });
      } catch (error) { this.setData({ busy: false, error: page.errorText(error) }); return; }
    }
    return warehouse.submit(action, id, payload).then(result => {
      if (recovery) recovery.remove(id);
      this.entries = {}; this.loadedVersion = undefined;
      this.setData({ pendingAction: '', cancelOpen: false, cancelReason: '', cancelError: '', pickerOpen: false, receiptOpen: false, person: null, remark: '', page: 1 });
      if (action === 'delivery.create') { this.setData({ mode: 'detail', id: Number(result.id) }); wx.setNavigationBarTitle({ title: '配送详情' }); }
      return this.load();
    }).catch(error => { if (recovery && !error.pendingCommand) recovery.remove(id); this.setData({ error: page.errorText(error), pendingAction: error.pendingCommand ? action : '', conflictOpen: !!error.pendingCommand || error.statusCode === 409 }); })
      .finally(() => this.setData({ busy: false }));
  },
  closeConflict() { this.setData({ conflictOpen: false }); },
  refreshConflict() { this.setData({ conflictOpen: false }); if (this.data.pendingAction) return this.submit(); return this.load(); },
  redelivery() { if (!this.isLocked() && this.data.canRedelivery) wx.navigateTo({ url: `/pages/warehouse/delivery/index?mode=redelivery&id=${this.data.id}` }); },
  stop() {},
});
