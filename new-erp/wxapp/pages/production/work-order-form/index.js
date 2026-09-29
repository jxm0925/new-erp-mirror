const workOrders = require('../../../services/work-orders');
const attachments = require('../../../services/work-order-attachments');
const { pendingCommand } = require('../../../utils/cutting-command');
const STATUS = { DRAFT: '草稿', WAIT_RELEASE: '待发布', RELEASED: '已发布', IN_PROGRESS: '生产中', COMPLETED: '已完成', CANCELLED: '已取消' };
const can = code => { const p = wx.getStorageSync('erp_permissions') || []; return Array.isArray(p) ? p.includes(code) : p[code] === true; };
const uuid = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const n = Math.random() * 16 | 0; return (c === 'x' ? n : (n & 3 | 8)).toString(16); });

Page({
  data: {
    executionReadOnly: true, activeTab: 'technical', planOnly: false, technicalVersion: 0, technical: {}, technicalFiles: [], technicalMaterials: [], attachmentBusy: 0, sourceLabel: '',
    id: 0, loading: true, busy: false, error: '', statusLabel: '', number: '', reservation: '', editable: false, dirty: false, released: false,
    item: {}, routing: {}, operation: {}, routeSummary: [], routePage: 1, routeLastPage: 1, routeLoading: false, routeError: '', actions: {}, gate: null, pending: null,
    purpose: 'common_inventory', targetWorkOrder: {}, targetUnit: {}, targetOperation: {}, targetStatus: '', optionError: '',
    form: { target_qty: '', planned_date: '', production_location_name: '', production_batch: '' },
    picker: '', pickerTitle: '', keyword: '', options: [], selected: null, page: 1, lastPage: 1, optionLoading: false,
    categories: [], categoryId: '', categoryPage: 1, categoryLastPage: 1, categoryLoading: false,
  },
  onLoad(options) {
    // Work-order management moved to PC; retain this route only for execution drawings.
    const id = Number(options.id || 0);
    if (!id || options.tab !== 'technical') {
      wx.redirectTo({ url: id ? '/pages/production/work-order-detail/index?id=' + id : '/pages/production/tasks/index' });
      return;
    }
    wx.setNavigationBarTitle({ title: '技术资料' });
    const actor = wx.getStorageSync('erp_user') || {};
    this.storageKey = `work-order-form:${actor.legacy_id || actor.id || 'session'}`;
    this.setData({ id: Number(options.id || 0), activeTab: ['plan', 'technical', 'check'].includes(options.tab) ? options.tab : 'plan' });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onUnload() { this.optionSequence = (this.optionSequence || 0) + 1; this.categorySequence = (this.categorySequence || 0) + 1; this.routeSequence = (this.routeSequence || 0) + 1; },
  load() {
    if (this.data.busy) return Promise.resolve();
    if (!this.data.id) { wx.redirectTo({ url: '/pages/production/tasks/index' }); return Promise.resolve(); }
    this.setData({ loading: true, error: '', gate: null, activeTab: 'technical' });
    if (this.data.id) return workOrders.detail(this.data.id).then(response => {
      const wo = response.data; const routing = wo.routing || {}; const product = wo.product || {}; const target = wo.reserved_target || {};
      const end = routing.target_routing_operation || {};
      this.version = wo.business_version;
      this.setData({ number: wo.work_order_no, statusLabel: STATUS[wo.status] || wo.status, actions: { view_tasks: !!(wo.actions || {}).view_tasks }, dirty: false, released: ['RELEASED', 'IN_PROGRESS'].includes(wo.status),
        technicalVersion: Number(wo.technical_version || 0), technical: wo.technical_snapshot || {},
        sourceLabel: wo.source_type === 'stock_prebuild' ? (wo.stocking_purpose === 'reserved_for_work_order' ? '指定工单备货' : '公共库存备货') : ({ sales_order: '销售订单生产', trial: '试制生产', production_plan: '计划生产' }[wo.source_type] || '生产工单'),
        technicalFiles: ((wo.technical_snapshot || {}).attachments || []).map(file => Object.assign({}, file, {
          kind: file.mime_type === 'application/pdf' ? 'PDF' : String(file.mime_type || '').startsWith('image/') ? '图片' : 'CAD',
          sizeLabel: Number(file.file_size) >= 1048576 ? (Number(file.file_size) / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.ceil(Number(file.file_size || 0) / 1024)) + ' KB',
        })),
        technicalMaterials: ((wo.technical_snapshot || {}).materials || []).map(row => {
          const dimensions = (row.configuration || {}).dimensions || {};
          const length = dimensions.length_mm || dimensions.target_length_mm;
          const width = dimensions.width_mm;
          const thickness = dimensions.thickness_mm;
          return Object.assign({}, row, { quantityLabel: String(row.per_output_qty || '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''),
            specLabel: length && width ? [length, width, thickness].filter(Boolean).join('×') + 'mm' : [row.spec, length ? '长' + length + 'mm' : ''].filter(Boolean).join(' · ') || '—' });
        }),
        editable: false,
        planOnly: wo.source_type !== 'stock_prebuild' || !(wo.actions || {}).edit,
        purpose: wo.stocking_purpose || 'common_inventory',
        targetWorkOrder: { id: wo.reserved_for_work_order_id, name: target.work_order_no || (wo.reserved_for_work_order_id ? '无权查看目标工单' : ''), execution_mode: target.execution_mode || (wo.reserved_for_production_unit_id ? 'unit' : 'quantity') },
        targetUnit: { id: wo.reserved_for_production_unit_id, name: target.unit_no || '—' },
        targetOperation: { id: wo.reserved_for_target_operation_id, name: target.operation_name || '—' },
        item: { id: product.item_id, name: product.item_name || product.name, code: product.item_code, unit_name: (wo.quantity || {}).unit_name },
        routing: { id: routing.id, name: routing.name, code: routing.no, version: routing.version },
        operation: { id: routing.target_routing_operation_id, name: (end.sequence ? end.sequence + ' - ' : '') + (end.operation_name || routing.target_operation_name || ''), sequence: end.sequence },
        routeSummary: ((routing.snapshot || {}).operations || []).map(row => ({ id: row.routing_operation_id, name: row.operation_name || '', sequence: row.sequence })), routePage: 1, routeLastPage: 1,
        form: { target_qty: String((wo.quantity || {}).target_qty || ''), planned_date: (wo.plan || {}).planned_date || '', production_location_name: (wo.plan || {}).production_location_name || '', production_batch: (wo.plan || {}).production_batch || '' },
      });
      this.setData({ pending: null });
    }).catch(error => this.fail(error)).finally(() => this.setData({ loading: false }));
    const saved = wx.getStorageSync(this.storageKey);
    this.session = saved && saved.session || uuid();
    if (saved) this.setData({ form: saved.form || this.data.form, item: saved.item || {}, routing: saved.routing || {}, operation: saved.operation || {}, number: saved.number || '', reservation: saved.reservation || '', purpose: saved.purpose || 'common_inventory', targetWorkOrder: saved.targetWorkOrder || {}, targetUnit: saved.targetUnit || {}, targetOperation: saved.targetOperation || {} });
    if (this.data.routing.id) this.loadRoute();
    this.setData({ editable: can('production.work_order.create'), actions: { create: can('production.work_order.create') }, statusLabel: '新建' });
    this.restorePending();
    if (this.data.pending || !this.data.editable) { this.setData({ loading: false }); return Promise.resolve(); }
    return this.reserve().finally(() => this.setData({ loading: false }));
  },
  reserve(renewed = false) {
    if (this.data.pending) return Promise.resolve();
    return workOrders.reserve(this.session).then(response => {
      const reservation = response.data;
      // A successful create can outlive a lost page refresh. Reopen its actual
      // document instead of using a consumed number to create another work order.
      if (reservation.status === 'used' && reservation.business_type === 'work_order' && reservation.business_id) {
        wx.removeStorageSync(this.storageKey); this.setData({ id: Number(reservation.business_id) }); return this.load();
      }
      if (!renewed && reservation.expires_at && Date.parse(reservation.expires_at) <= Date.now()) return this.renewReservation();
      this.setData({ number: reservation.document_no, reservation: reservation.reservation_token }); this.remember();
    }).catch(error => {
      if (!renewed && error.statusCode === 422 && (error.errors || {}).creation_session_id) return this.renewReservation();
      this.fail(error);
    });
  },
  renewReservation() {
    if (this.data.pending) return Promise.resolve();
    this.session = uuid(); this.setData({ number: '', reservation: '' }); this.remember();
    return this.reserve(true);
  },
  remember() {
    if (!this.data.id) wx.setStorageSync(this.storageKey, { session: this.session, number: this.data.number, reservation: this.data.reservation, form: this.data.form, item: this.data.item, routing: this.data.routing, operation: this.data.operation, purpose: this.data.purpose, targetWorkOrder: this.data.targetWorkOrder, targetUnit: this.data.targetUnit, targetOperation: this.data.targetOperation });
  },
  restorePending() {
    const id = this.data.id; const base = `production/work-orders${id ? '/' + id : ''}`;
    const save = pendingCommand(base, id ? 'PUT' : 'POST');
    let pending = save ? { action: 'save', payload: save, label: '保存草稿' } : null;
    const plan = id && pendingCommand(`${base}/plan`, 'PUT');
    if (!pending && plan) pending = { action: 'save-plan', payload: plan, label: '保存生产计划' };
    if (id && !pending) ['submit', 'publish', 'return-draft'].some(action => {
      const payload = pendingCommand(`${base}/${action}`);
      if (!payload) return false;
      pending = { action, payload, label: { submit: '提交', publish: '发布', 'return-draft': '退回草稿' }[action] }; return true;
    });
    this.setData({ pending });
  },
  input(event) { if (!this.data.editable || this.data.pending || this.data.busy || (this.data.planOnly && event.currentTarget.dataset.field === 'target_qty')) return; this.setData({ [`form.${event.currentTarget.dataset.field}`]: event.detail.value, dirty: true }); this.remember(); },
  setPurpose(event) {
    if (this.data.id || !this.data.editable || this.data.busy || this.data.pending) return;
    const purpose = event.currentTarget.dataset.purpose;
    if (!['common_inventory', 'reserved_for_work_order'].includes(purpose) || purpose === this.data.purpose) return;
    this.setData({ purpose, targetWorkOrder: {}, targetUnit: {}, targetOperation: {}, dirty: true }); this.remember();
  },
  clearTarget() {
    // Persisted purpose and target identity are immutable; edits are revalidated by the server.
    if (!this.data.id) this.setData({ targetWorkOrder: {}, targetUnit: {}, targetOperation: {} });
  },
  openPicker(event) {
    if (!this.data.editable || this.data.planOnly || this.data.busy || this.data.pending) return;
    const type = event.currentTarget.dataset.type;
    if ((type === 'items' || type.startsWith('reserved_')) && this.data.id) return;
    if (type.startsWith('reserved_') && !this.data.operation.id) return this.toast('请先选择加工终点');
    if (['reserved_units', 'reserved_operations'].includes(type) && !this.data.targetWorkOrder.id) return this.toast('请先选择目标工单');
    if (type === 'reserved_operations' && this.data.targetWorkOrder.execution_mode === 'unit' && !this.data.targetUnit.id) return this.toast('请先选择生产单元');
    if (type !== 'items' && !this.data.item.id) return this.toast('请先选择产出物料');
    if (type === 'routing_operations' && !this.data.routing.id) return this.toast('请先选择工艺路线');
    this.setData({ picker: type, pickerTitle: { items: '选择产出物料', routings: '选择工艺路线', routing_operations: '选择目标工序', reserved_work_orders: '选择目标工单', reserved_units: '选择生产单元', reserved_operations: '选择接收工序' }[type], keyword: '', categoryId: '', targetStatus: '', optionError: '', page: 1, lastPage: 1, options: [], selected: { items: this.data.item, routings: this.data.routing, routing_operations: this.data.operation, reserved_work_orders: this.data.targetWorkOrder, reserved_units: this.data.targetUnit, reserved_operations: this.data.targetOperation }[type] });
    this.loadOptions(1);
    if (type === 'items') this.loadCategories(1);
  },
  closePicker() { this.optionSequence = (this.optionSequence || 0) + 1; this.categorySequence = (this.categorySequence || 0) + 1; this.setData({ picker: '', optionLoading: false, categoryLoading: false }); },
  noop() {},
  keyword(event) { this.optionSequence = (this.optionSequence || 0) + 1; this.setData({ keyword: event.detail.value, options: [], optionLoading: false }); },
  targetStatus(event) { this.setData({ targetStatus: event.currentTarget.dataset.status || '' }); return this.loadOptions(1); },
  search() { this.loadOptions(1); },
  loadOptions(page) {
    const sequence = this.optionSequence = (this.optionSequence || 0) + 1;
    this.setData({ optionLoading: true, options: [], optionError: '' });
    return workOrders.options(this.data.picker, { keyword: this.data.keyword.trim(), category_id: this.data.categoryId, output_item_id: this.data.item.id, routing_id: this.data.routing.id, target_routing_operation_id: this.data.operation.id, reserved_for_work_order_id: this.data.targetWorkOrder.id, reserved_for_production_unit_id: this.data.targetUnit.id, status: this.data.targetStatus, page, per_page: 15 }).then(response => {
      if (sequence === this.optionSequence) this.setData({ options: (response.data || []).map(row => Object.assign({}, row, { statusLabel: STATUS[row.status] || row.status || '' })), page: Number(response.current_page), lastPage: Number(response.last_page) });
    }).catch(error => { if (sequence === this.optionSequence) this.setData({ optionError: error.message || '加载失败，请重试' }); }).finally(() => { if (sequence === this.optionSequence) this.setData({ optionLoading: false }); });
  },
  loadCategories(page) {
    const sequence = this.categorySequence = (this.categorySequence || 0) + 1;
    this.setData({ categoryLoading: true });
    return workOrders.options('categories', { page, per_page: 15 }).then(response => {
      if (sequence === this.categorySequence) this.setData({ categories: response.data || [], categoryPage: Number(response.current_page), categoryLastPage: Number(response.last_page) });
    }).catch(error => { if (sequence === this.categorySequence) this.toast(error.message); }).finally(() => { if (sequence === this.categorySequence) this.setData({ categoryLoading: false }); });
  },
  category(event) { this.setData({ categoryId: event.currentTarget.dataset.id || '' }); this.loadOptions(1); },
  pageOptions(event) { const next = this.data.page + Number(event.currentTarget.dataset.step); if (!this.data.optionLoading && next >= 1 && next <= this.data.lastPage) this.loadOptions(next); },
  pageCategories(event) { const next = this.data.categoryPage + Number(event.currentTarget.dataset.step); if (!this.data.categoryLoading && next >= 1 && next <= this.data.categoryLastPage) this.loadCategories(next); },
  select(event) { const row = this.data.options.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (!this.data.optionLoading && row) this.setData({ selected: row }); },
  confirmSelection() {
    const selected = this.data.selected; if (!selected || !selected.id) return this.toast('请选择一项');
    const picker = this.data.picker;
    if (picker === 'items' && selected.id !== this.data.item.id) { this.routeSequence = (this.routeSequence || 0) + 1; this.setData({ item: selected, routing: {}, operation: {}, routeSummary: [] }); this.clearTarget(); }
    if (picker === 'routings' && selected.id !== this.data.routing.id) { this.setData({ routing: selected, operation: {}, routeSummary: [] }); this.clearTarget(); this.loadRoute(); }
    if (picker === 'routing_operations' && selected.id !== this.data.operation.id) { this.setData({ operation: selected }); this.clearTarget(); this.loadRoute(); }
    if (picker === 'reserved_work_orders' && selected.id !== this.data.targetWorkOrder.id) this.setData({ targetWorkOrder: selected, targetUnit: {}, targetOperation: {} });
    if (picker === 'reserved_units' && selected.id !== this.data.targetUnit.id) this.setData({ targetUnit: selected, targetOperation: {} });
    if (picker === 'reserved_operations') this.setData({ targetOperation: selected });
    this.setData({ dirty: true });
    this.closePicker(); this.remember();
  },
  loadRoute(page = 1) {
    const sequence = this.routeSequence = (this.routeSequence || 0) + 1;
    this.setData({ routeLoading: true, routeError: '', routeSummary: [] });
    return workOrders.options('routing_preview', { output_item_id: this.data.item.id, routing_id: this.data.routing.id, target_routing_operation_id: this.data.operation.id, page, per_page: 10 })
      .then(response => { if (sequence === this.routeSequence) this.setData({ routeSummary: (response.data || []).map(row => ({ id: row.id, name: row.operation_name, sequence: row.sequence })), routePage: Number(response.current_page), routeLastPage: Number(response.last_page) }); })
      .catch(error => { if (sequence === this.routeSequence) this.setData({ routeError: error.message || '路线加载失败' }); })
      .finally(() => { if (sequence === this.routeSequence) this.setData({ routeLoading: false }); });
  },
  routePage(event) { const next = this.data.routePage + Number(event.currentTarget.dataset.step); if (!this.data.routeLoading && next >= 1 && next <= this.data.routeLastPage) return this.loadRoute(next); },
  retryRoute() { return this.loadRoute(this.data.routePage); },
  openWorkOrder() { if (this.data.id) wx.navigateTo({ url: '/pages/production/work-order-detail/index?id=' + this.data.id }); },
  selectTab(event) { const tab = event.currentTarget.dataset.tab; if (['plan', 'technical', 'check'].includes(tab)) this.setData({ activeTab: tab }); },
  openAttachment(event) {
    if (this.data.attachmentBusy) return;
    const file = this.data.technicalFiles.find(row => Number(row.id) === Number(event.currentTarget.dataset.id));
    if (!file) return;
    this.setData({ attachmentBusy: file.id });
    return attachments.open(this.data.id, file).catch(error => this.toast(error.message || '附件打开失败')).finally(() => this.setData({ attachmentBusy: 0 }));
  },
  save() {
    if (this.data.busy || !this.data.editable || this.data.pending) return;
    if (this.data.id && this.data.planOnly) return this.execute('save-plan', {
      planned_date: this.data.form.planned_date || null, expected_version: this.version,
    });
    if (!this.data.item.id || !this.data.routing.id || !this.data.operation.id) return this.toast('请选择产出物料、工艺路线和目标工序');
    if (!/^\d+(\.\d{1,8})?$/.test(this.data.form.target_qty) || Number(this.data.form.target_qty) <= 0) return this.toast('请输入有效的计划数量');
    if (this.data.purpose === 'reserved_for_work_order' && (!this.data.targetWorkOrder.id || !this.data.targetOperation.id || (this.data.targetWorkOrder.execution_mode === 'unit' && !this.data.targetUnit.id))) return this.toast('请选择完整的指定工单去向');
    if (!this.data.id && !this.data.reservation) return this.toast('工单编号尚未取得，请下拉重试');
    const payload = Object.assign({}, this.data.form, { planned_date: this.data.form.planned_date || null, production_routing_id: this.data.routing.id, target_routing_operation_id: this.data.operation.id });
    delete payload.production_location_name;
    delete payload.production_batch;
    if (this.data.id) payload.expected_version = this.version;
    else Object.assign(payload, { source_type: 'stock_prebuild', stocking_purpose: this.data.purpose, output_item_id: this.data.item.id, reservation_token: this.data.reservation, creation_session_id: this.session });
    if (!this.data.id && this.data.purpose === 'reserved_for_work_order') Object.assign(payload, { reserved_for_work_order_id: this.data.targetWorkOrder.id, reserved_for_production_unit_id: this.data.targetWorkOrder.execution_mode === 'unit' ? this.data.targetUnit.id : null, reserved_for_target_operation_id: this.data.targetOperation.id });
    return this.execute('save', payload);
  },
  transition(event) {
    if (this.data.busy || this.data.pending) return;
    if (this.data.dirty) return this.toast('请先保存草稿，再执行提交或发布');
    const action = event.currentTarget.dataset.action;
    const label = { submit: '提交工单', publish: '发布工单', 'return-draft': '退回草稿' }[action];
    wx.showModal({ title: label, editable: true, placeholderText: '请输入操作原因', success: result => {
      if (result.confirm && String(result.content || '').trim()) this.execute(action, { expected_version: this.version, reason: result.content.trim() });
    } });
  },
  retry() { if (this.data.pending && !this.data.busy) return this.execute(this.data.pending.action, this.data.pending.payload); },
  execute(action, payload) {
    if (this.data.busy) return Promise.resolve();
    let refresh = false;
    this.setData({ busy: true, error: '', gate: null });
    return (action === 'save' ? workOrders.save(this.data.id, payload) : action === 'save-plan' ? workOrders.savePlan(this.data.id, payload) : workOrders.transition(this.data.id, action, payload)).then(response => {
      if (!this.data.id) wx.removeStorageSync(this.storageKey);
      this.setData({ id: Number(response.data.id), pending: null }); this.toast(response.message || '已保存');
      refresh = true;
    }).catch(error => { refresh = error.statusCode === 409; this.fail(error); }).finally(async () => {
      this.setData({ busy: false }); this.restorePending();
      // Uncertain requests keep their original payload/version; never refresh them into a new write.
      if (refresh && !this.data.pending && this.data.id) {
        const message = this.data.error;
        await this.load();
        if (message) this.setData({ error: message });
      }
    });
  },
  checkGate() {
    if (this.data.busy || this.data.pending) return;
    if (this.data.dirty) return this.toast('请先保存草稿');
    this.setData({ busy: true, error: '' });
    return workOrders.gate(this.data.id).then(async response => {
      this.setData({ gate: response.data });
      const detail = await workOrders.detail(this.data.id);
      this.version = detail.data.business_version;
      this.setData({ actions: detail.data.actions || {} });
    }).catch(error => this.fail(error)).finally(() => this.setData({ busy: false }));
  },
  openTasks() { wx.navigateTo({ url: `/pages/production/queue/index?type=work_order&workOrderId=${this.data.id}` }); },
  fail(error) { this.setData({ error: error.message || '操作失败，请重试' }); },
  toast(title) { wx.showToast({ title, icon: 'none', duration: 2500 }); },
});
