const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const TABS = [{ key: 'mine', label: '我的' }, { key: 'available', label: '可接' }, { key: 'done', label: '做完的' }];
const REVIEW_TAB = { key: 'review', label: '待审核' };

function tabsFor(counts, tab) {
  return (counts && counts.review > 0) || tab === 'review' ? TABS.concat([REVIEW_TAB]) : TABS;
}

function decorate(row, tab, canExecute) {
  const planned = Number(row.planned_qty || 0);
  const done = Number(row.done_qty || 0);
  const canClaim = Boolean(canExecute && row.allowed && row.allowed.claim);
  return Object.assign({}, row, {
    key: row.key || `${row.id}-${row.lane || 'normal'}`, lane: row.lane || 'normal', title: row.title || row.operation_name, unitShort: row.unit_short || '台',
    plannedText: view.quantity(row.planned_qty), doneText: view.quantity(row.done_qty),
    percent: planned > 0 ? Math.min(100, Math.round(done / planned * 100)) : 0,
    dueText: String(row.planned_finish_date || '').slice(5, 10),
    canClaim,
    buttonText: !canExecute || tab === 'done' || (tab === 'available' && !canClaim) ? '查看' : (tab === 'available' ? (row.lane === 'rework' ? '接返工' : '接单') : (row.timing ? '继续报工' : (row.here_qty ? (row.lane === 'rework' ? '开始返工' : '开始报工') : '查看'))),
  });
}

function decorateReview(row, kind) {
  if (kind === 'scrap') {
    return Object.assign({}, row, { key: `s${row.id}`, kind, title: `${row.operation_name || ''}申请报废`,
      line: `${row.serial_no || ''} · ${row.work_order_no || ''}`, reasonText: [(row.reason_tags || []).join('、'), row.reason].filter(Boolean).join('：'),
      who: row.reporter_name, photos: row.photos || [] });
  }
  const plan = row.plan || {};
  const lines = (row.lines || []).map(l => `${l.component_item_name}${l.serial_no ? ' ' + l.serial_no : ' ' + view.quantity(l.qty) + (l.unit_name || '')}`).join('、');
  return Object.assign({}, row, { key: `p${row.id}`, kind, title: `不良退回 · ${row.plan_label || ''}${plan.action === 'rework' && plan.start_operation_name ? '（从' + plan.start_operation_name + '开始）' : ''}`,
    line: `${row.return_no || ''} · ${row.work_order_no || ''} · ${row.operation_name || ''}`, reasonText: `${row.summary || lines}；${row.reason || ''}`,
    who: row.requested_by_name, photos: plan.photos || [] });
}

Page({
  data: { tabs: TABS, tab: 'mine', counts: { mine: 0, available: 0 }, rows: [], reviews: [], page: 1, lastPage: 1, total: 0,
    loading: true, busy: false, error: '', keyword: '', userName: '', canExecute: false },
  onLoad(options) {
    const user = wx.getStorageSync('erp_user') || {};
    this.setData({ tab: options && TABS.concat([REVIEW_TAB]).some(t => t.key === options.tab) ? options.tab : 'mine', keyword: options && options.keyword ? decodeURIComponent(options.keyword) : '', userName: user.nickname || user.display_name || user.username || '' });
  },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    if (this.data.tab === 'review') return this.loadReviews(sequence);
    return production.tasks({ tab: this.data.tab, page: this.data.page, per_page: 10, keyword: this.data.keyword }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response);
      const canExecute = Boolean(response.permissions && response.permissions.execute);
      const counts = response.counts || this.data.counts;
      this.setData({ rows: paged.rows.map(row => decorate(row, this.data.tab, canExecute)), page: paged.page, lastPage: paged.lastPage, total: paged.total, canExecute,
        counts, tabs: tabsFor(counts, this.data.tab), loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  loadReviews(sequence) {
    return production.reviews().then(response => {
      if (sequence !== this.sequence) return;
      const body = response.data || response || {};
      const reviews = (body.scraps || []).map(r => decorateReview(r, 'scrap')).concat((body.plans || []).map(r => decorateReview(r, 'plan')));
      const counts = Object.assign({}, this.data.counts, { review: reviews.length });
      this.setData({ reviews, rows: [], counts, tabs: tabsFor(counts, 'review'), page: 1, lastPage: 1, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  review(event) {
    const { key, approve } = event.currentTarget.dataset;
    const row = this.data.reviews.find(item => item.key === key);
    if (!row || this.data.busy) return;
    const yes = approve === 'yes' || approve === true;
    const content = row.kind === 'scrap'
      ? (yes ? `确认 ${row.serial_no || ''} 报废？报废后这件不再生产。` : '驳回后继续在本道返工。')
      : (yes ? `通过后按「${row.plan_label}」处置${row.plan && row.plan.action === 'rework' ? '，自动开返工工单' : ''}。` : '驳回后料还在原工序。');
    wx.showModal({ title: yes ? '审核通过' : '驳回', content, editable: !yes, placeholderText: '驳回原因（选填）', success: res => {
      if (!res.confirm) return;
      const payload = { approve: yes, remark: res.content || '' };
      this.setData({ busy: true });
      (row.kind === 'scrap' ? production.reviewScrap(row.id, payload) : production.reviewPlan(row.id, payload)).then(response => {
        wx.showToast({ title: response.message || '已处理', icon: 'none' });
        this.load();
      }).catch(error => wx.showModal({ title: '没处理成功', content: view.errorText(error), showCancel: false }))
        .finally(() => this.setData({ busy: false }));
    } });
  },
  previewPhoto(event) {
    const { url, urls } = event.currentTarget.dataset;
    wx.previewImage({ current: url, urls: urls || [url] });
  },
  pickTab(event) {
    const tab = event.currentTarget.dataset.tab;
    if (tab === this.data.tab) return;
    this.setData({ tab, page: 1, rows: [], reviews: [] });
    this.load();
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  turnPage(event) {
    const page = this.data.page + Number(event.currentTarget.dataset.delta);
    if (page > 0 && page <= this.data.lastPage) { this.setData({ page }); this.load(); }
  },
  open(event) {
    const { id, lane } = event.currentTarget.dataset;
    wx.navigateTo({ url: `/pages/production/task/index?id=${Number(id)}&lane=${lane || 'normal'}` });
  },
  primary(event) {
    const key = event.currentTarget.dataset.key;
    const row = this.data.rows.find(item => item.key === key);
    if (this.data.tab !== 'available' || !this.data.canExecute || !row || !row.canClaim) return this.open(event);
    if (this.data.busy) return;
    const id = row.id;
    this.setData({ busy: true });
    production.action(id, { action: 'claim', lane: row.lane }).then(response => {
      wx.showToast({ title: response.message || '接单成功', icon: 'success' });
      wx.navigateTo({ url: `/pages/production/task/index?id=${id}&lane=${row.lane}` });
    }).catch(error => {
      wx.showModal({ title: '接单没成功', content: view.errorText(error), showCancel: false });
      this.load();
    }).finally(() => this.setData({ busy: false }));
  },
  openSharedCutting() { wx.navigateTo({ url: '/pages/production/cutting-run/index' }); },
  back() { view.back(); },
});
