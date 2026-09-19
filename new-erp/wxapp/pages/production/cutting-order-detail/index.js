const cutting = require('../../../services/cutting');

function dataOf(response) { return response && response.data !== undefined ? response.data : (response || {}); }
function pageOf(value) {
  const page = value || {}; const meta = page.meta || {};
  return { rows: Array.isArray(page.data) ? page.data : [], currentPage: Number(meta.current_page || 1), lastPage: Number(meta.last_page || 1), total: Number(meta.total || 0) };
}
function can(code) { const p = wx.getStorageSync('erp_permissions') || []; return Array.isArray(p) ? p.includes(code) : p[code] === true; }
const ACTION_LABELS = { claim: '接手历史任务', start: '开始加工', pause: '暂停计时', resume: '恢复计时', finish: '完成加工任务',
  collaboratorStart: '开始协同计时', collaboratorPause: '暂停协同计时', leave: '退出协同' };
function dimensionsText(value) {
  if (!value) return '';
  let parsed = value;
  if (typeof value === 'string') { try { parsed = JSON.parse(value); } catch (_) { return value; } }
  if (!parsed || typeof parsed !== 'object') return '';
  const length = parsed.length_mm || parsed.length;
  const width = parsed.width_mm || parsed.width;
  const thickness = parsed.thickness_mm || parsed.thickness;
  return [length, width, thickness].filter(v => v !== undefined && v !== null && v !== '').join(' × ') + (length ? ' mm' : '');
}
function presentInput(item) {
  const physical = !!item.physical_material_id;
  return Object.assign({}, item, {
    source_no: item.physical_no || item.batch_no || `批次 #${item.id}`,
    source_name: [item.item_name, item.spec].filter(Boolean).join('  '),
    dimension_text: dimensionsText(item.dimensions) || (item.standard_stock_length_mm ? `${item.standard_stock_length_mm} mm` : ''),
    usage_label: physical ? '整张使用' : `投入 ${Number(item.input_qty || 0)} 根`,
    status_tone: item.status === 'CONFIRMED' ? 'success' : (item.status === 'PROCESSING' ? 'warning' : 'neutral'),
  });
}
function presentCandidate(item, selected) {
  const physical = !!item.physical_no;
  const remnant = item.source_type === 'REMNANT_WIP';
  const key = physical ? `physical:${item.id}` : (remnant ? `remnant:${item.remnant_holding_id}` : `balance:${item.inventory_balance_id}`);
  return Object.assign({}, item, {
    _key: key,
    _selected: selected.some(row => row._key === key),
    source_no: item.physical_no || item.batch_no || '-',
    source_name: [item.item_name, item.spec].filter(Boolean).join('  '),
    dimension_text: dimensionsText(item.dimensions) || (item.standard_stock_length_mm ? `原料长度 ${item.standard_stock_length_mm} mm` : ''),
    available_label: physical ? (item.material_form === 'REMNANT' ? '实物余料' : '整张钢板') : (remnant ? '定长余料 · 1根' : `可用 ${Number(item.available_root_qty || 0)} 根`),
  });
}

Page({
  data: {
    taskId: 0, orderId: 0, loading: true, busy: false,
    task: {}, order: {}, lifecycle: {}, inputs: [], participants: [],
    isOwner: false, canAddInput: false, actionName: '', secondaryActionName: '', elapsedLabel: '00:00',
    flow: { confirm: 0, handover: 0, warehouse: 0 },
    showInput: false, inputType: 'physical', inputKeyword: '', categories: [], categoryId: '',
    inputCandidates: [], selectedInputs: [], inputPage: 1, inputLastPage: 1, inputTotal: 0, inputLoading: false,
    quantityValue: '1',
    inputsPage: 1, inputsLastPage: 1, inputsTotal: 0, myLabor: {}, isCollaborator: false, actionLabel: '', secondaryActionLabel: '',
    categoryPage: 1, categoryLastPage: 1, showSelected: false,
    showFlow: false, flowType: '', flowRows: [], flowPage: 1, flowLastPage: 1, flowLoading: false,
  },
  onLoad(options) {
    this.setData({ taskId: Number(options.taskId || options.id || 0), orderId: Number(options.orderId || 0) });
  },
  onShow() { if (this.data.taskId) this.load(); },
  onHide() { clearInterval(this.timer); },
  onUnload() { clearInterval(this.timer); this.loadSequence = (this.loadSequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  noop() {},
  load(page = 1) {
    if (typeof page !== 'number') page = 1;
    const sequence = this.loadSequence = (this.loadSequence || 0) + 1;
    this.setData({ loading: true });
    const detail = cutting.taskExecution(this.data.taskId, { page, per_page: 20 });
    const overview = this.data.orderId ? cutting.orderExecution(this.data.orderId, { page: 1, per_page: 20 }) : Promise.resolve({ data: {} });
    return Promise.all([detail, overview]).then(([taskResponse, orderResponse]) => {
      if (sequence !== this.loadSequence) return;
      const payload = dataOf(taskResponse);
      const orderPayload = dataOf(orderResponse);
      const task = payload.task || {};
      const order = payload.order || orderPayload.order || {};
      const actor = wx.getStorageSync('erp_user') || {};
      const isOwner = Number(task.assignee_user_legacy_id || 0) === Number(actor.legacy_id || actor.id || 0);
      const isCollaborator = (payload.participants || []).some(row => Number(row.employee_legacy_id) === Number(actor.legacy_id || actor.id) && row.role === 'collaborator' && !row.left_at);
      const myLabor = payload.my_labor || {};
      const actions = this.actionsFor(task, isOwner, !!myLabor.active_session_id, isCollaborator);
      const flow = orderPayload.flow || { confirm: 0, handover: 0, warehouse: 0 };
      Object.keys(flow).forEach(key => { flow[key] = Number(flow[key] || 0); });
      const inputPage = pageOf(payload.inputs);
      order.display_status = ({ PUBLISHED: '待加工', IN_PROGRESS: '加工中', CLOSED: '已关闭', CANCELLED: '已取消' })[order.status] || '-';
      this.clockOffset = payload.server_now ? new Date(payload.server_now).getTime() - Date.now() : 0;
      this.setData({ task, order, orderId: Number(order.id || this.data.orderId), lifecycle: orderPayload.lifecycle || {},
        inputs: inputPage.rows.map(presentInput), inputsPage: inputPage.currentPage, inputsLastPage: inputPage.lastPage, inputsTotal: inputPage.total,
        participants: payload.participants || [], isOwner, isCollaborator, myLabor,
        canAddInput: can('production.cutting.issue') && !['FINISHED', 'CANCELLED'].includes(task.status) && !['CLOSED', 'CANCELLED'].includes(order.status),
        actionName: actions[0] || '', secondaryActionName: actions[1] || '', actionLabel: ACTION_LABELS[actions[0]] || '', secondaryActionLabel: ACTION_LABELS[actions[1]] || '', flow, loading: false });
      clearInterval(this.timer); this.tick(); this.timer = setInterval(() => this.tick(), 1000);
    }).catch((error) => {
      this.setData({ loading: false });
      wx.showToast({ title: (error && error.message) || '任务详情加载失败', icon: 'none' });
    });
  },
  prevBatches() { if (this.data.inputsPage > 1) return this.load(this.data.inputsPage - 1); },
  nextBatches() { if (this.data.inputsPage < this.data.inputsLastPage) return this.load(this.data.inputsPage + 1); },
  actionsFor(task, isOwner, active = false, isCollaborator = false) {
    if (task.status === 'WAIT_CLAIM') return ['claim'];
    if (!isOwner) return isCollaborator && ['IN_PROGRESS', 'PAUSED'].includes(task.status) ? [active ? 'collaboratorPause' : 'collaboratorStart', 'leave'] : [];
    if (task.status === 'READY') return ['start'];
    if (['IN_PROGRESS', 'PAUSED'].includes(task.status)) return ['finish', active ? 'pause' : 'resume'];
    return [];
  },
  tick() {
    const labor = this.data.myLabor;
    const active = labor.active_session_id && labor.started_at ? Math.max(0, Math.floor((Date.now() + (this.clockOffset || 0) - new Date(labor.started_at).getTime()) / 1000)) : 0;
    const seconds = Math.floor(Number(labor.closed_minutes || 0) * 60) + active;
    this.setData({ elapsedLabel: [Math.floor(seconds / 3600), Math.floor(seconds % 3600 / 60), seconds % 60].map(value => String(value).padStart(2, '0')).join(':') });
  },
  runTaskAction(event) {
    const action = event.currentTarget.dataset.action;
    const map = { claim: cutting.claimTask, start: cutting.startTask, pause: cutting.pauseTask, resume: cutting.resumeTask, finish: cutting.finishTask,
      collaboratorStart: cutting.startCollaborator, collaboratorPause: cutting.pauseCollaborator, leave: cutting.leaveCollaboration };
    const labels = ACTION_LABELS;
    if (!map[action] || this.data.busy) return;
    const execute = (switchData = {}) => {
      this.setData({ busy: true });
      return map[action](this.data.taskId, Object.assign({ expected_version: Number(this.data.task.business_version) }, switchData))
        .then(() => { wx.showToast({ title: `${labels[action]}成功`, icon: 'success' }); return this.load(); })
        .catch(error => {
          if (error.errorCode === 'labor_switch_confirmation_required') {
            wx.showModal({ title: '切换本人计时', content: error.message, success: result => {
              if (result.confirm) execute({ switch_active_labor: true, expected_active_labor_session_id: Number(error.details.active_labor_session_id) });
            } });
          } else wx.showToast({ title: (error && error.message) || `${labels[action]}失败`, icon: 'none' });
        })
        .finally(() => this.setData({ busy: false }));
    };
    if (action === 'finish') wx.showModal({ title: '完成加工任务', content: '请确认所有现场加工已经完成。加工结果仍需逐批提交和核算。', success: result => { if (result.confirm) execute(); } });
    else execute();
  },
  goRecord(event) {
    const settlementId = Number(event.currentTarget.dataset.id || 0);
    if (!settlementId) return;
    wx.navigateTo({ url: `/pages/production/cutting-record/index?orderId=${this.data.orderId}&settlementId=${settlementId}` });
  },

  openAddInput() {
    if (this.data.busy) return;
    this.setData({ showInput: true, inputType: 'physical', inputKeyword: '', categoryId: '', categories: [], inputCandidates: [], selectedInputs: [], quantityValue: '1', showSelected: false });
    Promise.all([this.loadInputCategories(), this.loadInputs(1)]).catch(() => null);
  },
  closeAddInput() { if (!this.data.busy) this.setData({ showInput: false }); },
  setInputType(event) {
    const inputType = event.currentTarget.dataset.type;
    if (inputType === this.data.inputType) return;
    this.setData({ inputType, inputCandidates: [], selectedInputs: [], inputPage: 1, inputLastPage: 1, quantityValue: '1' });
    this.loadInputs(1);
  },
  loadInputCategories(page = 1) {
    return cutting.selectorCategories(this.data.orderId, { mode: 'inputs', page, per_page: 20 }).then(response => {
      const result = pageOf(response);
      this.setData({ categories: (page === 1 ? [{ id: '', category_name: '全部' }] : this.data.categories).concat(result.rows), categoryPage: result.currentPage, categoryLastPage: result.lastPage });
    });
  },
  moreCategories() { if (this.data.categoryPage < this.data.categoryLastPage) return this.loadInputCategories(this.data.categoryPage + 1); },
  toggleSelected() { this.setData({ showSelected: !this.data.showSelected }); },
  removeSelected(event) {
    const key = event.currentTarget.dataset.key; const selected = this.data.selectedInputs.filter(row => row._key !== key);
    this.setData({ selectedInputs: selected, inputCandidates: this.data.inputCandidates.map(row => Object.assign({}, row, { _selected: selected.some(item => item._key === row._key) })) });
  },
  onInputKeyword(event) { this.setData({ inputKeyword: event.detail.value }); },
  searchInputs() { this.loadInputs(1); },
  setCategory(event) { this.setData({ categoryId: event.currentTarget.dataset.id || '' }); this.loadInputs(1); },
  prevInputs() { if (this.data.inputPage > 1) this.loadInputs(this.data.inputPage - 1); },
  nextInputs() { if (this.data.inputPage < this.data.inputLastPage) this.loadInputs(this.data.inputPage + 1); },
  loadInputs(page) {
    const sequence = this.inputSequence = (this.inputSequence || 0) + 1;
    this.setData({ inputLoading: true });
    return cutting.listInputs(this.data.orderId, { input_type: this.data.inputType, keyword: String(this.data.inputKeyword || '').trim() || undefined,
      category_id: this.data.categoryId || undefined, page, per_page: 20 }).then(response => {
        if (sequence !== this.inputSequence) return;
        const result = pageOf(response);
        this.setData({ inputCandidates: result.rows.map(row => presentCandidate(row, this.data.selectedInputs)), inputPage: result.currentPage,
          inputLastPage: result.lastPage, inputTotal: result.total, inputLoading: false });
      }).catch(error => { this.setData({ inputLoading: false }); wx.showToast({ title: (error && error.message) || '可用材料加载失败', icon: 'none' }); });
  },
  toggleInput(event) {
    const key = event.currentTarget.dataset.key;
    const candidate = this.data.inputCandidates.find(row => row._key === key);
    if (!candidate) return;
    let selected = this.data.selectedInputs.slice();
    const index = selected.findIndex(row => row._key === key);
    if (this.data.inputType === 'quantity') selected = index >= 0 ? [] : [candidate];
    else if (index >= 0) selected.splice(index, 1); else selected.push(candidate);
    this.setData({ selectedInputs: selected, inputCandidates: this.data.inputCandidates.map(row => Object.assign({}, row, { _selected: selected.some(item => item._key === row._key) })) });
  },
  onQuantityValue(event) { this.setData({ quantityValue: event.detail.value }); },
  bumpQuantity(event) { this.setData({ quantityValue: String(Math.max(1, Number(this.data.quantityValue || 0) + Number(event.currentTarget.dataset.delta || 0))) }); },
  confirmAddInput() {
    if (this.data.busy) return;
    const selected = this.data.selectedInputs;
    if (!selected.length) return wx.showToast({ title: '请先选择实际用料', icon: 'none' });
    this.setData({ busy: true });
    const version = Number(this.data.order.business_version);
    let promise;
    if (this.data.inputType === 'physical') {
      const ids = selected.map(row => Number(row.id));
      promise = cutting.issuePhysicals(this.data.orderId, { expected_version: version, physical_material_ids: ids });
    } else {
      const row = selected[0];
      const payload = { expected_version: version };
      if (row.remnant_holding_id) payload.remnant_holding_id = Number(row.remnant_holding_id);
      else { payload.inventory_balance_id = Number(row.inventory_balance_id); payload.input_qty = String(this.data.quantityValue || ''); }
      promise = cutting.issue(this.data.orderId, payload);
    }
    return promise.then(() => { this.setData({ showInput: false }); wx.showToast({ title: '用料已正式领出', icon: 'success' }); return this.load(); })
      .catch(error => { wx.showToast({ title: (error && error.message) || '添加用料失败，请刷新核对', icon: 'none' }); return this.load(); })
      .finally(() => this.setData({ busy: false }));
  },
  openFlow(event) { this.setData({ showFlow: true, flowType: event.currentTarget.dataset.type }); return this.loadFlow(1); },
  closeFlow() { this.setData({ showFlow: false }); },
  loadFlow(page) {
    this.setData({ flowLoading: true });
    return cutting.orderExecution(this.data.orderId, { flow: this.data.flowType, page, per_page: 20 }).then(response => {
      const payload = dataOf(response); const result = pageOf(this.data.flowType === 'confirm' ? payload.inputs : payload.results);
      this.setData({ flowRows: result.rows.map(row => Object.assign({}, row, { record_id: row.settlement_batch_id || row.id })), flowPage: result.currentPage, flowLastPage: result.lastPage, flowLoading: false });
    }).catch(error => { this.setData({ flowLoading: false }); wx.showToast({ title: error.message || '流转记录加载失败', icon: 'none' }); });
  },
  prevFlow() { if (this.data.flowPage > 1) return this.loadFlow(this.data.flowPage - 1); },
  nextFlow() { if (this.data.flowPage < this.data.flowLastPage) return this.loadFlow(this.data.flowPage + 1); },
  runLifecycle(event) {
    const type = event.currentTarget.dataset.type;
    const fn = type === 'close' ? cutting.closeOrder : cutting.cancelOrder;
    const title = type === 'close' ? '关闭下料单' : '取消下料单';
    wx.showModal({ title, editable: true, placeholderText: '请填写原因', success: result => {
      const reason = String(result.content || '').trim();
      if (!result.confirm) return;
      if (!reason) return wx.showToast({ title: '请填写原因', icon: 'none' });
      this.setData({ busy: true });
      fn(this.data.orderId, { expected_version: Number(this.data.order.business_version), reason }).then(() => {
        wx.showToast({ title: `${title}成功`, icon: 'success' }); return this.load();
      }).catch(error => wx.showToast({ title: (error && error.message) || `${title}失败`, icon: 'none' }))
        .finally(() => this.setData({ busy: false }));
    } });
  },
});

module.exports = { dataOf, pageOf, dimensionsText, presentInput, presentCandidate };
