const cutting = require('../../../services/cutting');

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
  data: { scope: 'mine', group: 'active', keyword: '', tasks: [], loading: false, currentPage: 1, lastPage: 1, total: 0, error: '' },
  onShow() { return this.reload(); },
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
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return cutting.listTasks({ scope: this.data.scope, status_group: this.data.group, keyword: this.data.keyword.trim() || undefined, page, per_page: 20 }).then(response => {
      if (sequence !== this.requestSequence) return;
      const result = pagePayload(response);
      this.setData({ tasks: result.rows.map(presentTask), currentPage: result.currentPage, lastPage: result.lastPage, total: result.total, loading: false });
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
