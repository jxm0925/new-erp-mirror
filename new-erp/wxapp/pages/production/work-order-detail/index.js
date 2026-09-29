const production = require('../../../services/production');
const STATUS = { DRAFT: '草稿', WAIT_RELEASE: '待发布', RELEASED: '已发布', IN_PROGRESS: '生产中', COMPLETED: '已完成', CLOSED: '已关闭', CANCELLED: '已取消' };
const STATES = { WAITING: '待生产', WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_PREVIOUS: '待前序交接', WAIT_PREDECESSOR: '待前序交接', WAIT_HANDOVER: '待前序交接', IN_PROGRESS: '生产中', PAUSED: '已暂停', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', COMPLETED: '已完成', EXCEPTION: '异常', REWORK: '返工中', QUALITY_FAILED: '质检不合格', HANDOVER_REJECTED: '交接拒收', CANCELLED: '已取消' };
const number = value => value === undefined || value === null || !Number.isFinite(Number(value)) ? '—' : String(Number(Number(value).toFixed(8)));
const tone = status => status === 'COMPLETED' ? 'green' : (['EXCEPTION', 'REWORK', 'QUALITY_FAILED', 'HANDOVER_REJECTED', 'CANCELLED'].includes(status) ? 'red' : (status === 'IN_PROGRESS' ? 'blue' : 'orange'));
function formatUnit(unit) {
  const e = unit.execution || {}, op = e.current_operation || {}, task = e.current_task || {};
  const state = unit.display_status || unit.status;
  return Object.assign({}, unit, { statusLabel: STATES[state] || state, tone: tone(state), operationName: op.name || '—',
    sequenceText: op.position ? '工序 ' + op.position + '/' + op.total : (op.sequence ? '序号 ' + op.sequence : ''),
    taskId: task.id, taskNo: task.task_no || '—', canViewTask: !!(unit.actions || {}).view_task,
    kittingLabel: (e.kitting || {}).label || '—', kittingTone: (e.kitting || {}).status === 'CONFIRMED' ? 'green' : 'orange',
    handoverLabel: (e.previous_handover || {}).label || '—', handoverTone: (e.previous_handover || {}).status === 'RECEIVED' ? 'blue' : 'gray' });
}
function formatOperation(op) {
  const q = op.quantity || {};
  return Object.assign({}, op, { statusLabel: STATES[op.status] || op.status, tone: tone(op.status),
    planned: number(q.planned_qty), completed: number(q.completed_qty), unqualified: number(q.unqualified_qty), scrapped: number(q.scrapped_qty),
    unit: q.unit_name || '', taskId: (op.task || {}).id, taskNo: (op.task || {}).task_no || '—', canViewTask: !!(op.actions || {}).view_task });
}
Page({
  data: { workOrderId: 0, masterId: 0, loading: true, error: '', errorCode: '', errorTitle: '', workOrder: null,
    mode: 'unit', actions: {}, metrics: [], rows: [], rowTotal: 0, rowPage: 0, rowHasMore: false, rowsLoading: false,
    rowsError: '', rowsForbidden: false, currentFilter: '', activeFilterLabel: '', filterOptions: [], showFilter: false },
  onLoad(options) { this.setData({ workOrderId: Number(options.id || options.workOrderId || 0), masterId: Number(options.masterId || 0) }); },
  onShow() { return this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; this.rowsSequence = (this.rowsSequence || 0) + 1; },
  onPullDownRefresh() { return this.load().finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() { if (this.data.rowHasMore && !this.data.rowsLoading) return this.loadRows(true); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.rowsSequence = (this.rowsSequence || 0) + 1;
    this.setData({ loading: true, error: '', rows: [], rowsError: '', rowsLoading: false, rowHasMore: false });
    if (!this.data.workOrderId) { this.setData({ loading: false, error: '工单参数无效', errorCode: 'not_found', errorTitle: '工单不存在' }); return Promise.resolve(); }
    return production.workOrder(this.data.workOrderId).then(response => {
      if (sequence !== this.sequence) return;
      const wo = response.data || {}, q = (wo.execution_summary || {}).quantity || {}, tasks = (wo.execution_summary || {}).tasks || {};
      const product = wo.product || {}, route = wo.routing || {}, end = route.target_routing_operation || {};
      const mode = wo.execution_mode || 'unit', actions = wo.actions || {}, unit = q.unit_name || (wo.quantity || {}).unit_name || '';
      const metric = (label, value, color) => ({ label, value: number(value), unit, color });
      const metrics = [metric('计划', q.planned_qty === undefined ? (wo.quantity || {}).target_qty : q.planned_qty, ''),
        metric('完成', q.completed_qty, 'green'), metric('在制', q.in_progress_qty, 'blue'),
        metric(mode === 'unit' ? '异常' : '待生产', mode === 'unit' ? q.exception_qty : q.waiting_qty, mode === 'unit' ? 'red' : 'orange'),
        { label: '工序完成', value: number(tasks.completed) + '/' + number(tasks.total), unit: '', color: '' }];
      this.setData({ loading: false, mode, actions, metrics, masterId: Number(wo.production_master_order_id || 0),
        workOrder: Object.assign({}, wo, { productName: product.item_name || product.name || '—',
          sourceLabel: wo.source_type === 'sales_order' ? '销售生产' : (wo.stocking_purpose === 'reserved_for_work_order' ? '指定工单备货' : (wo.stocking_purpose === 'common_inventory' ? '公共库存备货' : wo.source_type_label)),
          sourceNo: (wo.source || {}).no || '', statusLabel: STATUS[wo.status] || wo.status,
          endpoint: (end.sequence ? end.sequence + ' - ' : '') + (end.operation_name || route.target_operation_name || '—'),
          canManage: false }),
        rowsForbidden: mode === 'unit' ? !actions.view_units : !actions.view_operations,
        currentFilter: '', activeFilterLabel: '',
        filterOptions: [{ key: '', label: '全部' }].concat((mode === 'unit'
          ? ['WAITING', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'IN_PROGRESS', 'COMPLETED', 'EXCEPTION']
          : ['WAIT_PREVIOUS', 'WAIT_CLAIM', 'WAIT_MATERIAL', 'IN_PROGRESS', 'PAUSED', 'WAIT_QUALITY', 'WAIT_WAREHOUSE', 'COMPLETED', 'REWORK']).map(key => ({ key, label: STATES[key] }))) });
      if (!this.data.rowsForbidden) return this.loadRows();
    }).catch(error => {
      if (sequence !== this.sequence) return;
      const code = error.statusCode === 404 ? 'not_found' : (error.statusCode === 403 ? 'forbidden' : 'load');
      this.setData({ loading: false, workOrder: null, errorCode: code, errorTitle: { not_found: '工单不存在', forbidden: '无权查看工单', load: '加载失败' }[code], error: error.message || '请检查网络连接后重试' });
    });
  },
  loadRows(append = false) {
    if (this.data.rowsForbidden || !this.data.workOrder) return Promise.resolve();
    if (append && this.data.rowsLoading) return Promise.resolve();
    const sequence = this.rowsSequence = (this.rowsSequence || 0) + 1, page = append ? this.data.rowPage + 1 : 1;
    const params = { page, per_page: 10 };
    params[this.data.mode === 'unit' ? 'display_status' : 'status'] = this.data.currentFilter;
    this.setData(Object.assign({ rowsLoading: true, rowsError: '' }, append ? {} : { rows: [], rowTotal: 0, rowPage: 0, rowHasMore: false }));
    const api = this.data.mode === 'unit' ? production.workOrderUnits : production.workOrderOperations;
    return api(this.data.workOrderId, params).then(response => {
      if (sequence !== this.rowsSequence) return;
      const incoming = (response.data || []).map(this.data.mode === 'unit' ? formatUnit : formatOperation);
      const previous = append ? this.data.rows : [], keys = new Set(previous.map(row => row.id));
      this.setData({ rows: previous.concat(incoming.filter(row => !keys.has(row.id))), rowTotal: Number(response.total || 0),
        rowPage: Number(response.current_page || page), rowHasMore: Number(response.current_page || page) < Number(response.last_page || page), rowsLoading: false });
    }).catch(error => {
      if (sequence !== this.rowsSequence) return;
      this.setData({ rowsLoading: false, rowsForbidden: error.statusCode === 403, rowsError: error.message || '加载失败，请重试' });
    });
  },
  retryRows() { return this.loadRows(this.data.rows.length > 0); },
  openFilterPicker() {
    this.setData({ showFilter: true });
  },
  closeFilter() { this.setData({ showFilter: false }); },
  noop() {},
  applyFilter(event) {
    const choice = this.data.filterOptions.find(row => row.key === event.currentTarget.dataset.key);
    if (!choice) return;
    this.setData({ currentFilter: choice.key, activeFilterLabel: choice.key ? choice.label : '', showFilter: false }); return this.loadRows();
  },
  openUnit(event) {
    const id = Number(event.currentTarget.dataset.id); if (!this.data.actions.view_units || !id) return;
    wx.navigateTo({ url: '/pages/production/unit-detail/index?unitId=' + id + '&workOrderId=' + this.data.workOrderId + '&masterId=' + this.data.masterId });
  },
  openTask(event) {
    const row = this.data.rows.find(item => item.id === Number(event.currentTarget.dataset.id));
    if (row && row.canViewTask && row.taskId) wx.navigateTo({ url: '/pages/production/task-detail/index?id=' + row.taskId });
  },
  openTasks() { if (this.data.actions.view_tasks) wx.navigateTo({ url: '/pages/production/queue/index?type=work_order&workOrderId=' + this.data.workOrderId }); },
  openMaster() { if (this.data.masterId && this.data.actions.view_master_order) wx.navigateTo({ url: '/pages/production/master-detail/index?id=' + this.data.masterId }); },

  backToList() { wx.redirectTo({ url: '/pages/production/tasks/index' }); },
  openMaterials() { if (this.data.actions.view_materials) return this.showMaterials(1); },
  showMaterials(page) {
    return production.workOrderMaterials(this.data.workOrderId, { page, per_page: 5 }).then(response => {
      const rows = response.data || [], more = Number(response.current_page || page) < Number(response.last_page || page);
      const content = rows.length ? rows.map(row => ((row.component || {}).name || '—') + '  ' + number((row.quantity || {}).required_qty) + ((row.unit || {}).name || '')).join('\n') : '暂无物料需求';
      wx.showModal({ title: '物料需求 · 第' + page + '页', content, confirmText: more ? '下一页' : '关闭', showCancel: more, cancelText: '关闭', success: result => { if (result.confirm && more) this.showMaterials(page + 1); } });
    }).catch(error => wx.showToast({ title: error.message || '物料加载失败', icon: 'none' }));
  },
});
