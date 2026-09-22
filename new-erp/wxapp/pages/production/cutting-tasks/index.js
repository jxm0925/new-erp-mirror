const cutting = require('../../../services/cutting');
const { pendingCommands } = require('../../../utils/cutting-command');
function can(code) { const permissions = wx.getStorageSync('erp_permissions') || []; return Array.isArray(permissions) ? permissions.includes(code) : permissions[code] === true; }

function pagePayload(response) {
  const payload = response || {}; const meta = payload.meta || {};
  return { rows: Array.isArray(payload.data) ? payload.data : [], currentPage: Number(meta.current_page || 1), lastPage: Number(meta.last_page || 1), total: Number(meta.total || 0) };
}
function presentTask(item) {
  return Object.assign({}, item, {
    display_status: item.display_status || '-',
    owner_label: item.owner_name || (item.assignee_user_legacy_id ? `执行人 #${item.assignee_user_legacy_id}` : '暂未接手'),
    output_summary: item.output_summary || '待加工产出',
  });
}
Page({
  data: { scope: 'mine', group: 'active', keyword: '', tasks: [], loading: false, currentPage: 1, lastPage: 1, total: 0, error: '', canReceive: false, canReject: false, canViewHandovers: false, canViewCutting: false, canCreate: false, showDecision: false, decision: {}, busy: false, pendingDecisions: [] },
  onShow() {
    const view = can('production.cutting.view'); const handover = can('production.cutting.handover.view');
    this.setData({ canReceive: can('production.cutting.handover.receive'), canReject: can('production.cutting.handover.reject'), canViewHandovers: handover, canViewCutting: view, canCreate: can('production.cutting.record'), group: !view && handover ? 'handovers' : this.data.group });
    return this.reload();
  },
  noop() {},
  resumeDecision(event) {
    if (this.data.busy) return;
    const pending = this.data.pendingDecisions[Number(event.currentTarget.dataset.index)];
    if (!pending) return;
    this.setData({ showDecision: true, decision: Object.assign({ id: pending.id, accept: pending.accept, handover_no: `交接 #${pending.id}`, reason: '' }, pending.payload, { locked: true }) });
  },
  openDecision(event) {
    if (this.data.busy) return;
    const row = this.data.tasks.find(item => Number(item.id) === Number(event.currentTarget.dataset.id));
    const accept = event.currentTarget.dataset.action === 'accept';
    if (!row || (accept ? !this.data.canReceive : !this.data.canReject)) return;
    const unresolved = pendingCommands(`production/cutting/handovers/${row.id}/`)[0];
    if (unresolved) {
      // Resolve the uncertain decision before allowing an opposite decision on the same handover.
      this.setData({ showDecision: true, decision: Object.assign({ id: row.id, accept: unresolved.path.endsWith('/accept'), handover_no: row.handover_no, reason: '' }, unresolved.payload, { locked: true }) });
      return;
    }
    const actor = wx.getStorageSync('erp_user') || {};
    const pending = wx.getStorageSync(`cutting-pending:${actor.legacy_id || actor.id || 'session'}:POST:production/cutting/handovers/${row.id}/${accept ? 'accept' : 'reject'}`);
    this.setData({ showDecision: true, decision: Object.assign({ id: row.id, accept, handover_no: row.handover_no, quantity: row.remaining_qty, max: Number(row.remaining_qty), reason: '', expected_version: row.business_version }, pending && pending.payload || {}, { locked: !!(pending && pending.payload) }) });
  },
  closeDecision() { if (!this.data.busy) this.setData({ showDecision: false }); },
  onDecisionField(event) { if (!this.data.busy && !this.data.decision.locked) this.setData({ [`decision.${event.currentTarget.dataset.field}`]: event.detail.value }); },
  submitDecision() {
    if (this.data.busy) return;
    const row = this.data.decision;
    if (!/^\d+(\.\d{1,8})?$/.test(String(row.quantity)) || Number(row.quantity) <= 0 || (!row.locked && Number(row.quantity) > row.max)) return wx.showToast({ title: '请填写有效的本次数量', icon: 'none' });
    if (!row.accept && !String(row.reason).trim()) return wx.showToast({ title: '请填写拒收原因', icon: 'none' });
    const payload = { expected_version: row.expected_version, quantity: String(row.quantity) };
    if (!row.accept) payload.reason = String(row.reason).trim();
    this.setData({ busy: true });
    return (row.accept ? cutting.acceptHandover(row.id, payload) : cutting.rejectHandover(row.id, payload)).then(() => {
      this.setData({ showDecision: false }); wx.showToast({ title: row.accept ? '已接收' : '已拒收退回', icon: 'success' }); return this.reload();
    }).catch(error => {
      const uncertain = !error.statusCode || error.statusCode >= 500 || [401, 403, 408, 429].includes(error.statusCode) || error.errorCode === 'command_processing';
      this.setData({ 'decision.locked': uncertain }); wx.showToast({ title: error.message || '处理失败，请重试', icon: 'none' });
      if (error.statusCode === 409 && !uncertain) { this.setData({ showDecision: false }); return this.reload(); }
    }).finally(() => this.setData({ busy: false }));
  },
  onPullDownRefresh() { return this.reload().finally(() => wx.stopPullDownRefresh()); },
  switchGroup(e) { const group = e.currentTarget.dataset.group; if (group && group !== this.data.group) { this.setData({ group }); return this.reload(); } },
  onKeyword(e) { this.setData({ keyword: e.detail.value }); },
  clearKeyword() {
    this.setData({ keyword: '' });
    return this.reload();
  },
  goCreate() { wx.navigateTo({ url: '/pages/production/cutting-create/index' }); },
  reload() { return this.fetchPage(1); },
  prevPage() { if (this.data.currentPage > 1) return this.fetchPage(this.data.currentPage - 1); },
  nextPage() { if (this.data.currentPage < this.data.lastPage) return this.fetchPage(this.data.currentPage + 1); },
  fetchPage(page) {
    this.setData({ pendingDecisions: pendingCommands('production/cutting/handovers/').map(row => {
      const match = row.path.match(/handovers\/(\d+)\/(accept|reject)$/);
      return match ? Object.assign({}, row, { id: Number(match[1]), accept: match[2] === 'accept' }) : null;
    }).filter(Boolean) });
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    const handovers = this.data.group === 'handovers';
    const query = { scope: this.data.scope, status_group: handovers ? undefined : this.data.group, keyword: this.data.keyword.trim() || undefined, page, per_page: 20 };
    return (handovers ? cutting.pendingHandovers(query) : cutting.listTasks(query)).then(response => {
      if (sequence !== this.requestSequence) return;
      const result = pagePayload(response);
      this.setData({ tasks: handovers ? result.rows.map(row => Object.assign({}, row, { remaining_qty: String(Number(row.dispatched_qty) - Number(row.accepted_qty) - Number(row.rejected_qty)) })) : result.rows.map(presentTask), currentPage: result.currentPage, lastPage: result.lastPage, total: result.total, loading: false });
    }).catch(error => {
      if (sequence !== this.requestSequence) return;
      this.setData({ loading: false, tasks: [], error: error.message || '下料记录加载失败' });
    });
  },
  openTask(e) {
    const taskId = Number(e.currentTarget.dataset.taskId || 0); const orderId = Number(e.currentTarget.dataset.orderId || 0);
    if (taskId && orderId) wx.navigateTo({ url: `/pages/production/cutting-order-detail/index?taskId=${taskId}&orderId=${orderId}` });
  },
});
module.exports = { pagePayload };
