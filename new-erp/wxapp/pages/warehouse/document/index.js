const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const view = require('../../../utils/warehouse-document');
const clone = value => JSON.parse(JSON.stringify(value));
let localId = 0;
const uid = () => `entry-${++localId}`;
Page({
  data: { kind: '', id: 0, stage: 'post', receiptId: 0, loading: true, busy: false, error: '', header: {}, headerFields: [], lines: [],
    page: 1, lastPage: 1, total: 0, actions: [], mainAction: '', mainLabel: '', locator: {}, locatorOpen: false, locatorTarget: '', quantity: '',
    fieldErrors: {}, allocationOpen: false, allocations: [], allocationLine: null, allocationError: '', pendingAction: '', conflictOpen: false,
    packages: [], packagesPage: 1, packagesLast: 1, receipt: null, readonly: false, relatedOpen: false,
    returnSerialOpen: false, returnSerialLine: {}, returnSerialValue: {}, returnSerialExcluded: [], returnSerialConflict: false, canPacking: false },
  onLoad(options) {
    this.drafts = {}; this.returnLineCache = {}; this.returnSerialQueue = [];
    const postedOutput = options.completed === '1' && ['output', 'cutting_product'].includes(options.kind);
    this.setData({ kind: options.kind, id: Number(postedOutput ? options.parent : options.id), stage: options.stage || 'post', receiptId: postedOutput ? Number(options.id) : 0 });
    this.load();
  },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return warehouse.document(this.data.kind, this.data.id, { stage: this.data.stage, receipt_id: this.data.receiptId || undefined, page: this.data.page, packages_page: this.data.packagesPage, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return;
      const result = response.data || response; const header = result.header; const paged = page.rows(result.lines);
      const actions = result.actions || []; const mainAction = actions.find(a => a !== 'purchase.allocate') || '';
      const lines = paged.rows.map(raw => {
        const row = view.line(raw);
        if (this.data.kind === 'sales_return' && this.data.stage === 'receive') {
          if (!this.returnLineCache) this.returnLineCache = {};
          this.returnLineCache[row.id] = row;
          if (!this.drafts[row.id]) this.drafts[row.id] = { sales_return_item_id: row.id, received_base_qty: '', restock_base_qty: '', pending_base_qty: '0', scrap_base_qty: '0', rejected_base_qty: '0', batch_no: row.serialTracked ? '' : row.sourceBatch, inspection_remark: '', locator: {}, serialRows: { restock: [], pending: [], scrap: [], rejected: [] }, serialReviewed: false };
          const draft = this.drafts[row.id];
          if (row.serialTracked !== draft.serialTracked || (row.remaining_receivable_qty !== undefined && Number(row.remaining_receivable_qty) !== Number(draft.remainingReceivable))) draft.serialReviewed = false;
          draft.serialTracked = row.serialTracked; draft.remainingReceivable = row.remaining_receivable_qty;
          row.draft = this.drafts[row.id];
        }
        return row;
      });
      const known = ({ purchase_receipt: ['purchase.allocate', 'purchase.post'], output: ['output.warehouse'], cutting_product: ['cutting.warehouse'],
        production_return: ['production_return.receive'], sales_return: [this.data.stage === 'receive' ? 'sales_return.receive' : 'sales_return.post'],
        sales_shipment: ['sales_shipment.post', 'sales_shipment.dispatch'], purchase_return: ['purchase_return.post'] })[this.data.kind] || [];
      const pendingAction = known.find(action => warehouse.pending(action, this.data.id)) || '';
      this.setData({ header, headerFields: view.header(this.data.kind, header, this.data.stage), lines, total: paged.total, page: paged.page, lastPage: paged.lastPage,
        canPacking: this.data.kind === 'sales_shipment' && (page.permissions()('sales_order.shipment.packing.execute') || page.permissions()('sales_order.shipment.packing.quality')),
        hasMaterialCost: header.material_total_cost !== undefined && header.material_total_cost !== null, actions, mainAction, mainLabel: view.actionLabels[mainAction] || '', canAllocate: actions.includes('purchase.allocate'),
        loading: false, pendingAction, receipt: result.receipt, readonly: !actions.length, item: header.item || {},
        quantity: this.data.quantity || page.quantity(header.remaining_qty), postingEligibility: result.posting_eligibility || null,
        packages: (result.packages && result.packages.data) || [], packagesTotal: (result.packages && result.packages.total) || 0, packagesLast: (result.packages && result.packages.last_page) || 1 });
      wx.setNavigationBarTitle({ title: this.data.kind === 'sales_return' && this.data.stage === 'receive' ? '销售退货收货' : mainAction === 'sales_shipment.dispatch' ? '销售发运' : view.titles[this.data.kind] || '仓库单据' });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  turnPage(event) { const next = this.data.page + Number(event.currentTarget.dataset.delta); if (next > 0 && next <= this.data.lastPage && !this.data.busy) { this.setData({ page: next }); this.load(); } },
  turnPackages(event) { const next = this.data.packagesPage + Number(event.currentTarget.dataset.delta); if (next > 0 && next <= this.data.packagesLast) { this.setData({ packagesPage: next }); this.load(); } },
  quantityInput(event) { if (!this.data.busy && !this.data.pendingAction) this.setData({ quantity: event.detail.value, fieldErrors: {} }); },
  returnInput(event) {
    if (this.data.busy || this.data.pendingAction) return;
    const { id, field } = event.currentTarget.dataset;
    if (!this.drafts[id] || !['received_base_qty', 'restock_base_qty', 'pending_base_qty', 'scrap_base_qty', 'rejected_base_qty', 'batch_no', 'inspection_remark'].includes(field)) return;
    if (this.drafts[id].serialTracked && field !== 'inspection_remark') return;
    this.drafts[id][field] = event.detail.value;
    this.setData({ lines: this.data.lines.map(row => row.id === Number(id) ? Object.assign({}, row, { draft: Object.assign({}, this.drafts[id]) }) : row), fieldErrors: {} });
  },
  openReturnSerial(event) {
    if (this.data.busy || this.data.pendingAction || this.data.mainAction !== 'sales_return.receive') return;
    const id = Number(event.currentTarget.dataset.id); const draft = this.drafts[id];
    if (!draft || !draft.serialTracked) return;
    this.returnSerialQueue = [id]; this.showReturnSerial(id, false);
  },
  showReturnSerial(id, conflict) {
    const row = this.returnLineCache && this.returnLineCache[id]; if (!row) return;
    const excluded = [];
    Object.values(this.drafts).forEach(draft => { if (Number(draft.sales_return_item_id) !== Number(id)) view.returnDispositions.forEach(disposition => (draft.serialRows && draft.serialRows[disposition] || []).forEach(serial => excluded.push(Number(serial.id)))); });
    this.setData({ returnSerialOpen: true, returnSerialLine: row, returnSerialValue: clone(this.drafts[id]), returnSerialExcluded: excluded, returnSerialConflict: !!conflict });
  },
  confirmReturnSerial(event) {
    if (this.data.busy || this.data.pendingAction || !this.data.returnSerialOpen) return;
    const result = event.detail; const id = Number(result.line_id); const draft = this.drafts[id];
    if (!draft || Number(this.data.returnSerialLine.id) !== id || !draft.serialTracked) return;
    try {
      const selection = view.returnSerialSelection(result.serialRows, draft.remainingReceivable);
      Object.assign(draft, selection, { serialReviewed: true }); draft.serialReviewFingerprint = view.returnSerialFingerprint(draft);
      this.setData({ lines: this.data.lines.map(row => Object.assign({}, row, { draft: this.drafts[row.id] })), error: '', returnSerialConflict: false });
      this.returnSerialQueue = (this.returnSerialQueue || []).filter(value => Number(value) !== id);
      if (this.returnSerialQueue.length) this.showReturnSerial(this.returnSerialQueue[0], false);
      else this.setData({ returnSerialOpen: false });
    } catch (error) { this.setData({ error: page.errorText(error) }); }
  },
  cancelReturnSerial() { if (this.data.busy || this.data.pendingAction) return; this.returnSerialQueue = []; this.setData({ returnSerialOpen: false, returnSerialConflict: false }); },
  openLocator(event) {
    if (this.data.busy || this.data.pendingAction) return;
    const target = event.currentTarget.dataset.target || 'output'; const index = Number(event.currentTarget.dataset.index || 0); const id = Number(event.currentTarget.dataset.id || 0);
    const value = target === 'allocation' ? this.data.allocations[index].locator : target === 'return' ? this.drafts[id].locator : this.data.locator;
    this.setData({ locatorOpen: true, locatorTarget: target, locatorIndex: index, locatorLine: id, locatorValue: value });
  },
  chooseLocator(event) {
    const value = event.detail;
    if (this.data.locatorTarget === 'allocation') {
      const allocations = clone(this.data.allocations); allocations[this.data.locatorIndex].locator = value; this.setData({ allocations });
    } else if (this.data.locatorTarget === 'return') {
      this.drafts[this.data.locatorLine].locator = value;
      this.setData({ lines: this.data.lines.map(row => Object.assign({}, row, { draft: this.drafts[row.id] })) });
    } else this.setData({ locator: value });
    this.setData({ locatorOpen: false, fieldErrors: {}, allocationError: '' });
  }, closeLocator() { this.setData({ locatorOpen: false }); },
  openAllocation(event) {
    if (this.data.busy || this.data.pendingAction) return;
    const row = this.data.lines.find(row => row.id === Number(event.currentTarget.dataset.id)); if (!row || !this.data.canAllocate) return;
    const allocations = row.allocations.map(a => ({ uid: uid(), locator: { warehouse_id: a.warehouse_id, location_id: a.location_id,
      warehouse_name: a.warehouse && a.warehouse.warehouse_name, location_name: a.location && a.location.location_name }, base_qty: page.quantity(a.base_qty),
      serialText: (a.serial_nos || []).join('\n'), physical_entries: a.physical_entries.map(p => ({ uid: uid(), dimensions: clone(p.dimensions) })) }));
    this.setData({ allocationLine: row, allocations: allocations.length ? allocations : [{ uid: uid(), locator: {}, base_qty: '', serialText: '', physical_entries: [] }], allocationOpen: true, allocationError: '' });
  },
  closeAllocation() { if (!this.data.busy) this.setData({ allocationOpen: false, allocations: [], allocationLine: null, allocationError: '', locatorOpen: false }); },
  allocationInput(event) {
    if (this.data.busy || this.data.pendingAction) return;
    const { index, piece, field } = event.currentTarget.dataset; const rows = clone(this.data.allocations);
    if (piece !== undefined) rows[Number(index)].physical_entries[Number(piece)].dimensions[field] = event.detail.value;
    else rows[Number(index)][field] = event.detail.value;
    this.setData({ allocations: rows, allocationError: '' });
  },
  addAllocation() { if (this.data.busy || this.data.pendingAction) return; this.setData({ allocations: this.data.allocations.concat([{ uid: uid(), locator: {}, base_qty: '', serialText: '', physical_entries: [] }]) }); },
  removeAllocation(event) { this.setData({ allocations: this.data.allocations.filter((_, i) => i !== Number(event.currentTarget.dataset.index)) }); },
  addPhysical(event) { const rows = clone(this.data.allocations); rows[Number(event.currentTarget.dataset.index)].physical_entries.push({ uid: uid(), dimensions: { length_mm: '', width_mm: '', thickness_mm: '' } }); this.setData({ allocations: rows }); },
  removePhysical(event) { const rows = clone(this.data.allocations); rows[Number(event.currentTarget.dataset.index)].physical_entries.splice(Number(event.currentTarget.dataset.piece), 1); this.setData({ allocations: rows }); },
  saveAllocation() {
    if (this.data.busy) return;
    if (!this.data.allocations.length || this.data.allocations.some(a => !a.locator.location_id || !Number(a.base_qty))) return this.setData({ allocationError: '请填写每项分配的仓库、库位和数量' });
    const payload = { items: [{ receipt_item_id: this.data.allocationLine.id, expected_revision: this.data.allocationLine.allocation_revision, allocations: this.data.allocations.map(a => ({ warehouse_id: a.locator.warehouse_id,
      location_id: a.locator.location_id, base_qty: a.base_qty, physical_entries: a.physical_entries.map(p => ({ dimensions: p.dimensions })), serial_nos: a.serialText.split(/[\n,，]+/).map(s => s.trim()).filter(Boolean) })) }] };
    return this.execute('purchase.allocate', payload);
  },
  submit() {
    if (this.data.busy) return;
    if (this.data.pendingAction) return this.execute(this.data.pendingAction, {});
    const action = this.data.mainAction; if (!action) return;
    let payload = {};
    if (['output.warehouse', 'cutting.warehouse'].includes(action)) {
      const errors = {};
      if (!this.data.locator.location_id) errors.locator = '请选择仓库和库位';
      if (!(Number(this.data.quantity) > 0)) errors.quantity = '请输入大于0的本次入库数量';
      if (!this.data.header.batch_no) errors.batch = '来源批次缺失，请核对原单据';
      this.setData({ fieldErrors: errors }); if (Object.keys(errors).length) return;
      payload = { expected_version: this.data.header.business_version, warehouse_id: this.data.locator.warehouse_id, location_id: this.data.locator.location_id,
        batch_no: this.data.header.batch_no, [action === 'output.warehouse' ? 'posted_base_qty' : 'quantity']: this.data.quantity };
    } else if (action === 'production_return.receive') payload = { expected_version: this.data.header.business_version };
    else if (action === 'sales_return.receive') {
      const unreviewed = Object.values(this.drafts).filter(draft => draft.serialTracked && (!draft.serialReviewed || draft.serialReviewFingerprint !== view.returnSerialFingerprint(draft)));
      if (unreviewed.length) { this.returnSerialQueue = unreviewed.map(draft => Number(draft.sales_return_item_id)); this.showReturnSerial(this.returnSerialQueue[0], false); return; }
      let items;
      try { items = view.returnReceiptItems(this.drafts); } catch (error) { this.setData({ error: page.errorText(error) }); return; }
      if (!items.length) return this.setData({ error: '请填写本次实际收到的数量' });
      payload = { items };
    }
    return this.execute(action, payload);
  },
  execute(action, payload) {
    this.setData({ busy: true, error: '', allocationError: '', conflictOpen: false });
    return warehouse.submit(action, this.data.id, payload).then(result => {
      this.setData({ pendingAction: '', allocationOpen: false, allocations: [], allocationLine: null, fieldErrors: {}, locatorOpen: false, quantity: '', locator: {} });
      if (action === 'output.warehouse') this.setData({ receiptId: result.posting_id });
      if (action === 'cutting.warehouse') this.setData({ receiptId: result.receipt_id });
      if (action === 'sales_return.receive') { this.drafts = {}; this.returnLineCache = {}; this.returnSerialQueue = []; this.setData({ id: result.id, stage: 'post', page: 1, returnSerialOpen: false, returnSerialLine: {}, returnSerialValue: {}, returnSerialConflict: false }); }
      return this.load();
    }).catch(error => {
      const details = Object.values((error.details && error.details.errors) || error.errors || {}).flat().join('；');
      this.setData({ error: details || page.errorText(error), allocationError: action === 'purchase.allocate' ? details || page.errorText(error) : '',
        pendingAction: error.pendingCommand ? action : '', conflictOpen: !!error.pendingCommand || error.statusCode === 409 });
      if (action === 'sales_return.receive' && !error.pendingCommand && /序列|原批次/.test(details || page.errorText(error))) {
        const drafts = Object.values(this.drafts).filter(draft => draft.serialTracked && Number(draft.received_base_qty) > 0);
        drafts.forEach(draft => { draft.serialReviewed = false; }); this.returnSerialQueue = drafts.map(draft => Number(draft.sales_return_item_id));
        if (this.returnSerialQueue.length) { this.setData({ conflictOpen: false }); this.showReturnSerial(this.returnSerialQueue[0], true); }
      }
    }).finally(() => this.setData({ busy: false }));
  },
  closeConflict() { this.setData({ conflictOpen: false }); },
  refreshConflict() { this.setData({ conflictOpen: false }); if (this.data.pendingAction) return this.submit(); return this.load(); },
  openRelated() { this.setData({ relatedOpen: true }); }, closeRelated() { this.setData({ relatedOpen: false }); },
  openPacking() { if (this.data.canPacking && !this.data.busy) wx.navigateTo({ url: `/pages/production/shipment-packing/index?shipment_id=${this.data.id}` }); },
  back: page.back, stop() {},
});
