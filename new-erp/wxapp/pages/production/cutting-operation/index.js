const cutting = require('../../../services/production-cutting');
const production = require('../../../services/production');
function object(value) { if (!value) return {}; if (typeof value === 'object') return value; try { return JSON.parse(value); } catch (_) { return {}; } }
function clock(seconds) { const n = Math.max(0, Math.floor(seconds || 0)); return [Math.floor(n / 3600), Math.floor(n / 60) % 60, n % 60].map(v => String(v).padStart(2, '0')).join(':'); }
function sizeText(size) { return [size.length_mm || size.required_length_mm, size.width_mm || size.required_width_mm, size.thickness_mm || size.required_thickness_mm].filter(v => Number(v) > 0).map(v => String(Number(v))).join(' × ') + ' mm'; }
function otherSummary(rows) { return rows.length ? rows.map(r => r.result_type === 'usable_remnant' ? `${sizeText(object(r.measurements))} · ${Number(r.actual_qty)}${object(r.measurements).width_mm ? '块' : '根'}` : `${Number(r.actual_qty)}${r.result_type === 'scrapped_output' ? '件' : ' kg'}`).join('；') : '未登记'; }

Page({
  data: { taskId: 0, targetType: '', targetId: 0, loading: true, busy: false, detail: {}, target: {}, source: {}, batches: [], batchPage: 1, batchLastPage: 1,
    step: 1, expanded: false, actualQty: '', otherResults: [], remnantSummary: '未登记', lossSummary: '未登记', sizeLabel: '', timer: '00:00:00', minutes: '0.0',
    showMaterials: false, keyword: '', categories: [], categoryId: '', categoryPage: 1, categoryLastPage: 1, candidates: [], page: 1, lastPage: 1, selected: null, rootQty: '1',
    showOther: false, otherType: 'usable_remnant', otherDraft: {}, editingIndex: -1, otherRows: [], error: '', completed: false, dirty: false, disposition: 'direct_handover' },
  onLoad(options) { this.setData({ taskId: Number(options.taskId), targetType: options.targetType, targetId: Number(options.targetId) }); this.batchId = Number(options.batchId || 0); return this.load(); },
  onShow() { if (this.ready && !this.data.dirty) this.load(); else if (this.ready) { clearInterval(this.ticker); this.ticker = setInterval(() => this.updateTimer(), 1000); } },
  onHide() { clearInterval(this.ticker); },
  onUnload() { clearInterval(this.ticker); this.materialSequence = (this.materialSequence || 0) + 1; this.categorySequence = (this.categorySequence || 0) + 1; },
  onPullDownRefresh() { if (this.data.dirty) { wx.stopPullDownRefresh(); return wx.showToast({ title: '请先保存当前结果', icon: 'none' }); } return this.load().finally(() => wx.stopPullDownRefresh()); },
  args() { return [this.data.taskId, this.data.targetType, this.data.targetId]; },
  load(preserveDraft = false) {
    const draft = preserveDraft && this.data.dirty ? { actualQty: this.data.actualQty, otherResults: this.data.otherResults,
      remnantSummary: this.data.remnantSummary, lossSummary: this.data.lossSummary, step: this.data.step, dirty: true } : null;
    return cutting.show(...this.args(), { settlement_batch_id: this.batchId || undefined, batch_page: this.data.batchPage }).then(response => {
      const detail = response.data || response; const target = detail.target || {}; const page = detail.batches || {}; const batch = detail.current_batch;
      const batches = (page.data || []).map(row => Object.assign({}, row, { dimensions: object(row.dimensions), label: row.physical_no || row.batch_no, quantityLabel: String(Number(row.input_qty)) }));
      const source = Object.assign({}, batches.find(row => batch && row.id === batch.id) || {}, batch || {});
      source.quantityLabel = String(Number(source.input_qty || 0));
      source.dimensions = object(source.dimensions); source.sizeLabel = source.physical_material_id ? sizeText(source.dimensions) : `${Number(source.standard_stock_length_mm || 0)} mm`;
      const rows = detail.results || []; const product = rows.find(row => row.result_type === 'product');
      const others = rows.filter(row => row.result_type !== 'product').map(row => ({ client_row_id: row.client_row_id, result_type: row.result_type, actual_qty: String(row.actual_qty), measurement_status: row.measurement_status, measurements: object(row.measurements) }));
      this.batchId = batch ? batch.id : 0;
      const completed = !!target.completed_at || ['COMPLETED', 'WAIT_QUALITY', 'WAIT_WAREHOUSE'].includes(target.status);
      const requirement = detail.requirement || detail.dimensions || {};
      this.setData({ detail, target, source, batches, batchLastPage: page.meta && page.meta.last_page || 1,
        actualQty: product ? String(Number(product.actual_qty)) : (others.length ? '0' : ''), otherResults: others,
        sizeLabel: sizeText(requirement), remnantSummary: otherSummary(others.filter(r => r.result_type === 'usable_remnant')),
        lossSummary: otherSummary(others.filter(r => r.result_type !== 'usable_remnant')),
        step: completed ? 3 : (batch ? Math.max(2, this.data.step) : 1), completed, dirty: false, loading: false, error: '',
        disposition: target.output_mode_snapshot === 'warehouse_required' ? 'warehouse' : this.data.disposition });
      if (draft && !completed) this.setData(draft);
      this.ready = true; this.updateTimer(); clearInterval(this.ticker); this.ticker = setInterval(() => this.updateTimer(), 1000);
    }).catch(error => { this.setData({ loading: false, error: error.message || '加载失败' }); });
  },
  updateTimer() {
    const labor = this.data.target.my_labor || {};
    if (this.clockBase !== this.data.detail.server_now) { this.clockBase = this.data.detail.server_now; this.localBase = Date.now(); }
    // The server includes the active session through server_now; add only time since this response.
    const running = labor.status === 'ACTIVE' ? Math.max(0, (Date.now() - this.localBase) / 1000) : 0;
    const seconds = Number(labor.accumulated_seconds || 0) + running;
    this.setData({ timer: clock(seconds), minutes: (seconds / 60).toFixed(1) });
  },
  run(action, success) {
    if (this.data.busy) return Promise.resolve(false);
    this.setData({ busy: true, error: '' });
    return action().then(async result => { if (success) await success(result.data || result); return true; })
      .catch(error => { this.setData({ error: error.message || '操作失败，请重试' }); return false; }).finally(() => this.setData({ busy: false }));
  },
  toggleTimer() {
    const target = this.data.target; const method = target.my_labor && target.my_labor.status === 'ACTIVE' ? 'pause' : (target.started_at ? 'resume' : 'start');
    return this.run(() => production[method](...this.args(), { expected_version: target.business_version }), () => this.load(true));
  },
  toggleSource() { this.setData({ expanded: !this.data.expanded }); },
  async openMaterials() {
    if (this.data.dirty) return wx.showToast({ title: '请先保存当前结果', icon: 'none' });
    if (!this.data.detail.operation_id) {
      const prepared = await this.run(() => cutting.prepare(...this.args(), { expected_version: this.data.target.business_version }), () => this.load());
      if (!prepared) return;
    }
    this.setData({ showMaterials: true, selected: null, keyword: '', categoryId: '', categoryPage: 1, categories: [], page: 1 });
    return Promise.all([this.loadCategories(), this.loadMaterials()]);
  },
  closeMaterials() { if (!this.data.busy) { this.materialSequence = (this.materialSequence || 0) + 1; this.categorySequence = (this.categorySequence || 0) + 1; this.setData({ showMaterials: false }); } },
  noop() {},
  loadCategories() {
    const sequence = this.categorySequence = (this.categorySequence || 0) + 1; const page = this.data.categoryPage;
    return cutting.materials(...this.args(), { mode: 'categories', page }).then(r => {
      if (sequence === this.categorySequence) this.setData({ categories: (page === 1 ? [] : this.data.categories).concat(r.data || []), categoryLastPage: r.meta.last_page });
    }).catch(e => { if (sequence === this.categorySequence) this.setData({ error: e.message }); });
  },
  loadMaterials() {
    const sequence = this.materialSequence = (this.materialSequence || 0) + 1;
    return cutting.materials(...this.args(), { keyword: this.data.keyword, category_id: this.data.categoryId || undefined, page: this.data.page }).then(r => {
      if (sequence === this.materialSequence) this.setData({ candidates: (r.data || []).map(row => Object.assign({}, row, { key: `${row.production_input_holding_id}:${row.physical_material_id || 0}`, availableLabel: String(Number(row.available_qty)), sizeLabel: row.physical_material_id ? sizeText(object(row.dimensions)) : `${Number(row.standard_stock_length_mm)} mm` })), lastPage: r.meta.last_page });
    }).catch(e => { if (sequence === this.materialSequence) this.setData({ error: e.message }); });
  },
  onSearch(event) { this.materialSequence = (this.materialSequence || 0) + 1; this.setData({ keyword: event.detail.value, candidates: [] }); },
  search() { this.setData({ page: 1 }); return this.loadMaterials(); },
  category(event) { this.setData({ categoryId: event.currentTarget.dataset.id || '', page: 1 }); return this.loadMaterials(); },
  materialPage(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (page < 1 || page > this.data.lastPage) return; this.setData({ page }); return this.loadMaterials(); },
  categoryMore() { if (this.data.categoryPage >= this.data.categoryLastPage) return; this.setData({ categoryPage: this.data.categoryPage + 1 }); return this.loadCategories(); },
  chooseMaterial(event) { const selected = this.data.candidates.find(row => row.key === event.currentTarget.dataset.key); this.setData({ selected, rootQty: '1' }); },
  onRootQty(event) { this.setData({ rootQty: event.detail.value }); },
  useMaterial() {
    const selected = this.data.selected; if (!selected) return;
    const payload = { expected_version: this.data.target.business_version, production_input_holding_id: selected.production_input_holding_id };
    if (selected.physical_material_id) payload.physical_material_id = selected.physical_material_id;
    else payload.quantity = this.data.rootQty;
    return this.run(() => cutting.useMaterial(...this.args(), payload), result => { this.batchId = result.settlement_batch_id; this.setData({ showMaterials: false, step: 2 }); return this.load(); });
  },
  selectBatch(event) {
    if (this.data.dirty) return wx.showToast({ title: '请先保存当前结果', icon: 'none' });
    this.batchId = Number(event.currentTarget.dataset.id); this.setData({ step: 2 }); return this.load();
  },
  batchPage(event) { const page = this.data.batchPage + Number(event.currentTarget.dataset.delta); if (page < 1 || page > this.data.batchLastPage || this.data.dirty) return; this.batchId = 0; this.setData({ batchPage: page }); return this.load(); },
  onQuantity(event) { this.setData({ actualQty: event.detail.value, dirty: true }); },
  bumpQuantity(event) { this.setData({ actualQty: String(Math.max(0, Number(this.data.actualQty || 0) + Number(event.currentTarget.dataset.delta))), dirty: true }); },
  openOther(event) {
    if (this.data.completed) return;
    const type = event.currentTarget.dataset.type;
    this.setData({ showOther: true, otherType: type, otherRows: this.data.otherResults.filter(row => type === 'usable_remnant' ? row.result_type === type : row.result_type !== 'usable_remnant'),
      editingIndex: -1, otherDraft: { result_type: type === 'usable_remnant' ? type : 'process_loss', actual_qty: type === 'usable_remnant' ? '1' : '', length_mm: '', width_mm: '', weight_kg: '' } });
  },
  closeOther() { this.setData({ showOther: false }); },
  otherField(event) { this.setData({ [`otherDraft.${event.currentTarget.dataset.field}`]: event.detail.value }); },
  lossType(event) { this.setData({ 'otherDraft.result_type': event.currentTarget.dataset.type }); },
  editOther(event) { const index = Number(event.currentTarget.dataset.index); const row = this.data.otherRows[index]; this.setData({ editingIndex: index, otherDraft: Object.assign({}, row, object(row.measurements)) }); },
  removeOther(event) { const index = Number(event.currentTarget.dataset.index); this.setData({ otherRows: this.data.otherRows.filter((_, i) => i !== index), editingIndex: -1 }); },
  saveOtherRow() {
    const d = this.data.otherDraft; if (!/^\d+(\.\d{1,8})?$/.test(String(d.actual_qty)) || Number(d.actual_qty) <= 0) return wx.showToast({ title: '请填写大于零的实际数量', icon: 'none' });
    if (d.result_type === 'usable_remnant' && Number(d.actual_qty) !== 1) return wx.showToast({ title: '每块或每根余料请单独登记', icon: 'none' });
    const measured = {}; const sheet = !!this.data.source.physical_material_id;
    if (d.result_type === 'usable_remnant' || d.result_type === 'scrapped_output') {
      if (!(Number(d.length_mm) > 0) || (sheet && !(Number(d.width_mm) > 0))) return wx.showToast({ title: '请填写实际尺寸', icon: 'none' });
      measured.length_mm = d.length_mm; if (sheet) { measured.width_mm = d.width_mm; measured.thickness_mm = this.data.detail.requirement.input.actual_thickness_mm; measured.shape = 'RECTANGLE'; }
    } else measured.weight_kg = d.actual_qty;
    const row = { client_row_id: d.client_row_id || `other-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`, result_type: d.result_type, actual_qty: String(d.actual_qty), measurement_status: 'MEASURED', measurements: measured };
    const rows = this.data.otherRows.slice(); if (this.data.editingIndex >= 0) rows[this.data.editingIndex] = row; else rows.push(row);
    this.setData({ otherRows: rows, editingIndex: -1, otherDraft: { result_type: this.data.otherType === 'usable_remnant' ? 'usable_remnant' : 'process_loss', actual_qty: '', length_mm: '', width_mm: '' } });
  },
  confirmOthers() {
    const retained = this.data.otherResults.filter(row => this.data.otherType === 'usable_remnant' ? row.result_type !== 'usable_remnant' : row.result_type === 'usable_remnant');
    const rows = retained.concat(this.data.otherRows);
    this.setData({ otherResults: rows, showOther: false, step: 2, dirty: true, remnantSummary: otherSummary(rows.filter(r => r.result_type === 'usable_remnant')), lossSummary: otherSummary(rows.filter(r => r.result_type !== 'usable_remnant')) });
  },
  saveResult(review = true) {
    if (!/^\d+(\.\d{1,8})?$/.test(String(this.data.actualQty))) return wx.showToast({ title: '请填写实际产出数量', icon: 'none' });
    if (Number(this.data.actualQty) === 0 && !this.data.otherResults.length) return wx.showToast({ title: '零产出时请登记实际损耗或余料', icon: 'none' });
    return this.run(() => cutting.save(...this.args(), this.batchId, { expected_version: this.data.source.business_version, actual_qty: this.data.actualQty, other_results: this.data.otherResults }),
      () => { this.setData({ step: review ? 3 : 2 }); return this.load(); });
  },
  next() { if (this.data.step === 1) { if (!this.batchId) return this.openMaterials(); this.setData({ step: 2 }); return; } return this.saveResult(true); },
  saveAndAdd() { return Promise.resolve(this.saveResult(false)).then(saved => { if (saved) return this.openMaterials(); }); },
  previous() { this.setData({ step: Math.max(1, this.data.step - 1), expanded: this.data.step === 2 }); },
  chooseDisposition(event) { this.setData({ disposition: event.currentTarget.dataset.value }); },
  finish() { if (this.data.dirty) return this.saveResult(true); return this.run(() => cutting.finish(...this.args(), { expected_version: this.data.target.business_version, disposition: this.data.disposition }), () => this.load()); },
  drawings() { return wx.navigateTo({ url: `/pages/production/work-order-form/index?id=${this.data.detail.work_order_id}&tab=technical` }); },
  back() { wx.navigateBack(); },
});
