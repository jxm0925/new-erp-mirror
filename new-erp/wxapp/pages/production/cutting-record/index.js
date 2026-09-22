const cutting = require('../../../services/cutting');
const production = require('../../../services/production');
const { pendingCommands } = require('../../../utils/cutting-command');

const OTHER_DEFS = [
  { type: 'usable_remnant', label: '可用余料', icon: 'coupon-o', unit: '块' },
  { type: 'recyclable_scrap', label: '可回收废料', icon: 'replay', unit: 'kg' },
  { type: 'process_loss', label: '工艺损耗', icon: 'setting-o', unit: 'kg' },
  { type: 'scrapped_output', label: '报废产出', icon: 'delete-o', unit: '片' },
];

function dataOf(response) { return response && response.data !== undefined ? response.data : (response || {}); }
function pageOf(value) {
  const page = value || {}; const meta = page.meta || {};
  return { rows: Array.isArray(page.data) ? page.data : [], currentPage: Number(meta.current_page || 1), lastPage: Number(meta.last_page || 1), total: Number(meta.total || 0) };
}
function can(code) { const p = wx.getStorageSync('erp_permissions') || []; return Array.isArray(p) ? p.includes(code) : p[code] === true; }
function numberValue(value) { const result = Number(value || 0); return Number.isFinite(result) ? result : 0; }
function parseMeasurements(value) {
  if (!value) return {};
  if (typeof value === 'object') return value;
  try { return JSON.parse(value) || {}; } catch (_) { return {}; }
}
function routeLabel(route) {
  if (route.route_type === 'WAREHOUSE') return '入库';
  return route.target_label || [route.order_no || route.work_order_no, route.operation_name || route.task_no, route.unit_no].filter(Boolean).join(' / ') || '订单需求';
}
function normalizeProduct(item, index) {
  const measurements = parseMeasurements(item.measurements);
  const configuration = parseMeasurements(item.configuration_dimensions);
  const requirement = parseMeasurements(item.cutting_requirement_snapshot);
  const pending = pendingCommands('production/cutting/routes/');
  const routes = (item.routes || []).filter(route => route.status !== 'CANCELLED').map(route => Object.assign({}, route, {
    quantity: String(route.quantity), target_label: routeLabel(route), editable: !route.status || route.status === 'PLANNED',
    can_dispatch: ['WAIT_DISPATCH', 'PART_DISPATCHED', 'PART_RECEIVED'].includes(route.status) && numberValue(route.quantity) > numberValue(route.handed_over_qty),
    pending_transfer: pending.some(command => command.path === `production/cutting/routes/${route.id}/dispatch`),
  }));
  const assigned = routes.reduce((sum, route) => sum + numberValue(route.quantity), 0);
  return Object.assign({}, item, {
    client_row_id: item.client_row_id || `product-${item.id || index}-${Date.now()}`,
    actual_qty: String(item.actual_qty == null ? '' : item.actual_qty),
    item_name: item.item_name || item.item_code || '正式产出',
    measurements,
    cutting_requirement_snapshot: requirement,
    bom_item_id: item.bom_item_id || requirement.bom_item_id,
    per_output_piece_qty: item.per_output_piece_qty || requirement.per_output_piece_qty,
    dimensions_locked: !!(item.required_length_mm || requirement.required_length_mm),
    required_length_mm: item.required_length_mm || requirement.required_length_mm,
    required_width_mm: item.required_width_mm || requirement.required_width_mm,
    required_thickness_mm: item.required_thickness_mm || requirement.required_thickness_mm,
    measure_length_mm: String(item.cut_length_mm || measurements.length_mm || configuration.length_mm || ''),
    measure_width_mm: String(measurements.width_mm || configuration.width_mm || ''),
    measure_weight_kg: String(measurements.weight_kg || ''),
    routes, assigned_qty_ui: assigned, unassigned_qty_ui: Math.max(0, numberValue(item.actual_qty) - assigned),
  });
}
function normalizeOthers(rows, mode = 'sheet') {
  return OTHER_DEFS.reduce((all, definition) => {
    const def = Object.assign({}, definition);
    if (mode === 'length' && def.type === 'usable_remnant') def.unit = '根';
    if (mode === 'length' && def.type === 'scrapped_output') def.unit = '件';
    const matches = rows.filter(row => row.result_type === def.type || row.type === def.type);
    return all.concat((matches.length ? matches : [{}]).map(existing => {
    const measurements = parseMeasurements(existing.measurements);
    const measurementStatus = existing.measurement_status || 'NOT_RECORDED';
    let summary = '未登记';
    if (measurementStatus === 'NOT_MEASURED') summary = '未测量';
    if (measurementStatus === 'MEASURED') {
      if (def.type === 'usable_remnant') summary = [measurements.length_mm, measurements.width_mm, measurements.thickness_mm].filter(v => v !== undefined && v !== '').join(' × ') + ` mm · ${existing.actual_qty || 0}${def.unit}`;
      else if (def.type === 'recyclable_scrap' || def.type === 'process_loss') summary = `实际称量：${measurements.weight_kg || existing.actual_qty || 0} kg`;
      else summary = `${existing.actual_qty || 0}${def.unit}`;
    }
    return Object.assign({}, def, { id: existing.id, business_version: existing.business_version,
      touched: existing.touched !== undefined ? existing.touched : !!existing.client_row_id,
      client_row_id: existing.client_row_id || `other-${def.type}`,
      measurement_status: measurementStatus, actual_qty: existing.actual_qty == null ? '' : String(existing.actual_qty),
      measurements, summary });
    }));
  }, []);
}
function otherCards(rows) {
  return OTHER_DEFS.map(def => {
    const saved = rows.filter(row => row.type === def.type && row.touched !== false);
    return Object.assign({}, def, { client_row_id: `other-card-${def.type}`, count: saved.length,
      measurement_status: saved.some(row => row.measurement_status === 'MEASURED') ? 'MEASURED' : 'NOT_RECORDED',
      summary: saved.length ? `已登记 ${saved.length} 条 · ${saved.map(row => row.summary).join('；')}` : '未登记' });
  });
}
function blankOther(type, mode) {
  const row = normalizeOthers([{ type, client_row_id: `other-${type}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`, touched: false }], mode)
    .find(item => item.type === type);
  if (type === 'usable_remnant') row.actual_qty = '1';
  return row;
}
function recomputeProducts(products) {
  return products.map(item => {
    const assigned = (item.routes || []).reduce((sum, route) => sum + numberValue(route.quantity), 0);
    const next = Object.assign({}, item, { assigned_qty_ui: assigned, unassigned_qty_ui: Math.max(0, numberValue(item.actual_qty) - assigned) });
    if (numberValue(item.per_output_piece_qty) > 0) next.piece_qty = String(numberValue(item.actual_qty) * numberValue(item.per_output_piece_qty));
    return next;
  });
}
function dimensionsLabel(row) {
  const size = [row.required_length_mm, row.required_width_mm, row.required_thickness_mm].filter(value => numberValue(value) > 0).map(value => String(Number(value))).join(' × ');
  return size ? `${size} mm${row.bom_version ? ` · ${row.bom_version}` : ''}` : (row.drawing_reference || row.spec || '-');
}
function productDimensionError(product, mode) {
  if (!product.dimensions_locked) return '';
  const m = product.measurements || {};
  const length = mode === 'length' ? product.cut_length_mm : m.length_mm;
  if (numberValue(length) !== numberValue(product.required_length_mm)) return '产出长度与所选规格不一致，请重新选择产出';
  if (mode === 'sheet' && numberValue(m.width_mm) !== numberValue(product.required_width_mm)) return '产出宽度与所选规格不一致，请重新选择产出';
  return '';
}

Page({
  data: {
    orderId: 0, settlementId: 0, loading: true, busy: false,
    order: {}, source: {}, products: [], others: [], otherCards: otherCards([]), statusLabel: '-', editableResults: false, editableRoutes: false,
    showOutput: false, outputKeyword: '', outputCategories: [], outputCategoryId: '', outputCandidates: [], outputPage: 1, outputLastPage: 1,
    selectedOutput: null, outputQty: '1', outputLoading: false,
    showOther: false, otherDraft: {}, otherType: '', otherSessionRows: [], otherEditIndex: -1,
    showSplit: false, splitIndex: -1, splitProduct: {}, routeType: 'NEXT_OPERATION', routeQty: '', targets: [], targetIndex: -1, targetKeyword: '', targetLoading: false,
    categoryPage: 1, categoryLastPage: 1, targetPage: 1, targetLastPage: 1,
    confirmation: null, canConfirm: false, canInspect: false, canDispatch: false, canReturn: false,
    pendingSave: false, error: '', outputLength: '', outputWidth: '', outputWeight: '', canAutoConfirm: false,
    showTransfer: false, transfer: {},
  },
  onLoad(options) {
    this.setData({ orderId: Number(options.orderId || 0), settlementId: Number(options.settlementId || options.id || 0) });
    const actor = wx.getStorageSync('erp_user') || {};
    this.jobKey = `cutting-save-job:${actor.legacy_id || actor.id || 'session'}:${this.data.settlementId}`;
    this.pendingJob = wx.getStorageSync(this.jobKey) || null;
    return this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  noop() {},
  openTransfer(event) {
    if (this.data.busy) return;
    const id = Number(event.currentTarget.dataset.id);
    const route = this.data.products.flatMap(product => product.routes || []).find(row => Number(row.id) === id);
    if (!route) return;
    if (route.route_type !== 'NEXT_OPERATION' || !this.data.canDispatch || (!route.can_dispatch && !route.pending_transfer)) return;
    const quantity = String(numberValue(route.quantity) - numberValue(route.handed_over_qty));
    // Recover an uncertain write using its exact original payload even after a restart.
    const actor = wx.getStorageSync('erp_user') || {};
    const path = `production/cutting/routes/${id}/dispatch`;
    const pending = wx.getStorageSync(`cutting-pending:${actor.legacy_id || actor.id || 'session'}:POST:${path}`);
    const payload = pending && pending.payload;
    this.setData({ showTransfer: true, transfer: Object.assign({ id, target_label: route.target_label, quantity, max: Number(quantity), expected_version: route.business_version }, payload || {}, { locked: !!payload }) });
  },
  closeTransfer() { if (!this.data.busy) this.setData({ showTransfer: false }); },
  onTransferField(event) { if (!this.data.busy && !this.data.transfer.locked) this.setData({ [`transfer.${event.currentTarget.dataset.field}`]: event.detail.value }); },
  submitTransfer() {
    if (this.data.busy) return;
    const row = this.data.transfer;
    if (!/^\d+(\.\d{1,8})?$/.test(String(row.quantity)) || numberValue(row.quantity) <= 0 || (!row.locked && numberValue(row.quantity) > row.max)) return wx.showToast({ title: '请填写有效的本次数量', icon: 'none' });
    const payload = { expected_version: row.expected_version, quantity: String(row.quantity) };
    this.setData({ busy: true });
    return cutting.dispatchRoute(row.id, payload).then(() => {
      this.setData({ showTransfer: false }); wx.showToast({ title: '已交出待接收', icon: 'success' }); return this.load();
    }).catch(error => {
      const uncertain = !error.statusCode || error.statusCode >= 500 || [401, 403, 408, 429].includes(error.statusCode) || error.errorCode === 'command_processing';
      this.setData({ 'transfer.locked': uncertain }); wx.showToast({ title: error.message || '操作失败，请重试', icon: 'none' });
      if (error.statusCode === 409 && !uncertain) { this.setData({ showTransfer: false }); return this.load(); }
    }).finally(() => this.setData({ busy: false }));
  },
  load() {
    this.setData({ loading: true });
    return cutting.settlementExecution(this.data.settlementId, { page: 1, per_page: 100 }).then(response => {
      const payload = dataOf(response);
      const resultPage = pageOf(payload.results);
      if (resultPage.lastPage > 1) throw new Error('结果超过单批编辑上限，已停止编辑以防丢失其他页记录。');
      const rows = resultPage.rows;
      const source = payload.source || {};
      source.dimensions = parseMeasurements(source.dimensions);
      const others = normalizeOthers(rows.filter(row => row.result_type !== 'product'), source.input_cutting_mode);
      this.setData({ order: payload.order || {}, orderId: Number((payload.order || {}).id || this.data.orderId), source,
        products: recomputeProducts(rows.filter(row => row.result_type === 'product').map(normalizeProduct)),
        others, otherCards: otherCards(others),
        statusLabel: source.display_status || source.status || '-', editableResults: source.status === 'PROCESSING',
        editableRoutes: ['PROCESSING', 'WAIT_ROUTE'].includes(source.status), loading: false, error: '', confirmation: payload.confirmation || null,
        canConfirm: can('production.cutting.confirm'), canInspect: can('production.output.quality'),
        canDispatch: can('production.cutting.handover.dispatch'),
        canReturn: can('production.cutting.issue') && source.status === 'PROCESSING' && !!source.physical_material_id && !source.first_cut_at && !rows.length,
        pendingSave: !!this.pendingJob, canAutoConfirm: (payload.order || {}).purpose === 'WORKER' && source.status === 'WAIT_CONFIRM' && can('production.cutting.record') });
      if (this.pendingJob) this.setData({ products: this.pendingJob.products, others: this.pendingJob.others,
        otherCards: otherCards(this.pendingJob.others), editableResults: false, editableRoutes: false });
    }).catch(error => { this.setData({ loading: false, editableResults: false, editableRoutes: false, error: error.message || '下料记录加载失败' }); wx.showToast({ title: (error && error.message) || '下料记录加载失败', icon: 'none' }); });
  },
  onProductQty(event) {
    if (this.data.busy || !this.data.editableResults) return;
    const index = Number(event.currentTarget.dataset.index); const products = this.data.products.slice();
    if (!products[index]) return; products[index] = Object.assign({}, products[index], { actual_qty: event.detail.value }); this.setData({ products: recomputeProducts(products) });
  },
  bumpProductQty(event) {
    if (this.data.busy || !this.data.editableResults) return;
    const index = Number(event.currentTarget.dataset.index); const products = this.data.products.slice();
    if (!products[index]) return; products[index] = Object.assign({}, products[index], { actual_qty: String(Math.max(0, numberValue(products[index].actual_qty) + Number(event.currentTarget.dataset.delta || 0))) }); this.setData({ products: recomputeProducts(products) });
  },
  removeProduct(event) {
    if (this.data.busy || !this.data.editableResults) return;
    const products = this.data.products.slice(); products.splice(Number(event.currentTarget.dataset.index), 1); this.setData({ products });
  },
  onProductMeasure(event) {
    if (this.data.busy || !this.data.editableResults) return;
    const index = Number(event.currentTarget.dataset.index); const field = event.currentTarget.dataset.field;
    const products = this.data.products.slice(); const product = products[index]; if (!product) return;
    if (product.dimensions_locked && field !== 'weight_kg') return;
    const value = event.detail.value; const measurements = Object.assign({}, parseMeasurements(product.measurements));
    if (field === 'weight_kg') measurements.weight_kg = value;
    if (field === 'length_mm' && this.data.source.input_cutting_mode === 'sheet') measurements.length_mm = value;
    if (field === 'width_mm') measurements.width_mm = value;
    const updates = { measurements, measurement_status: Object.values(measurements).some(v => numberValue(v) > 0) ? 'MEASURED' : 'NOT_RECORDED' };
    updates[`measure_${field}`] = value;
    if (field === 'length_mm' && this.data.source.input_cutting_mode === 'length') { updates.cut_length_mm = value; updates.piece_qty = product.actual_qty; }
    products[index] = Object.assign({}, product, updates); this.setData({ products });
  },

  openAddOutput() {
    if (!this.data.editableResults) return;
    this.setData({ showOutput: true, outputKeyword: '', outputCategoryId: '', outputCategories: [], outputCandidates: [], selectedOutput: null, outputQty: '1', outputLength: '', outputWidth: '', outputWeight: '', outputPage: 1, outputLastPage: 1 });
    Promise.all([this.loadOutputCategories(), this.loadOutputs(1)]).catch(() => null);
  },
  closeOutput() { if (!this.data.busy) { this.outputSequence = (this.outputSequence || 0) + 1; this.setData({ showOutput: false, outputLoading: false }); } },
  loadOutputCategories(page = 1) {
    const request = this.data.order.purpose === 'WORKER' ? cutting.workerCategories({ settlement_batch_id: this.data.settlementId, page, per_page: 20 })
      : cutting.selectorCategories(this.data.orderId, { mode: 'outputs', page, per_page: 20 });
    return request.then(response => {
      const result = pageOf(response);
      this.setData({ outputCategories: (page === 1 ? [{ id: '', category_name: '全部' }] : this.data.outputCategories).concat(result.rows), categoryPage: result.currentPage, categoryLastPage: result.lastPage });
    });
  },
  moreOutputCategories() { if (this.data.categoryPage < this.data.categoryLastPage) return this.loadOutputCategories(this.data.categoryPage + 1); },
  onOutputKeyword(event) { this.setData({ outputKeyword: event.detail.value }); },
  setOutputCategory(event) { this.setData({ outputCategoryId: event.currentTarget.dataset.id || '', selectedOutput: null }); this.loadOutputs(1); },
  searchOutputs() { this.loadOutputs(1); },
  prevOutputs() { if (this.data.outputPage > 1) this.loadOutputs(this.data.outputPage - 1); },
  nextOutputs() { if (this.data.outputPage < this.data.outputLastPage) this.loadOutputs(this.data.outputPage + 1); },
  loadOutputs(page) {
    const sequence = this.outputSequence = (this.outputSequence || 0) + 1;
    this.setData({ outputLoading: true });
    const query = { keyword: String(this.data.outputKeyword || '').trim() || undefined,
      category_id: this.data.outputCategoryId || undefined, settlement_batch_id: this.data.settlementId, page, per_page: 20 };
    const request = this.data.order.purpose === 'WORKER' ? cutting.workerOutputs(query) : cutting.allowedOutputs(this.data.orderId, query);
    return request.then(response => {
        if (sequence !== this.outputSequence) return;
        const result = pageOf(response);
        this.setData({ outputCandidates: result.rows.map(row => Object.assign({}, row, { option_key: `${row.id}:${row.configuration_id || 0}:${row.bom_item_id || 0}`, dimensions_label: dimensionsLabel(row) })), outputPage: result.currentPage, outputLastPage: result.lastPage, outputLoading: false });
      }).catch(error => { if (sequence !== this.outputSequence) return; this.setData({ outputLoading: false, outputCandidates: [], selectedOutput: null }); wx.showToast({ title: (error && error.message) || '允许产出加载失败', icon: 'none' }); });
  },
  pickOutput(event) {
    const key = event.currentTarget.dataset.key; const selectedOutput = this.data.outputCandidates.find(row => row.option_key === key) || null;
    const dimensions = parseMeasurements(selectedOutput && selectedOutput.configuration_dimensions);
    this.setData({ selectedOutput, outputLength: (selectedOutput && selectedOutput.required_length_mm) || dimensions.length_mm || '', outputWidth: (selectedOutput && selectedOutput.required_width_mm) || dimensions.width_mm || '', outputWeight: '' });
  },
  onOutputQty(event) { this.setData({ outputQty: event.detail.value }); },
  onOutputLength(event) { this.setData({ outputLength: event.detail.value }); },
  onOutputWidth(event) { this.setData({ outputWidth: event.detail.value }); },
  onOutputWeight(event) { this.setData({ outputWeight: event.detail.value }); },
  bumpOutputQty(event) { this.setData({ outputQty: String(Math.max(1, numberValue(this.data.outputQty) + Number(event.currentTarget.dataset.delta || 0))) }); },
  confirmOutput() {
    const allowed = this.data.selectedOutput; const qty = numberValue(this.data.outputQty);
    if (!allowed || qty <= 0) return wx.showToast({ title: '请选择正式产出并填写数量', icon: 'none' });
    if (this.data.source.input_cutting_mode === 'length' && numberValue(this.data.outputLength) <= 0 && numberValue(this.data.outputWeight) <= 0) return wx.showToast({ title: '请填写实际切段长度或总重量', icon: 'none' });
    if (this.data.source.input_cutting_mode === 'sheet' && numberValue(this.data.outputWeight) <= 0
      && (numberValue(this.data.outputLength) <= 0 || numberValue(this.data.outputWidth) <= 0)) return wx.showToast({ title: '请填写单件长宽或本条总重量', icon: 'none' });
    const product = normalizeProduct({ client_row_id: `product-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
      result_type: 'product', allowed_output_id: this.data.order.purpose === 'WORKER' ? null : allowed.id,
      item_id: this.data.order.purpose === 'WORKER' ? allowed.id : allowed.item_id, configuration_id: allowed.configuration_id, item_code: allowed.item_code,
      item_name: allowed.item_name, spec: allowed.spec, configuration_no: allowed.configuration_no,
      bom_item_id: allowed.bom_item_id, per_output_piece_qty: allowed.per_output_piece_qty,
      required_length_mm: allowed.required_length_mm, required_width_mm: allowed.required_width_mm, required_thickness_mm: allowed.required_thickness_mm,
      drawing_reference: allowed.drawing_reference, actual_qty: String(qty), measurement_status: 'NOT_RECORDED', routes: [] }, this.data.products.length);
    if (this.data.source.input_cutting_mode === 'length') product.cut_length_mm = String(this.data.outputLength);
    const measurements = {};
    if (this.data.source.input_cutting_mode === 'sheet' && numberValue(this.data.outputLength) > 0 && numberValue(this.data.outputWidth) > 0) {
      measurements.length_mm = String(this.data.outputLength); measurements.width_mm = String(this.data.outputWidth);
    }
    if (numberValue(this.data.outputWeight) > 0) measurements.weight_kg = String(this.data.outputWeight);
    if (Object.keys(measurements).length) { product.measurements = measurements; product.measurement_status = 'MEASURED'; }
    const dimensionError = productDimensionError(product, this.data.source.input_cutting_mode);
    if (dimensionError) return wx.showToast({ title: dimensionError, icon: 'none' });
    this.setData({ products: recomputeProducts(this.data.products.concat([normalizeProduct(product, this.data.products.length)])), showOutput: false });
  },

  openOther(event) {
    if (!this.data.editableResults) return;
    const type = event.currentTarget.dataset.type;
    const session = this.data.others.filter(item => item.type === type && item.touched !== false).map(item => JSON.parse(JSON.stringify(item)));
    const otherDraft = this.newOtherDraft(type);
    this.setData({ showOther: true, otherType: type, otherSessionRows: session, otherEditIndex: -1, otherDraft });
  },
  newOtherDraft(type) {
    const draft = blankOther(type, this.data.source.input_cutting_mode);
    if (type === 'usable_remnant' && this.data.source.input_cutting_mode === 'sheet') {
      const source = parseMeasurements(this.data.source.dimensions);
      if (source.thickness_mm) draft.measurements.thickness_mm = source.thickness_mm;
    }
    return draft;
  },
  closeOther() { this.setData({ showOther: false, otherDraft: {}, otherType: '', otherSessionRows: [], otherEditIndex: -1 }); },
  setMeasurementStatus(event) { this.setData({ 'otherDraft.measurement_status': event.currentTarget.dataset.status, 'otherDraft.touched': true }); },
  onOtherQty(event) {
    const updates = { 'otherDraft.actual_qty': event.detail.value, 'otherDraft.touched': true };
    if (['recyclable_scrap', 'process_loss'].includes(this.data.otherDraft.type)) updates['otherDraft.measurements.weight_kg'] = event.detail.value;
    this.setData(updates);
  },
  onOtherMeasure(event) {
    if (this.data.otherDraft.type === 'usable_remnant' && event.currentTarget.dataset.field === 'thickness_mm' && this.data.source.dimensions.thickness_mm) return;
    this.setData({ [`otherDraft.measurements.${event.currentTarget.dataset.field}`]: event.detail.value, 'otherDraft.touched': true });
  },
  setOtherShape(event) { this.setData({ 'otherDraft.measurements.shape': event.currentTarget.dataset.shape, 'otherDraft.touched': true }); },
  otherValidation(draft) {
    if (!draft || draft.measurement_status !== 'MEASURED') return '请选择“已实测”并完整登记本条结果';
    const qty = numberValue(draft.actual_qty); const m = draft.measurements || {}; const sourceMode = this.data.source.input_cutting_mode || (this.data.source.physical_material_id ? 'sheet' : 'length');
    if (!(qty > 0)) return '实测结果必须填写大于 0 的数量';
    if (draft.type === 'usable_remnant') {
      if (qty !== 1) return '每条可用余料的数量固定为 1';
      if (sourceMode === 'length' && !(numberValue(m.length_mm) > 0)) return '定长余料必须填写余料长度';
      if (sourceMode === 'sheet' && m.shape === 'RECTANGLE' && (!(numberValue(m.length_mm) > 0) || !(numberValue(m.width_mm) > 0) || !(numberValue(m.thickness_mm) > 0))) return '矩形板材余料必须填写长、宽、厚';
      if (sourceMode === 'sheet' && m.shape === 'IRREGULAR' && !(numberValue(m.weight_kg) > 0) && (!(numberValue(m.length_mm) > 0) || !(numberValue(m.width_mm) > 0))) return '异形板材余料必须填写外包长宽或重量';
      if (sourceMode === 'sheet' && !['RECTANGLE', 'IRREGULAR'].includes(m.shape)) return '请选择板材余料形状';
    }
    if (['recyclable_scrap', 'process_loss'].includes(draft.type) && !(numberValue(m.weight_kg) > 0)) return '请填写实际称重';
    if (draft.type === 'scrapped_output') {
      if (sourceMode === 'length' && !(numberValue(m.length_mm) > 0) && !(numberValue(m.weight_kg) > 0)) return '定长报废产出必须填写长度或总重量';
      if (sourceMode === 'sheet' && !(numberValue(m.weight_kg) > 0) && (!(numberValue(m.length_mm) > 0) || !(numberValue(m.width_mm) > 0))) return '板材报废产出必须填写长宽或总重量';
    }
    return '';
  },
  stageOtherDraft() {
    const error = this.otherValidation(this.data.otherDraft); if (error) { wx.showToast({ title: error, icon: 'none' }); return false; }
    const draft = Object.assign({}, this.data.otherDraft, { touched: true, actual_qty: String(this.data.otherDraft.type === 'usable_remnant' ? 1 : this.data.otherDraft.actual_qty) });
    const next = normalizeOthers([Object.assign({}, draft, { result_type: draft.type })], this.data.source.input_cutting_mode).find(item => item.client_row_id === draft.client_row_id);
    const rows = this.data.otherSessionRows.slice();
    if (this.data.otherEditIndex >= 0) rows.splice(this.data.otherEditIndex, 1, next); else rows.push(next);
    this.setData({ otherSessionRows: rows, otherEditIndex: -1 }); return rows;
  },
  addOtherEntry() {
    if (!this.stageOtherDraft()) return;
    this.setData({ otherDraft: this.newOtherDraft(this.data.otherType) });
  },
  editOtherEntry(event) { const index = Number(event.currentTarget.dataset.index); const row = this.data.otherSessionRows[index]; if (row) this.setData({ otherDraft: JSON.parse(JSON.stringify(row)), otherEditIndex: index }); },
  deleteOtherEntry(event) { const rows = this.data.otherSessionRows.slice(); const index = Number(event.currentTarget.dataset.index); rows.splice(index, 1); this.setData({ otherSessionRows: rows, otherEditIndex: -1, otherDraft: this.newOtherDraft(this.data.otherType) }); },
  confirmOther() {
    const untouched = this.data.otherEditIndex < 0 && this.data.otherDraft.touched === false;
    let session = this.data.otherSessionRows;
    if (!untouched) { session = this.stageOtherDraft(); if (!session) return; }
    const retained = this.data.others.filter(row => row.type !== this.data.otherType);
    const others = normalizeOthers(retained.concat(session), this.data.source.input_cutting_mode);
    this.setData({ others, otherCards: otherCards(others), showOther: false, otherDraft: {}, otherType: '', otherSessionRows: [], otherEditIndex: -1 });
  },

  async openSplit(event) {
    if (!this.data.editableRoutes) return;
    let index = Number(event.currentTarget.dataset.index); let product = this.data.products[index]; if (!product || this.data.busy) return;
    const clientRowId = product.client_row_id;
    if (!product.id || this.data.editableResults) {
      this.setData({ busy: true });
      try {
        await this.saveAndRoute(); await this.load();
        if (this.data.error || this.pendingJob || !this.data.editableRoutes) return;
        index = this.data.products.findIndex(row => row.client_row_id === clientRowId);
        product = this.data.products[index];
        if (!product || !product.id) throw new Error('产出已变化，请刷新后重新选择');
      }
      catch (error) { wx.showToast({ title: error.message || '草稿保存失败', icon: 'none' }); return; }
      finally { this.setData({ busy: false }); }
    }
    this.setData({ showSplit: true, splitIndex: index, splitProduct: JSON.parse(JSON.stringify(product)), routeType: 'NEXT_OPERATION', routeQty: String(product.unassigned_qty_ui || ''), targets: [], targetIndex: -1, targetKeyword: '', targetPage: 1, targetLastPage: 1 });
    return this.searchTargets();
  },
  closeSplit() { this.targetSequence = (this.targetSequence || 0) + 1; this.setData({ showSplit: false, splitIndex: -1, splitProduct: {}, targetLoading: false }); },
  setRouteType(event) {
    this.targetSequence = (this.targetSequence || 0) + 1;
    this.setData({ routeType: event.currentTarget.dataset.type, targetIndex: -1, targets: [], targetLoading: false });
    if (this.data.routeType === 'NEXT_OPERATION') return this.searchTargets();
  },
  onRouteQty(event) { this.setData({ routeQty: event.detail.value }); },
  bumpRouteQty(event) { this.setData({ routeQty: String(Math.max(1, numberValue(this.data.routeQty) + Number(event.currentTarget.dataset.delta || 0))) }); },
  onTargetKeyword(event) { this.setData({ targetKeyword: event.detail.value }); },
  searchTargets(page = 1) {
    if (typeof page !== 'number') page = 1;
    if (!this.data.splitProduct.id) return wx.showToast({ title: '请先保存产出，再选择订单', icon: 'none' });
    this.setData({ targetLoading: true });
    const sequence = this.targetSequence = (this.targetSequence || 0) + 1;
    return cutting.handoverTargets(this.data.splitProduct.id, { keyword: String(this.data.targetKeyword || '').trim() || undefined, page, per_page: 20 })
      .then(response => { if (sequence !== this.targetSequence) return; const result = pageOf(response); this.setData({ targets: result.rows, targetPage: result.currentPage, targetLastPage: result.lastPage, targetIndex: -1, targetLoading: false }); })
      .catch(error => { if (sequence !== this.targetSequence) return; this.setData({ targetLoading: false, targets: [], targetIndex: -1 }); wx.showToast({ title: (error && error.message) || '订单需求加载失败', icon: 'none' }); });
  },
  prevTargets() { if (this.data.targetPage > 1) return this.searchTargets(this.data.targetPage - 1); },
  nextTargets() { if (this.data.targetPage < this.data.targetLastPage) return this.searchTargets(this.data.targetPage + 1); },
  pickTarget(event) {
    const targetIndex = Number(event.currentTarget.dataset.index); const target = this.data.targets[targetIndex];
    if (!target) return;
    const assigned = (this.data.splitProduct.routes || []).filter(route => Number(route.target_material_requirement_id) === Number(target.target_material_requirement_id)).reduce((sum, route) => sum + numberValue(route.quantity), 0);
    const available = Math.max(0, numberValue(target.selectable_qty) - assigned);
    this.setData({ targetIndex, routeQty: String(Math.min(numberValue(this.data.splitProduct.unassigned_qty_ui), available)) });
  },
  removeRoute(event) {
    const routeIndex = Number(event.currentTarget.dataset.index); const product = this.data.splitProduct;
    if (!product || !product.routes[routeIndex] || product.routes[routeIndex].editable === false) return;
    const routes = product.routes.slice(); routes.splice(routeIndex, 1);
    const next = recomputeProducts([Object.assign({}, product, { routes })])[0];
    this.setData({ splitProduct: next, routeQty: '', targetIndex: -1 });
  },
  confirmSplit() {
    const index = this.data.splitIndex; const product = this.data.splitProduct; const qty = numberValue(this.data.routeQty);
    if (product && this.data.routeQty === '' && JSON.stringify(product.routes) !== JSON.stringify(this.data.products[index].routes)) {
      const products = this.data.products.slice(); products[index] = product;
      this.setData({ products: recomputeProducts(products), showSplit: false, splitIndex: -1, splitProduct: {} }); return;
    }
    if (!product || qty <= 0 || qty > numberValue(product.unassigned_qty_ui)) return wx.showToast({ title: '本次指定数量不合法', icon: 'none' });
    const route = { route_type: this.data.routeType, quantity: String(qty), status: 'PLANNED', editable: true };
    if (this.data.routeType === 'NEXT_OPERATION') {
      const target = this.data.targets[this.data.targetIndex]; if (!target) return wx.showToast({ title: '请选择订单需求', icon: 'none' });
      const assigned = (product.routes || []).filter(row => Number(row.target_material_requirement_id) === Number(target.target_material_requirement_id)).reduce((sum, row) => sum + numberValue(row.quantity), 0);
      if (qty + assigned > numberValue(target.selectable_qty)) return wx.showToast({ title: '指定数量超过该订单剩余需求', icon: 'none' });
      route.target_material_requirement_id = Number(target.target_material_requirement_id);
      route.target_label = [target.order_label || target.order_no || target.work_order_no, target.target_item_name, target.operation_name || target.task_no, target.unit_no].filter(Boolean).join(' / ');
    } else route.target_label = '入库';
    const products = this.data.products.slice(); products[index] = Object.assign({}, product, { routes: (product.routes || []).concat([route]) });
    this.setData({ products: recomputeProducts(products), showSplit: false, splitIndex: -1, splitProduct: {} });
  },

  buildResultsPayload() {
    const products = this.data.products.map(item => {
      const row = { client_row_id: item.client_row_id, result_type: 'product',
        actual_qty: String(item.actual_qty), measurement_status: item.measurement_status || 'NOT_RECORDED' };
      if (item.allowed_output_id) row.allowed_output_id = Number(item.allowed_output_id);
      else { row.item_id = Number(item.item_id); if (item.configuration_id) row.configuration_id = Number(item.configuration_id); }
      if (item.bom_item_id) row.bom_item_id = Number(item.bom_item_id);
      ['piece_qty', 'cut_length_mm', 'reported_quality'].forEach(key => { if (item[key] !== null && item[key] !== undefined && item[key] !== '') row[key] = String(item[key]); });
      const measurements = parseMeasurements(item.measurements);
      if (Object.keys(measurements).length) row.measurements = measurements;
      return row;
    });
    const others = this.data.others.filter(item => item.touched !== false).map(item => {
      const row = { client_row_id: item.client_row_id, result_type: item.type, measurement_status: item.measurement_status || 'NOT_RECORDED' };
      if (row.measurement_status === 'MEASURED') {
        row.actual_qty = String(item.actual_qty);
        const measurements = {};
        Object.keys(item.measurements || {}).forEach(key => { if (item.measurements[key] !== null && item.measurements[key] !== undefined && String(item.measurements[key]).trim() !== '') measurements[key] = String(item.measurements[key]); });
        if (Object.keys(measurements).length) row.measurements = measurements;
      }
      return row;
    });
    return products.concat(others);
  },
  routePlans() {
    const plans = {};
    this.data.products.forEach(product => { plans[product.client_row_id] = (product.routes || []).filter(route => route.editable !== false).map(route => {
      const row = { route_type: route.route_type, quantity: String(route.quantity) };
      if (route.route_type === 'NEXT_OPERATION') row.target_material_requirement_id = Number(route.target_material_requirement_id);
      return row;
    }); });
    return plans;
  },
  persistRoutesFrom(rows, plans) {
    return rows.filter(row => row.result_type === 'product' && plans[row.client_row_id]).reduce((chain, row) => chain.then(() => cutting.splitRoutes(row.id, {
      expected_version: Number(row.business_version), routes: plans[row.client_row_id],
    })), Promise.resolve());
  },
  refetch() { return cutting.settlementExecution(this.data.settlementId, { page: 1, per_page: 100 }).then(response => {
    const payload = dataOf(response);
    if (pageOf(payload.results).lastPage > 1) throw new Error('结果超过单批处理上限，已停止保存以防遗漏。');
    return payload;
  }); },
  storeJob(job) { this.pendingJob = job; this.setData({ pendingSave: !!job }); if (this.jobKey) { if (job) wx.setStorageSync(this.jobKey, job); else wx.removeStorageSync(this.jobKey); } },
  async runSaveJob(submit, needsSave) {
    let job = this.pendingJob;
    if (!job) {
      const results = this.buildResultsPayload();
      if (needsSave && (!results.length || results.length > 100)) throw new Error('每批须包含1至100条实际结果。');
      job = { submit, needsSave, saved: false, version: Number(this.data.source.business_version), results, plans: this.routePlans(),
        products: this.data.products, others: this.data.others, routeIndex: 0, routes: null, submitVersion: null };
      this.storeJob(job);
    }
    // Keep step identities in the durable workflow as well as the request cache:
    // the app may exit after a successful response but before the next checkpoint.
    if (!job.commandId) {
      job.commandId = `cut-job-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
      this.storeJob(job);
    }
    // Persist the exact multi-command workflow. A reconnect resumes the unfinished
    // command instead of re-saving stale results or losing remaining route edits.
    try {
      if (job.needsSave && !job.saved) {
        await cutting.saveSettlement(this.data.settlementId, { expected_version: job.version, results: job.results, client_command_id: `${job.commandId}-save` });
        job.saved = true; this.storeJob(job);
      }
      if (!job.routes) {
        const payload = await this.refetch();
        job.routes = pageOf(payload.results).rows.filter(row => row.result_type === 'product' && job.plans[row.client_row_id]).map(row => ({
          id: row.id, expected_version: Number(row.business_version), routes: job.plans[row.client_row_id],
        }));
        this.storeJob(job);
      }
      while (job.routeIndex < job.routes.length) {
        const row = job.routes[job.routeIndex];
        await cutting.splitRoutes(row.id, { expected_version: row.expected_version, routes: row.routes, client_command_id: `${job.commandId}-route-${job.routeIndex}` });
        job.routeIndex += 1; this.storeJob(job);
      }
      let payload = await this.refetch();
      if (job.submit) {
        if (job.submitVersion === null) { job.submitVersion = Number(payload.source.business_version); this.storeJob(job); }
        await cutting.submitSettlement(this.data.settlementId, { expected_version: job.submitVersion, client_command_id: `${job.commandId}-submit` });
        payload = await this.refetch();
      }
      if ((job.submit || !job.needsSave) && (payload.order || {}).purpose === 'WORKER' && (job.confirmVersion != null || payload.source.status === 'WAIT_CONFIRM')) {
        if (job.confirmVersion == null) { job.confirmVersion = Number(payload.source.business_version); this.storeJob(job); }
        await cutting.confirmSettlement(this.data.settlementId, { expected_version: job.confirmVersion, cost_method: 'MATERIAL_SHARE_V1', client_command_id: `${job.commandId}-confirm` });
        payload = await this.refetch();
      }
      this.storeJob(null); return payload;
    } catch (error) {
      if (error.statusCode >= 400 && error.statusCode < 500 && ![401, 403, 408, 429].includes(error.statusCode) && error.errorCode !== 'command_processing') {
        // A definitive rejection permits correction, but refresh the version while
        // preserving the worker's local result/route intent, including unsaved rows.
        this.storeJob(null);
        const latest = await this.refetch().catch(() => null);
        if (latest) this.setData({ source: Object.assign({}, latest.source, { dimensions: parseMeasurements(latest.source.dimensions) }),
          editableResults: latest.source.status === 'PROCESSING',
          editableRoutes: ['PROCESSING', 'WAIT_ROUTE'].includes(latest.source.status) });
      }
      throw error;
    }
  },
  saveAndRoute() { return this.runSaveJob(false, true); },
  confirmAutomatic() {
    if (this.data.busy || !this.data.canAutoConfirm) return;
    this.setData({ busy: true });
    return cutting.confirmSettlement(this.data.settlementId, { expected_version: Number(this.data.source.business_version), cost_method: 'MATERIAL_SHARE_V1' })
      .then(() => this.load()).catch(error => wx.showToast({ title: error.message || '自动核算失败', icon: 'none' }))
      .finally(() => this.setData({ busy: false }));
  },
  saveRoutesOnly() { return this.runSaveJob(false, false); },
  saveDraft() {
    if (this.data.busy) return; this.setData({ busy: true });
    const operation = this.data.editableResults ? this.saveAndRoute() : this.saveRoutesOnly();
    return operation.then(() => { wx.showToast({ title: this.data.editableResults ? '草稿已保存' : '去向已保存', icon: 'success' }); return this.load(); })
      .catch(error => wx.showToast({ title: (error && error.message) || '保存失败', icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
  validateBeforeSubmit() {
    if (!this.data.products.length) return '至少需要一条实际产品产出';
    const sourceMode = this.data.source.input_cutting_mode || (this.data.source.physical_material_id ? 'sheet' : 'length');
    for (const product of this.data.products) {
      if (!(numberValue(product.actual_qty) > 0)) return '正式产出数量必须大于 0';
      const dimensionError = productDimensionError(product, sourceMode); if (dimensionError) return dimensionError;
      const m = product.measurements || {};
      if (sourceMode === 'length' && !(numberValue(product.cut_length_mm || m.length_mm) > 0) && !(numberValue(m.weight_kg) > 0)) return '定长材料产出必须填写切段长度或总重量';
      if (sourceMode === 'sheet' && !(numberValue(m.weight_kg) > 0) && (!(numberValue(m.length_mm) > 0) || !(numberValue(m.width_mm) > 0))) return '板材产出必须填写单件长宽或本条总重量';
    }
    for (const row of this.data.others.filter(item => item.touched !== false)) { const error = this.otherValidation(row); if (error) return `${row.label}：${error}`; }
    return '';
  },
  submitResult() {
    if (this.data.busy || !this.data.editableResults) return;
    const error = this.validateBeforeSubmit(); if (error) return wx.showToast({ title: error, icon: 'none' });
    this.setData({ busy: true });
    return this.runSaveJob(true, true)
      .then(() => { wx.showToast({ title: '加工结果已提交', icon: 'success' }); return this.load(); })
      .catch(error => wx.showToast({ title: (error && error.message) || '提交失败', icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
});

module.exports = { dataOf, pageOf, parseMeasurements, normalizeProduct, normalizeOthers, otherCards, recomputeProducts };
