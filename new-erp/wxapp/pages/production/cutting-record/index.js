const cutting = require('../../../services/cutting');
const production = require('../../../services/production');

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
  if (route.route_type === 'WAREHOUSE') return '入库备货';
  return route.target_label || [route.work_order_no, route.unit_no, route.task_no].filter(Boolean).join(' / ') || `下一工序目标 #${route.target_material_requirement_id}`;
}
function normalizeProduct(item, index) {
  const measurements = parseMeasurements(item.measurements);
  const configuration = parseMeasurements(item.configuration_dimensions);
  const routes = (item.routes || []).filter(route => route.status !== 'CANCELLED').map(route => Object.assign({}, route, {
    quantity: String(route.quantity), target_label: routeLabel(route), editable: !route.status || route.status === 'PLANNED',
  }));
  const assigned = routes.reduce((sum, route) => sum + numberValue(route.quantity), 0);
  return Object.assign({}, item, {
    client_row_id: item.client_row_id || `product-${item.id || index}-${Date.now()}`,
    actual_qty: String(item.actual_qty == null ? '' : item.actual_qty),
    item_name: item.item_name || item.item_code || '正式产出',
    measurements,
    measure_length_mm: String(item.cut_length_mm || measurements.length_mm || configuration.length_mm || ''),
    measure_width_mm: String(measurements.width_mm || configuration.width_mm || ''),
    measure_weight_kg: String(measurements.weight_kg || ''),
    routes, assigned_qty_ui: assigned, unassigned_qty_ui: Math.max(0, numberValue(item.actual_qty) - assigned),
  });
}
function normalizeOthers(rows) {
  return OTHER_DEFS.reduce((all, def) => {
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
function recomputeProducts(products) {
  return products.map(item => {
    const assigned = (item.routes || []).reduce((sum, route) => sum + numberValue(route.quantity), 0);
    return Object.assign({}, item, { assigned_qty_ui: assigned, unassigned_qty_ui: Math.max(0, numberValue(item.actual_qty) - assigned) });
  });
}

Page({
  data: {
    orderId: 0, settlementId: 0, loading: true, busy: false,
    order: {}, source: {}, products: [], others: [], statusLabel: '-', editableResults: false, editableRoutes: false,
    showOutput: false, outputKeyword: '', outputCategories: [], outputCategoryId: '', outputCandidates: [], outputPage: 1, outputLastPage: 1,
    selectedOutput: null, outputQty: '1', outputLoading: false,
    showOther: false, otherDraft: {},
    showSplit: false, splitIndex: -1, splitProduct: {}, routeType: 'NEXT_OPERATION', routeQty: '', targets: [], targetIndex: -1, targetKeyword: '', targetLoading: false,
    categoryPage: 1, categoryLastPage: 1, targetPage: 1, targetLastPage: 1,
    confirmation: null, canConfirm: false, canInspect: false, canDispatch: false, canWarehouse: false, canReturn: false,
    pendingSave: false, error: '', outputLength: '', outputWidth: '', outputWeight: '', canAutoConfirm: false,
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
  load() {
    this.setData({ loading: true });
    return cutting.settlementExecution(this.data.settlementId, { page: 1, per_page: 100 }).then(response => {
      const payload = dataOf(response);
      const resultPage = pageOf(payload.results);
      if (resultPage.lastPage > 1) throw new Error('结果超过单批编辑上限，已停止编辑以防丢失其他页记录。');
      const rows = resultPage.rows;
      const source = payload.source || {};
      this.setData({ order: payload.order || {}, orderId: Number((payload.order || {}).id || this.data.orderId), source,
        products: recomputeProducts(rows.filter(row => row.result_type === 'product').map(normalizeProduct)),
        others: normalizeOthers(rows.filter(row => row.result_type !== 'product')),
        statusLabel: source.display_status || source.status || '-', editableResults: source.status === 'PROCESSING',
        editableRoutes: ['PROCESSING', 'WAIT_ROUTE'].includes(source.status), loading: false, error: '', confirmation: payload.confirmation || null,
        canConfirm: can('production.cutting.confirm'), canInspect: can('production.output.quality'),
        canDispatch: can('production.cutting.handover.dispatch'), canWarehouse: can('production.cutting.warehouse'),
        canReturn: can('production.cutting.issue') && source.status === 'PROCESSING' && !!source.physical_material_id && !source.first_cut_at && !rows.length,
        pendingSave: !!this.pendingJob, canAutoConfirm: (payload.order || {}).purpose === 'WORKER' && source.status === 'WAIT_CONFIRM' && can('production.cutting.record') });
      if (this.pendingJob) this.setData({ products: this.pendingJob.products, others: this.pendingJob.others, editableResults: false, editableRoutes: false });
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
    const value = event.detail.value; const measurements = Object.assign({}, parseMeasurements(product.measurements));
    if (field === 'weight_kg') measurements.weight_kg = value;
    if (field === 'length_mm' && this.data.source.physical_material_id) measurements.length_mm = value;
    if (field === 'width_mm') measurements.width_mm = value;
    const updates = { measurements, measurement_status: Object.values(measurements).some(v => numberValue(v) > 0) ? 'MEASURED' : 'NOT_RECORDED' };
    updates[`measure_${field}`] = value;
    if (field === 'length_mm' && !this.data.source.physical_material_id) { updates.cut_length_mm = value; updates.piece_qty = product.actual_qty; }
    products[index] = Object.assign({}, product, updates); this.setData({ products });
  },

  openAddOutput() {
    if (!this.data.editableResults) return;
    this.setData({ showOutput: true, outputKeyword: '', outputCategoryId: '', outputCategories: [], outputCandidates: [], selectedOutput: null, outputQty: '1', outputLength: '', outputWidth: '', outputWeight: '', outputPage: 1, outputLastPage: 1 });
    Promise.all([this.loadOutputCategories(), this.loadOutputs(1)]).catch(() => null);
  },
  closeOutput() { if (!this.data.busy) { this.outputSequence = (this.outputSequence || 0) + 1; this.setData({ showOutput: false, outputLoading: false }); } },
  loadOutputCategories(page = 1) {
    const request = this.data.order.purpose === 'WORKER' ? cutting.workerCategories({ page, per_page: 20 })
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
      category_id: this.data.outputCategoryId || undefined, page, per_page: 20 };
    const request = this.data.order.purpose === 'WORKER' ? cutting.workerOutputs(query) : cutting.allowedOutputs(this.data.orderId, query);
    return request.then(response => {
        if (sequence !== this.outputSequence) return;
        const result = pageOf(response);
        this.setData({ outputCandidates: result.rows.map(row => Object.assign({}, row, { option_key: `${row.id}:${row.configuration_id || 0}` })), outputPage: result.currentPage, outputLastPage: result.lastPage, outputLoading: false });
      }).catch(error => { if (sequence !== this.outputSequence) return; this.setData({ outputLoading: false }); wx.showToast({ title: (error && error.message) || '允许产出加载失败', icon: 'none' }); });
  },
  pickOutput(event) {
    const key = event.currentTarget.dataset.key; const selectedOutput = this.data.outputCandidates.find(row => row.option_key === key) || null;
    const dimensions = parseMeasurements(selectedOutput && selectedOutput.configuration_dimensions);
    this.setData({ selectedOutput, outputLength: dimensions.length_mm || '', outputWidth: dimensions.width_mm || '', outputWeight: '' });
  },
  onOutputQty(event) { this.setData({ outputQty: event.detail.value }); },
  onOutputLength(event) { this.setData({ outputLength: event.detail.value }); },
  onOutputWidth(event) { this.setData({ outputWidth: event.detail.value }); },
  onOutputWeight(event) { this.setData({ outputWeight: event.detail.value }); },
  bumpOutputQty(event) { this.setData({ outputQty: String(Math.max(1, numberValue(this.data.outputQty) + Number(event.currentTarget.dataset.delta || 0))) }); },
  confirmOutput() {
    const allowed = this.data.selectedOutput; const qty = numberValue(this.data.outputQty);
    if (!allowed || qty <= 0) return wx.showToast({ title: '请选择正式产出并填写数量', icon: 'none' });
    if (!this.data.source.physical_material_id && numberValue(this.data.outputLength) <= 0 && numberValue(this.data.outputWeight) <= 0) return wx.showToast({ title: '请填写实际切段长度或总重量', icon: 'none' });
    if (this.data.source.physical_material_id && numberValue(this.data.outputWeight) <= 0
      && (numberValue(this.data.outputLength) <= 0 || numberValue(this.data.outputWidth) <= 0)) return wx.showToast({ title: '请填写单件长宽或本条总重量', icon: 'none' });
    const product = normalizeProduct({ client_row_id: `product-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
      result_type: 'product', allowed_output_id: this.data.order.purpose === 'WORKER' ? null : allowed.id,
      item_id: this.data.order.purpose === 'WORKER' ? allowed.id : allowed.item_id, configuration_id: allowed.configuration_id, item_code: allowed.item_code,
      item_name: allowed.item_name, spec: allowed.spec, configuration_no: allowed.configuration_no,
      drawing_reference: allowed.drawing_reference, actual_qty: String(qty), measurement_status: 'NOT_RECORDED', routes: [] }, this.data.products.length);
    if (!this.data.source.physical_material_id) { product.cut_length_mm = String(this.data.outputLength); product.piece_qty = String(qty); }
    const measurements = {};
    if (this.data.source.physical_material_id && numberValue(this.data.outputLength) > 0 && numberValue(this.data.outputWidth) > 0) {
      measurements.length_mm = String(this.data.outputLength); measurements.width_mm = String(this.data.outputWidth);
    }
    if (numberValue(this.data.outputWeight) > 0) measurements.weight_kg = String(this.data.outputWeight);
    if (Object.keys(measurements).length) { product.measurements = measurements; product.measurement_status = 'MEASURED'; }
    this.setData({ products: this.data.products.concat([product]), showOutput: false });
  },

  openOther(event) {
    if (!this.data.editableResults) return;
    const row = this.data.others.find(item => item.client_row_id === event.currentTarget.dataset.key); if (!row) return;
    this.setData({ showOther: true, otherDraft: JSON.parse(JSON.stringify(row)) });
  },
  closeOther() { this.setData({ showOther: false, otherDraft: {} }); },
  setMeasurementStatus(event) { this.setData({ 'otherDraft.measurement_status': event.currentTarget.dataset.status }); },
  onOtherQty(event) {
    const updates = { 'otherDraft.actual_qty': event.detail.value };
    if (['recyclable_scrap', 'process_loss'].includes(this.data.otherDraft.type)) updates['otherDraft.measurements.weight_kg'] = event.detail.value;
    this.setData(updates);
  },
  onOtherMeasure(event) { this.setData({ [`otherDraft.measurements.${event.currentTarget.dataset.field}`]: event.detail.value }); },
  setOtherShape(event) { this.setData({ 'otherDraft.measurements.shape': event.currentTarget.dataset.shape }); },
  addOtherEntry() {
    const draft = this.data.otherDraft;
    const fresh = normalizeOthers([{ type: draft.type, client_row_id: `other-${draft.type}-${Date.now()}`, touched: false }]).find(row => row.type === draft.type);
    this.setData({ otherDraft: fresh });
  },
  confirmOther() {
    const draft = this.data.otherDraft;
    if (draft.measurement_status === 'MEASURED' && String(draft.actual_qty || '').trim() === '') return wx.showToast({ title: '实测结果必须填写数量', icon: 'none' });
    if (draft.measurement_status !== 'MEASURED') { draft.actual_qty = ''; draft.measurements = {}; }
    draft.touched = true;
    const next = normalizeOthers([Object.assign({}, draft, { result_type: draft.type })]).find(item => item.client_row_id === draft.client_row_id);
    let exists = false;
    const others = this.data.others.map(row => { if (row.client_row_id !== draft.client_row_id) return row; exists = true; return next; });
    if (!exists) others.push(next);
    this.setData({ others, showOther: false, otherDraft: {} });
  },

  async openSplit(event) {
    if (!this.data.editableRoutes) return;
    const index = Number(event.currentTarget.dataset.index); let product = this.data.products[index]; if (!product || this.data.busy) return;
    if (!product.id) {
      this.setData({ busy: true });
      try { await this.saveAndRoute(); await this.load(); product = this.data.products[index]; }
      catch (error) { wx.showToast({ title: error.message || '草稿保存失败', icon: 'none' }); return; }
      finally { this.setData({ busy: false }); }
    }
    this.setData({ showSplit: true, splitIndex: index, splitProduct: product, routeType: 'NEXT_OPERATION', routeQty: String(product.unassigned_qty_ui || ''), targets: [], targetIndex: -1, targetKeyword: '' });
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
    if (!this.data.splitProduct.id) return wx.showToast({ title: '请先保存草稿，再选择真实下一工序', icon: 'none' });
    this.setData({ targetLoading: true });
    const sequence = this.targetSequence = (this.targetSequence || 0) + 1;
    return cutting.handoverTargets(this.data.splitProduct.id, { keyword: String(this.data.targetKeyword || '').trim() || undefined, page, per_page: 20 })
      .then(response => { if (sequence !== this.targetSequence) return; const result = pageOf(response); this.setData({ targets: result.rows, targetPage: result.currentPage, targetLastPage: result.lastPage, targetIndex: -1, targetLoading: false }); })
      .catch(error => { if (sequence !== this.targetSequence) return; this.setData({ targetLoading: false }); wx.showToast({ title: (error && error.message) || '目标任务加载失败', icon: 'none' }); });
  },
  prevTargets() { if (this.data.targetPage > 1) return this.searchTargets(this.data.targetPage - 1); },
  nextTargets() { if (this.data.targetPage < this.data.targetLastPage) return this.searchTargets(this.data.targetPage + 1); },
  pickTarget(event) { this.setData({ targetIndex: Number(event.currentTarget.dataset.index) }); },
  removeRoute(event) {
    const productIndex = this.data.splitIndex; const routeIndex = Number(event.currentTarget.dataset.index); const products = this.data.products.slice();
    const product = products[productIndex]; if (!product || !product.routes[routeIndex] || product.routes[routeIndex].editable === false) return;
    const routes = product.routes.slice(); routes.splice(routeIndex, 1); products[productIndex] = Object.assign({}, product, { routes });
    const next = recomputeProducts(products); this.setData({ products: next, splitProduct: next[productIndex] });
  },
  confirmSplit() {
    const index = this.data.splitIndex; const product = this.data.products[index]; const qty = numberValue(this.data.routeQty);
    if (!product || qty <= 0 || qty > numberValue(product.unassigned_qty_ui)) return wx.showToast({ title: '本次指定数量不合法', icon: 'none' });
    const route = { route_type: this.data.routeType, quantity: String(qty), status: 'PLANNED', editable: true };
    if (this.data.routeType === 'NEXT_OPERATION') {
      const target = this.data.targets[this.data.targetIndex]; if (!target) return wx.showToast({ title: '请选择真实下一工序目标', icon: 'none' });
      route.target_material_requirement_id = Number(target.target_material_requirement_id);
      route.target_label = [target.work_order_no, target.unit_no, target.task_no].filter(Boolean).join(' / ');
    } else route.target_label = '入库备货';
    const products = this.data.products.slice(); products[index] = Object.assign({}, product, { routes: (product.routes || []).concat([route]) });
    this.setData({ products: recomputeProducts(products), showSplit: false, splitIndex: -1, splitProduct: {} });
  },

  buildResultsPayload() {
    const products = this.data.products.map(item => {
      const row = { client_row_id: item.client_row_id, result_type: 'product',
        actual_qty: String(item.actual_qty), measurement_status: item.measurement_status || 'NOT_RECORDED' };
      if (item.allowed_output_id) row.allowed_output_id = Number(item.allowed_output_id);
      else { row.item_id = Number(item.item_id); if (item.configuration_id) row.configuration_id = Number(item.configuration_id); }
      ['piece_qty', 'cut_length_mm', 'reported_quality'].forEach(key => { if (item[key] !== null && item[key] !== undefined && item[key] !== '') row[key] = String(item[key]); });
      if (item.measurements) row.measurements = parseMeasurements(item.measurements);
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
        if (latest) this.setData({ source: latest.source,
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
  submitResult() {
    if (this.data.busy || !this.data.editableResults) return; this.setData({ busy: true });
    return this.runSaveJob(true, true)
      .then(() => { wx.showToast({ title: '加工结果已提交', icon: 'success' }); return this.load(); })
      .catch(error => wx.showToast({ title: (error && error.message) || '提交失败', icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
});

module.exports = { dataOf, pageOf, parseMeasurements, normalizeProduct, normalizeOthers, recomputeProducts };
