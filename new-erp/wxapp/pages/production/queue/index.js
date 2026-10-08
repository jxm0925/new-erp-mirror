const production = require('../../../services/production');
const decisionCommand = require('../../../utils/production-decision-command');

const TITLES = { work_order: '工序任务', pool: '待接任务', collaboration: '我的协同', deliveries: '物料配送', receipts: '物料签收', handover: '待交接', kitting: '待齐套', trace: '扫码追溯', outputs_quality: '产出质检', internal_receive: '半成品接收', supplements: '补料审批', return_quality: '退料质检' };
const STATUS = { WAIT_PREVIOUS: '待前工序', WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待处理', WAIT_PICK: '待拣货', PICKING: '拣货中', IN_PROGRESS: '加工中', PAUSED: '已暂停', WAIT_ISSUE: '待发料', ISSUED: '待接收', SUBMITTED: '待处理', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工中', COMPLETED: '已完成', CANCELLED: '已取消', DELIVERED: '待收料' };
const EXECUTION_TYPES = ['outputs_quality', 'internal_receive', 'supplements', 'return_quality'];
const TASK_TYPES = ['pool', 'work_order', 'collaboration', 'kitting'];

Page({
  data: { type: '', title: '生产待办', loading: true, loadingMore: false, loaded: false, page: 0, total: 0, busy: false, rows: [], keyword: '', workOrderId: 0, trace: null, showPublicFilter: false, publicFilter: '' },
  onLoad(options) {
    const permissions = wx.getStorageSync('erp_permissions') || [];
    const can = code => Array.isArray(permissions) ? permissions.includes(code) : permissions[code] === true;
    this.setData({ canPacking: can('sales_order.shipment.packing.execute') || can('sales_order.shipment.packing.quality') });
    const type = ['picking', 'outputs_warehouse', 'internal_dispatch', 'return_receive'].includes(options.type) ? 'pool' : (options.type || 'pool');
    this.setData({ workOrderId: Number(options.workOrderId || 0), type, title: TITLES[type] || '生产待办', keyword: decodeURIComponent(options.keyword || ''),
      showPublicFilter: TASK_TYPES.includes(type), publicFilter: ['1', '0'].includes(String(options.is_public)) ? String(options.is_public) : '' });
    wx.setNavigationBarTitle({ title: this.data.title });
    if (type === 'assignments') {
      this.setData({ title: '待接受派单' });
      wx.setNavigationBarTitle({ title: '待接受派单' });
    }
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() {
    if (!this.data.loading && !this.data.loadingMore && this.data.rows.length < this.data.total) this.load(true);
  },
  onUnload() { this.requestSequence = (this.requestSequence || 0) + 1; },
  load(append = false) {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, rows: [] });
      wx.showToast({ title: '请先登录统一账号', icon: 'none' });
      return Promise.resolve();
    }
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    const page = append ? this.data.page + 1 : 1;
    const params = { page, per_page: 20, keyword: this.data.keyword.trim() };
    if (TASK_TYPES.includes(this.data.type)) params.is_public = this.data.publicFilter;
    this.setData(append ? { loadingMore: true } : { loading: true, loaded: false, rows: [], page: 0, total: 0 });
    let promise;
    if (this.data.type === 'pool') promise = production.taskPool(params);
    else if (this.data.type === 'assignments') promise = production.assignments(params);
    else if (this.data.type === 'work_order') promise = production.workOrderTasks(this.data.workOrderId, params);
    else if (this.data.type === 'collaboration') promise = production.collaborations(params);
    else if (this.data.type === 'kitting') promise = production.myTasks(Object.assign({}, params, { execution_filter: 'kitting' }));
    else if (this.data.type === 'deliveries') promise = production.deliveries(Object.assign({}, params, { status_group: 'pending_dispatch' }));
    else if (this.data.type === 'receipts') promise = production.deliveries(Object.assign({}, params, { status: 'DELIVERED' }));
    else if (this.data.type === 'handover') promise = production.pendingHandovers(params);
    else if (this.data.type === 'outputs_quality') promise = production.outputs(Object.assign({}, params, { status: 'WAIT_QUALITY' }));
    else if (this.data.type === 'internal_receive') promise = production.internalIssues(Object.assign({}, params, { status: 'ISSUED' }));
    else if (this.data.type === 'supplements') promise = production.supplements(Object.assign({}, params, { status: 'SUBMITTED' }));
    else if (this.data.type === 'return_quality') promise = production.materialReturns(Object.assign({}, params, { status: 'WAIT_QUALITY' }));
    else promise = production.trace(this.data.keyword);

    return promise.then((response) => {
      if (sequence !== this.requestSequence) return;
      if (this.data.type === 'trace') { this.setData({ trace: response.data || response, loading: false }); return; }
      let rows = response.data || [];
      rows = rows.map((row) => {
        if (this.data.type === 'assignments') return Object.assign({}, row, {
          statusLabel: '待接受派单', workOrderNo: row.work_order_no || '-', productName: row.item_name || '-',
        });
        if (EXECUTION_TYPES.includes(this.data.type)) return Object.assign({}, row, {
          statusLabel: STATUS[row.status] || row.status || '待处理',
          recordNo: row.output_no || row.issue_no || row.request_no || row.return_no || row.task_no || `#${row.id}`,
          workOrderNo: row.work_order_no || (row.work_order && row.work_order.work_order_no) || '-',
          productName: row.item_name || row.work_order_item_name || (row.work_order && row.work_order.output_item && row.work_order.output_item.item_name) || '-',
          operationLabel: row.operation_name_snapshot || '-',
        });
        const targets = row.target_details || [];
        const target = (this.data.type === 'kitting' ? targets.find((item) => item.status === 'WAIT_MATERIAL') : targets.find((item) => !['COMPLETED', 'CANCELLED'].includes(item.status))) || targets[0] || {};
        const workOrder = row.work_order || {};
        return Object.assign({}, row, {
          showPublicBadge: row.is_public_snapshot === true || row.is_public_snapshot === 1 || row.is_public_snapshot === '1',
          statusLabel: row.status === 'WAIT_ACCEPT' ? '待接受派单' : (STATUS[target.status || row.status] || '状态待更新'),
          workOrderNo: workOrder.work_order_no || '-',
          productName: (workOrder.output_item && (workOrder.output_item.item_name || workOrder.output_item.name)) || '-',
          unitLabel: target.production_unit_no || `${(row.target_details || []).length} 个执行目标`,
        });
      });
      const combined = append ? this.data.rows.concat(rows) : rows;
      this.setData({ rows: combined.filter((row, index) => combined.findIndex((item) => item.id === row.id) === index), page, total: Number(response.total || 0), loading: false, loadingMore: false, loaded: true });
    }).catch((error) => { if (sequence !== this.requestSequence) return; this.setData({ loading: false, loadingMore: false }); wx.showToast({ title: error.message, icon: 'none' }); });
  },
  onPublicFilter(event) {
    const value = String(event.currentTarget.dataset.value);
    if (!TASK_TYPES.includes(this.data.type) || !['', '1', '0'].includes(value) || value === this.data.publicFilter) return;
    this.setData({ publicFilter: value });
    return this.load();
  },
  openTask(event) { wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` }); },
  openDelivery(event) { wx.navigateTo({ url: `/pages/warehouse/delivery/index?id=${event.currentTarget.dataset.id}&mode=${this.data.type === 'receipts' ? 'receipt' : 'delivery'}` }); },
  claim(event) {
    if (this.data.busy) return;
    const task = this.data.rows.find((row) => row.id === Number(event.currentTarget.dataset.id));
    this.setData({ busy: true });
    production.claimTask(task.id, task.business_version).then(() => { wx.showToast({ title: '接单成功', icon: 'success' }); return this.load(); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
  acceptHandover(event) {
    if (this.data.busy) return;
    const row = this.data.rows.find((item) => item.id === Number(event.currentTarget.dataset.id));
    this.setData({ busy: true });
    production.acceptHandover(row.id, { expected_version: row.business_version, completeness: { complete: true } })
      .then(() => { wx.showToast({ title: '交接接收成功', icon: 'success' }); return this.load(); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' })).finally(() => this.setData({ busy: false }));
  },
  rejectHandover(event) {
    const row = this.data.rows.find((item) => item.id === Number(event.currentTarget.dataset.id));
    wx.showModal({ title: '拒收工序交接', editable: true, placeholderText: '必须填写拒收原因', success: (result) => {
      if (!result.confirm || !String(result.content || '').trim()) return;
      production.rejectHandover(row.id, { expected_version: row.business_version, reason: result.content.trim() })
        .then(() => { wx.showToast({ title: '已拒收并退回返工', icon: 'none' }); this.load(); })
        .catch((error) => wx.showToast({ title: error.message, icon: 'none' }));
    } });
  },
  runAction(factory, message) {
    if (this.data.busy) return;
    this.setData({ busy: true });
    factory().then(() => { wx.showToast({ title: message, icon: 'success' }); return this.load(); })
      .catch(error => wx.showToast({ title: error.message || '操作失败', icon: 'none', duration: 2500 }))
      .finally(() => this.setData({ busy: false }));
  },
  row(event) { return this.data.rows.find(item => item.id === Number(event.currentTarget.dataset.id)); },
  openPacking() {
    if (this.data.canPacking) wx.navigateTo({ url: '/pages/production/shipment-packing/index' });
  },
  openAssignmentTask(event) {
    const row = this.row(event);
    if (row) wx.navigateTo({ url: '/pages/production/task-detail/index?id=' + row.task_id });
  },
  acceptAssignment(event) { return this.decideAssignment(this.row(event), true); },
  rejectAssignment(event) {
    const row = this.row(event);
    if (!row || this.data.busy || !(row.allowed_actions || {}).reject) return;
    wx.showModal({ title: '拒绝派单', editable: true, placeholderText: '拒绝原因（可选）',
      success: result => { if (result.confirm) this.decideAssignment(row, false, String(result.content || '').trim()); } });
  },
  decideAssignment(row, accept, reason) {
    if (!row || this.data.busy || !(row.allowed_actions || {})[accept ? 'accept' : 'reject']) return;
    return this.runAction(() => decisionCommand.execute((accept ? 'accept_' : 'reject_') + row.id,
      { expected_version: row.business_version, expected_task_version: row.task_business_version, reason: reason || '' },
      payload => accept ? production.acceptAssignment(row.id, payload) : production.rejectAssignment(row.id, payload)),
      accept ? '已接受派单并接单' : '已拒绝，任务已回池');
  },
  inspectOutput(event) {
    const row = this.row(event); const passed = event.currentTarget.dataset.result === 'passed'; const qty = Number(row.output_base_qty || 0);
    const submit = nextStep => this.runAction(() => production.inspectOutput(row.id, { expected_version: row.business_version, result: passed ? 'passed' : 'failed', qualified_base_qty: passed ? qty : 0, unqualified_base_qty: passed ? 0 : qty, reason: passed ? '' : '现场判定不合格', next_step: nextStep }), passed ? '质检已通过' : '已判定不合格');
    if (passed && row.output_mode_snapshot === 'warehouse_optional') {
      wx.showActionSheet({ itemList: ['直接交接下一工序', '先入库再领用'], success: result => submit(result.tapIndex === 0 ? 'direct_handover' : 'warehouse') });
      return;
    }
    submit(row.output_mode_snapshot === 'flow_only' ? 'direct_handover' : 'warehouse');
  },
  receiveInternal(event) { const row = this.row(event); this.runAction(() => production.receiveInternalIssue(row.id, { expected_version: row.business_version }), '半成品已接收'); },
  decideSupplement(event) {
    const row = this.row(event); const approved = event.currentTarget.dataset.approved === 'true';
    wx.showModal({ title: approved ? '批准补料' : '拒绝补料', editable: true, placeholderText: approved ? '审批说明（可选）' : '请输入拒绝原因', success: result => {
      if (!result.confirm || (!approved && !String(result.content || '').trim())) return;
      this.runAction(() => production.decideSupplement(row.id, { expected_version: row.business_version, approved, reason: String(result.content || '').trim() }), approved ? '补料已批准' : '补料已拒绝');
    } });
  },
  qualityReturn(event) { const row = this.row(event); const passed = event.currentTarget.dataset.passed === 'true'; this.runAction(() => production.qualityMaterialReturn(row.id, { expected_version: row.business_version, passed, reason: passed ? '' : '质量退料检验不合格' }), passed ? '退料质检通过' : '退料已隔离'); },
});
