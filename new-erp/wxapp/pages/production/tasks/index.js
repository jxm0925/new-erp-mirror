const production = require('../../../services/production');

const DISPLAY_STATUS = {
  IN_PROGRESS: { label: '生产中', tone: 'running' },
  WAIT_CONDITION: { label: '待条件', tone: 'waiting' },
  EXCEPTION: { label: '异常', tone: 'exception' },
  COMPLETED: { label: '已完成', tone: 'completed' },
};
const STATUS = { DRAFT: '草稿', WAIT_RELEASE: '待发布', RELEASED: '已发布', IN_PROGRESS: '生产中', COMPLETED: '已完成', CLOSED: '已关闭', CANCELLED: '已取消' };
const blankSummary = () => ({ total: '—', in_progress: '—', wait_condition: '—', exception: '—', completed: '—' });

function numberText(value) {
  if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—';
  return String(Number(Number(value).toFixed(8)));
}

function workOrderView(row) {
  const state = DISPLAY_STATUS[row.display_status] || DISPLAY_STATUS.WAIT_CONDITION;
  const product = row.product || {};
  const source = row.source || {};
  const summary = row.execution_summary || {};
  const quantity = summary.quantity || {};
  const tasks = summary.tasks || {};
  const unit = quantity.unit_name || (row.quantity || {}).unit_name || '';
  const blockers = (((row.release || {}).gate_summary || {}).blockers || []).map(item => item.message);
  if (!blockers.length && row.status === 'WAIT_RELEASE') blockers.push('尚未发布');
  const withUnit = value => numberText(value) + (value === null || value === undefined ? '' : unit);
  return Object.assign({}, row, {
    kind: 'work_order', documentLabel: '工单', number: row.work_order_no,
    statusLabel: STATUS[row.status] || state.label, statusTone: state.tone,
    sourceNo: row.stocking_purpose === 'common_inventory' ? '公共库存备货'
      : (row.stocking_purpose === 'reserved_for_work_order' ? '指定工单备货' : (source.no || source.type_label || '—')),
    customerName: source.customer || '',
    productName: product.item_name || product.name || product.item_code || '—',
    executionLabel: row.execution_mode === 'quantity' ? '按数量生产' : '逐件生产',
    plannedText: withUnit(quantity.planned_qty === undefined ? (row.quantity || {}).target_qty : quantity.planned_qty),
    completedText: withUnit(quantity.completed_qty),
    inProgressText: withUnit(quantity.in_progress_qty),
    exceptionText: withUnit(quantity.exception_qty),
    progressText: numberText(tasks.completed) + ' / ' + numberText(tasks.total),
    deliveryDate: source.required_delivery_date || '',
    plannedDate: (row.plan || {}).planned_date || '',
    remarkText: source.remark || '',
    blockers, compact: row.status === 'DRAFT' || row.status === 'WAIT_RELEASE',
  });
}

Page({
  data: {
    statusBarHeight: 20, navBarHeight: 44, scanRight: 64,
    loading: true, loadingMore: false, loaded: false, canCreate: false,
    source: 'sales', activeStatus: '', keyword: '', page: 0, total: 0, rows: [],
    summary: blankSummary(), error: '', errorTitle: '', errorCode: '',
  },
  onLoad() {
    const system = wx.getSystemInfoSync ? wx.getSystemInfoSync() : {};
    const menu = wx.getMenuButtonBoundingClientRect ? wx.getMenuButtonBoundingClientRect() : null;
    const statusBarHeight = Number(system.statusBarHeight || 20);
    this.setData({
      statusBarHeight,
      navBarHeight: menu ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height) : 44,
      scanRight: menu && system.windowWidth ? Math.max(54, system.windowWidth - menu.left + 12) : 64,
    });
  },
  onShow() {
    this.setData({ canCreate: false });
    this.load();
  },
  onPullDownRefresh() { return this.load().finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() { if (!this.data.loading && !this.data.loadingMore && this.data.rows.length < this.data.total) return this.load(true); },
  onUnload() { clearTimeout(this.searchTimer); this.requestSequence = (this.requestSequence || 0) + 1; },
  load(append = false) {
    if (append && (this.data.loading || this.data.loadingMore)) return Promise.resolve();
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, loaded: false, rows: [], total: 0, errorTitle: '请先登录', error: '请登录统一账号后查看工单', errorCode: 'login' });
      return Promise.resolve();
    }
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    const page = append ? this.data.page + 1 : 1;
    const params = { page, per_page: 10, keyword: this.data.keyword.trim(), display_status: this.data.activeStatus, include_summary: 1 };
    if (this.data.source === 'sales') params.source_type = 'sales_order';
    else params.source_group = this.data.source === 'stock' ? 'stock_prebuild' : 'other';
    this.setData(append ? { loadingMore: true, error: '' } : { loading: true, loadingMore: false, loaded: false, rows: [], page: 0, total: 0, summary: blankSummary(), error: '', errorCode: '' });
    return production.workOrders(params).then(response => {
      if (sequence !== this.requestSequence) return;
      const incoming = (response.data || []).map(workOrderView);
      const existing = append ? this.data.rows : [];
      const keys = new Set(existing.map(item => item.id));
      this.setData({
        rows: existing.concat(incoming.filter(item => !keys.has(item.id))),
        page: Number(response.current_page || page), total: Number(response.total || 0),
        summary: response.summary || blankSummary(), loading: false, loadingMore: false, loaded: true,
      });
    }).catch(error => {
      if (sequence !== this.requestSequence) return;
      this.setData({ loading: false, loadingMore: false,
        error: error.message || '请检查网络后重试', errorCode: error.statusCode === 403 ? 'forbidden' : 'load',
        errorTitle: error.statusCode === 403 ? '无权查看生产工单' : '加载失败' });
    });
  },
  setSource(event) {
    const source = event.currentTarget.dataset.source;
    if (!source || source === this.data.source) return;
    clearTimeout(this.searchTimer);
    this.setData({ source, activeStatus: '', keyword: '' });
    return this.load();
  },
  setStatus(event) {
    const status = event.currentTarget.dataset.status || '';
    if (status === this.data.activeStatus) return;
    clearTimeout(this.searchTimer);
    this.setData({ activeStatus: status });
    return this.load();
  },
  onSearch(event) {
    clearTimeout(this.searchTimer);
    // Invalidate the active page immediately. A stale response must not render
    // during the debounce delay or re-enable paging for the previous keyword.
    this.requestSequence = (this.requestSequence || 0) + 1;
    this.setData({ keyword: event.detail.value || '', loading: true, loadingMore: false, rows: [], total: 0 });
    this.searchTimer = setTimeout(() => this.load(), 350);
  },
  clearSearch() { clearTimeout(this.searchTimer); this.setData({ keyword: '' }); return this.load(); },
  retry() { return this.load(this.data.rows.length > 0); },
  goBack() {
    if (getCurrentPages().length > 1) wx.navigateBack({ delta: 1 });
    else wx.reLaunch({ url: '/pages/index/index' });
  },

  scan() { wx.scanCode({ success: result => wx.navigateTo({ url: '/pages/production/queue/index?type=trace&keyword=' + encodeURIComponent(result.result) }) }); },
  openRow(event) {
    const id = Number(event.currentTarget.dataset.id || String(event.currentTarget.dataset.key || '').split(':').pop());
    const row = this.data.rows.find(item => Number(item.id) === id);
    if (row) wx.navigateTo({ url: '/pages/production/work-order-detail/index?id=' + row.id });
  },
});
