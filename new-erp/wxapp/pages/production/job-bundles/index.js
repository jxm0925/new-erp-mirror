const service = require('../../../services/job-bundles');

const statusNames = { DRAFT: '待接单', WAIT_CLAIM: '待接单', CLAIMED: '已接单', READY: '待开工', IN_PROGRESS: '加工中', PAUSED: '已暂停', FINISHED: '已结束', COMPLETED: '已结束', CANCELLED: '已取消' };
function quantity(value) {
  return value === null || value === undefined || value === '' ? '—'
    : String(value).replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
}
function withNames(bundle) {
  return Object.assign({}, bundle, {
    statusName: statusNames[bundle.status] || bundle.status || '—',
    laborText: quantity(bundle.actual_labor_minutes),
    lineCount: bundle.line_count || (bundle.lines || []).length,
    lines: (bundle.lines || []).map(line => Object.assign({}, line, {
      base_unit_name: line.base_unit_name || (line.unit || {}).base_unit_name || (line.item || {}).unit_name || '',
      plannedText: quantity(line.target && line.target.planned_base_qty),
      remainingText: quantity(line.target && line.target.remaining_base_qty),
      allocatedText: quantity(line.allocated_labor_minutes),
      targetStatusName: ({ READY: '待开工', WAIT_MATERIAL: '待齐套', IN_PROGRESS: '加工中', PAUSED: '已暂停', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', COMPLETED: '已完成' })[(line.target || {}).status] || (line.target || {}).status || '—',
    })),
  });
}

Page({
  data: { id: 0, loading: false, busy: false, error: '', rows: [], page: 1, total: 0, keyword: '', view: 'pool', detail: null,
    reportVisible: false, reportLine: null, reportDraft: { qualified_base_qty: '', unqualified_base_qty: '', scrapped_base_qty: '', defect_reason: '', remark: '' }, reportPending: false },
  onLoad(options) { this.setData({ id: Number(options.id || 0) }); },
  onShow() { this.pageActive = true; return this.refresh(); },
  onHide() { this.pageActive = false; this.stopTimer(); this.loadSequence = (this.loadSequence || 0) + 1; },
  onUnload() { this.pageActive = false; this.stopTimer(); this.loadSequence = (this.loadSequence || 0) + 1; },
  onPullDownRefresh() { return this.refresh().finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() { if (!this.data.id && !this.data.loading && this.data.rows.length < this.data.total) { this.setData({ page: this.data.page + 1 }); this.load(true); } },
  refresh() { if (!this.data.id) this.setData({ page: 1 }); return this.load(); },
  async load(append) {
    if (this.pageActive === false) return;
    const sequence = this.loadSequence = (this.loadSequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    try {
      if (this.data.id) {
        const response = await service.detail(this.data.id);
        if (sequence === this.loadSequence) { this.setData({ detail: withNames(response.data || {}) }); this.startTimer(); }
      } else {
        const response = await service.list({ view: this.data.view === 'pool' ? 'all' : 'mine', status: this.data.view === 'pool' ? 'WAIT_CLAIM' : undefined, keyword: this.data.keyword.trim(), page: this.data.page, per_page: 20 });
        if (sequence === this.loadSequence) this.setData({ rows: (append === true ? this.data.rows : []).concat((response.data || []).map(withNames)), total: Number(response.total || 0) });
      }
    } catch (error) { if (sequence === this.loadSequence) this.setData({ error: error.message || '共同加工作业加载失败', page: append === true ? Math.max(1,this.data.page - 1) : this.data.page }); }
    finally { if (sequence === this.loadSequence) this.setData({ loading: false }); }
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  changeView(event) { this.setData({ view: event.currentTarget.dataset.view, page: 1, rows: [] }); return this.load(); },
  stopTimer() { if (this.timer) clearInterval(this.timer); this.timer = null; },
  startTimer() {
    this.stopTimer(); const labor = this.data.detail && this.data.detail.my_labor;
    if (this.pageActive === false || !labor || labor.status !== 'ACTIVE') return;
    let seconds = Number(labor.accumulated_seconds || 0);
    this.timer = setInterval(() => { seconds += 1; this.setData({ 'detail.laborText': (seconds / 60).toFixed(2) }); }, 1000);
  },
  search() { this.setData({ page: 1, rows: [] }); return this.load(); },
  openBundle(event) { const id = Number(event.currentTarget.dataset.id); if (id) wx.navigateTo({ url: `/pages/production/job-bundles/index?id=${id}` }); },
  line(event) { return (this.data.detail.lines || []).find(row => row.id === Number(event.currentTarget.dataset.id)); },
  openTask(event) { const line = this.line(event); if (line && line.task) wx.navigateTo({ url: `/pages/production/task-detail/index?id=${line.task.id}` }); },
  async action(event) {
    const action = event.currentTarget.dataset.action;
    const detail = this.data.detail;
    if (this.data.busy || !detail || !(detail.allowed_actions || {})[action]) return;
    this.setData({ busy: true });
    try { await service.execute(`action-${detail.id}-${action}`, { expected_version: detail.business_version }, data => service.action(detail.id, action, data)); await this.load(); }
    catch (error) { wx.showToast({ title: error.message || '操作失败，请重试原操作', icon: 'none' }); await this.load(); }
    finally { this.setData({ busy: false }); }
  },
  openReport(event) {
    const line = this.line(event);
    if (!line || !(line.allowed_actions || {}).report) return;
    const saved = service.pending(`report-${this.data.id}-${line.id}`);
    const draft = saved ? saved.payload : { qualified_base_qty: '', unqualified_base_qty: '', scrapped_base_qty: '', defect_reason: '', remark: '' };
    this.setData({ reportVisible: true, reportLine: line, reportDraft: Object.assign({}, draft), reportPending: Boolean(saved) });
  },
  closeReport() { if (!this.data.busy) this.setData({ reportVisible: false, reportLine: null }); },
  reportInput(event) { if (!this.data.reportPending) this.setData({ [`reportDraft.${event.currentTarget.dataset.field}`]: event.detail.value }); },
  async submitReport() {
    if (this.data.busy || !this.data.reportLine) return;
    const draft = this.data.reportDraft;
    if (!this.data.reportPending && !['qualified_base_qty', 'unqualified_base_qty', 'scrapped_base_qty'].every(key => /^(0|[1-9]\d{0,19})(\.\d{1,8})?$/.test(String(draft[key])))) {
      return wx.showToast({ title: '请完整填写合格、不合格和报废数量，没有则填0', icon: 'none' });
    }
    const detail = this.data.detail; const line = this.data.reportLine;
    this.setData({ busy: true });
    try {
      await service.execute(`report-${detail.id}-${line.id}`, Object.assign({}, draft, { expected_version: detail.business_version, expected_target_version: line.target.business_version }), data => service.report(detail.id, line.id, data));
      this.setData({ reportVisible: false, reportLine: null }); await this.load();
    } catch (error) { this.setData({ reportPending: Boolean(service.pending(`report-${detail.id}-${line.id}`)) }); wx.showToast({ title: error.message || '报工失败，请重试原报工', icon: 'none' }); }
    finally { this.setData({ busy: false }); }
  },
  async completeLine(event) {
    const detail = this.data.detail; const line = this.line(event);
    if (this.data.busy || !line || !(line.allowed_actions || {}).complete) return;
    this.setData({ busy: true });
    try {
      const key = `complete-${detail.id}-${line.id}`;
      const saved = service.pending(key);
      let disposition = saved && saved.payload.disposition;
      if (!saved && line.target.output_mode === 'warehouse_optional') {
        const choices = line.target.allow_continue_without_warehouse ? ['直接交接下一工序', '先入库再领用'] : ['先入库再领用'];
        const choice = await new Promise(resolve => wx.showActionSheet({ itemList: choices, success: result => resolve(result.tapIndex), fail: () => resolve(null) }));
        if (choice === null) return;
        disposition = choices[choice] === '直接交接下一工序' ? 'direct_handover' : 'warehouse';
      }
      if (!disposition) disposition = line.target.output_mode === 'warehouse_required' ? 'warehouse' : 'direct_handover';
      const accepted = await new Promise(resolve => wx.showModal({ title: saved ? '重试原完工' : '确认完成此项',
        content: `${line.item.name}${line.unit && line.unit.no ? '\n生产单元：'+line.unit.no : ''}\n去向：${disposition === 'warehouse' ? '入库' : '直接交接下一工序'}`, success: result => resolve(result.confirm), fail: () => resolve(false) }));
      if (!accepted) return;
      await service.execute(key, { expected_version: detail.business_version, expected_target_version: line.target.business_version, disposition }, data => service.complete(detail.id, line.id, data)); await this.load();
    }
    catch (error) { wx.showToast({ title: error.message || '完工失败，请核对明细', icon: 'none' }); await this.load(); }
    finally { this.setData({ busy: false }); }
  },
});
