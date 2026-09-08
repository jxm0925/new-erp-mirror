const production = require('../../../services/production');

const TITLES = { pool: '待接任务', collaboration: '我的协同', deliveries: '物料配送', receipts: '物料签收', handover: '待交接', kitting: '待齐套', trace: '扫码追溯', picking: '拣货执行', outputs_quality: '产出质检', outputs_warehouse: '产出入库', internal_dispatch: '半成品发料', internal_receive: '半成品接收', supplements: '补料审批', return_receive: '退料收货', return_quality: '退料质检' };
const STATUS = { WAIT_PREVIOUS: '待前工序', WAIT_CLAIM: '待接单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待处理', WAIT_PICK: '待拣货', PICKING: '拣货中', IN_PROGRESS: '加工中', PAUSED: '已暂停', WAIT_ISSUE: '待发料', ISSUED: '待接收', SUBMITTED: '待处理', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工中', COMPLETED: '已完成', CANCELLED: '已取消', DELIVERED: '待收料' };
const EXECUTION_TYPES = ['picking', 'outputs_quality', 'outputs_warehouse', 'internal_dispatch', 'internal_receive', 'supplements', 'return_receive', 'return_quality'];

Page({
  data: { type: '', title: '生产待办', loading: true, loadingMore: false, loaded: false, page: 0, total: 0, busy: false, rows: [], keyword: '', trace: null },
  onLoad(options) {
    const type = options.type || 'pool';
    this.setData({ type, title: TITLES[type] || '生产待办', keyword: decodeURIComponent(options.keyword || '') });
    wx.setNavigationBarTitle({ title: this.data.title });
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
    const params = { page, per_page: 20 };
    this.setData(append ? { loadingMore: true } : { loading: true, loaded: false, rows: [], page: 0, total: 0 });
    let promise;
    if (this.data.type === 'pool') promise = production.taskPool(params);
    else if (this.data.type === 'collaboration') promise = production.collaborations(params);
    else if (this.data.type === 'kitting') promise = production.myTasks(Object.assign({}, params, { execution_filter: 'kitting' }));
    else if (this.data.type === 'deliveries') promise = production.deliveries(Object.assign({}, params, { status_group: 'pending_dispatch' }));
    else if (this.data.type === 'receipts') promise = production.deliveries(Object.assign({}, params, { status: 'DELIVERED' }));
    else if (this.data.type === 'handover') promise = production.pendingHandovers(params);
    else if (this.data.type === 'picking') promise = production.pickingTasks(Object.assign({}, params, { status_group: 'active' }));
    else if (this.data.type === 'outputs_quality') promise = production.outputs(Object.assign({}, params, { status: 'WAIT_QUALITY' }));
    else if (this.data.type === 'outputs_warehouse') promise = production.outputs(Object.assign({}, params, { status: 'WAIT_WAREHOUSE' }));
    else if (this.data.type === 'internal_dispatch') promise = production.internalIssues(Object.assign({}, params, { status: 'WAIT_ISSUE' }));
    else if (this.data.type === 'internal_receive') promise = production.internalIssues(Object.assign({}, params, { status: 'ISSUED' }));
    else if (this.data.type === 'supplements') promise = production.supplements(Object.assign({}, params, { status: 'SUBMITTED' }));
    else if (this.data.type === 'return_receive') promise = production.materialReturns(Object.assign({}, params, { status: 'SUBMITTED' }));
    else if (this.data.type === 'return_quality') promise = production.materialReturns(Object.assign({}, params, { status: 'WAIT_QUALITY' }));
    else promise = production.trace(this.data.keyword);

    return promise.then((response) => {
      if (sequence !== this.requestSequence) return;
      if (this.data.type === 'trace') { this.setData({ trace: response.data || response, loading: false }); return; }
      let rows = response.data || [];
      rows = rows.map((row) => {
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
          statusLabel: STATUS[target.status || row.status] || '状态待更新',
          workOrderNo: workOrder.work_order_no || '-',
          productName: (workOrder.output_item && (workOrder.output_item.item_name || workOrder.output_item.name)) || '-',
          unitLabel: target.production_unit_no || `${(row.target_details || []).length} 个执行目标`,
        });
      });
      const combined = append ? this.data.rows.concat(rows) : rows;
      this.setData({ rows: combined.filter((row, index) => combined.findIndex((item) => item.id === row.id) === index), page, total: Number(response.total || 0), loading: false, loadingMore: false, loaded: true });
    }).catch((error) => { if (sequence !== this.requestSequence) return; this.setData({ loading: false, loadingMore: false }); wx.showToast({ title: error.message, icon: 'none' }); });
  },
  openTask(event) { wx.navigateTo({ url: `/pages/production/task-detail/index?id=${event.currentTarget.dataset.id}` }); },
  openDelivery(event) { wx.navigateTo({ url: `/pages/production/delivery-detail/index?id=${event.currentTarget.dataset.id}&mode=${this.data.type === 'receipts' ? 'receipt' : 'delivery'}` }); },
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
  inspectOutput(event) {
    const row = this.row(event); const passed = event.currentTarget.dataset.result === 'passed'; const qty = Number(row.output_base_qty || 0);
    const submit = nextStep => this.runAction(() => production.inspectOutput(row.id, { expected_version: row.business_version, result: passed ? 'passed' : 'failed', qualified_base_qty: passed ? qty : 0, unqualified_base_qty: passed ? 0 : qty, reason: passed ? '' : '现场判定不合格', next_step: nextStep }), passed ? '质检已通过' : '已判定不合格');
    if (passed && row.output_mode_snapshot === 'warehouse_optional') {
      wx.showActionSheet({ itemList: ['直接交接下一工序', '先入库再领用'], success: result => submit(result.tapIndex === 0 ? 'direct_handover' : 'warehouse') });
      return;
    }
    submit(row.output_mode_snapshot === 'flow_only' ? 'direct_handover' : 'warehouse');
  },
  warehouseOutput(event) {
    const row = this.row(event);
    production.warehouses({ status: 'enabled', per_page: 100 }).then(response => {
      const warehouses = response.data || [];
      if (!warehouses.length) throw new Error('没有可用仓库');
      wx.showActionSheet({ itemList: warehouses.map(item => `${item.warehouse_code} ${item.warehouse_name}`), success: result => {
        const warehouse = warehouses[result.tapIndex]; const locations = warehouse.locations || [];
        if (!locations.length) return wx.showToast({ title: '该仓库没有可用库位', icon: 'none' });
        wx.showActionSheet({ itemList: locations.map(item => `${item.location_code} ${item.location_name}`), success: locationResult => {
          const location = locations[locationResult.tapIndex];
          wx.showModal({ title: '生产入库批次', editable: true, placeholderText: '请输入真实批次号', success: modal => {
            if (!modal.confirm || !String(modal.content || '').trim()) return;
            this.runAction(() => production.warehouseOutput(row.id, { expected_version: row.business_version, warehouse_id: warehouse.id, location_id: location.id, batch_no: modal.content.trim() }), '产出已入库');
          } });
        } });
      } });
    }).catch(error => wx.showToast({ title: error.message || '仓库加载失败', icon: 'none' }));
  },
  dispatchInternal(event) { const row = this.row(event); this.runAction(() => production.dispatchInternalIssue(row.id, { expected_version: row.business_version }), '半成品已发出'); },
  receiveInternal(event) { const row = this.row(event); this.runAction(() => production.receiveInternalIssue(row.id, { expected_version: row.business_version }), '半成品已接收'); },
  decideSupplement(event) {
    const row = this.row(event); const approved = event.currentTarget.dataset.approved === 'true';
    wx.showModal({ title: approved ? '批准补料' : '拒绝补料', editable: true, placeholderText: approved ? '审批说明（可选）' : '请输入拒绝原因', success: result => {
      if (!result.confirm || (!approved && !String(result.content || '').trim())) return;
      this.runAction(() => production.decideSupplement(row.id, { expected_version: row.business_version, approved, reason: String(result.content || '').trim() }), approved ? '补料已批准' : '补料已拒绝');
    } });
  },
  receiveReturn(event) { const row = this.row(event); this.runAction(() => production.receiveMaterialReturn(row.id, { expected_version: row.business_version }), '退料已收货'); },
  qualityReturn(event) { const row = this.row(event); const passed = event.currentTarget.dataset.passed === 'true'; this.runAction(() => production.qualityMaterialReturn(row.id, { expected_version: row.business_version, passed, reason: passed ? '' : '质量退料检验不合格' }), passed ? '退料质检通过' : '退料已隔离'); },
  assignPicking(event) { const row = this.row(event); const userId = Number((wx.getStorageSync('erp_user') || {}).legacy_id || 0); if (!userId) return wx.showToast({ title: '当前账号缺少人员标识', icon: 'none' }); this.runAction(() => production.assignPickingTask(row.id, { expected_version: row.business_version, assigned_picker_legacy_id: userId }), '已分配给我'); },
  startPicking(event) { const row = this.row(event); this.runAction(() => production.startPickingTask(row.id, { expected_version: row.business_version }), '已开始拣货'); },
  confirmPicking(event) {
    const row = this.row(event);
    production.pickingTask(row.id).then(response => {
      const detail = response.data || {}; const lines = (detail.lines || []).map(line => ({ picking_task_line_id: line.id, actual_pick_qty: Number(line.planned_pick_qty), serial_ids: ((line.serial_snapshot || {}).inventory_serial_ids || []) }));
      wx.showModal({ title: '确认全部实拣', content: `将按 ${lines.length} 条计划数量确认并正式扣减库存。`, success: result => { if (result.confirm) this.runAction(() => production.confirmPickingTask(row.id, { expected_version: detail.business_version, lines }), '拣货已过账'); } });
    }).catch(error => wx.showToast({ title: error.message || '明细加载失败', icon: 'none' }));
  },
});
