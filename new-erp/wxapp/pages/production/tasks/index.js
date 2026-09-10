const production = require('../../../services/production');

const MASTER_STATUS = {
  IN_PROGRESS: { label: '生产中', tone: 'running' },
  WAIT_CONDITION: { label: '待条件', tone: 'waiting' },
  EXCEPTION: { label: '异常', tone: 'exception' },
  COMPLETED: { label: '已完成', tone: 'completed' },
};

function numberText(value) {
  if (value === null || value === undefined || value === '') return '—';
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return '—';
  const parts = numeric.toFixed(4).replace(/\.?0+$/, '').split('.');
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return parts.join('.');
}

function kittingText(kitting) {
  const current = Number(kitting.confirmed_target_count || 0);
  const total = Number(kitting.required_target_count || 0);
  if (kitting.status === 'NOT_REQUIRED') return '无需齐套';
  if (kitting.status === 'READY') return `全部齐套 ${current} / ${total}`;
  if (kitting.status === 'PARTIAL') return `部分齐套 ${current} / ${total}`;
  return `待齐套 ${current} / ${total}`;
}

function productText(product) {
  const name = product.item_name || product.item_code || '未命名产品';
  const spec = product.spec ? ` ${product.spec}` : '';
  return `${name}${spec} × ${numberText(product.planned_qty)}${product.unit_name ? ` ${product.unit_name}` : ''}`;
}

function deliveryView(delivery) {
  const current = Number(delivery.received_line_count || 0);
  const total = Number(delivery.total_line_count || 0);
  const suffix = total > 0 ? ` ${current} / ${total}` : '';
  if (delivery.status === 'RECEIVED') return { text: `全部到位${suffix}`, tone: 'success' };
  if (delivery.status === 'PARTIALLY_RECEIVED') return { text: `部分到位${suffix}`, tone: 'warning' };
  if (delivery.status === 'IN_TRANSIT') return { text: `配送中${suffix}`, tone: 'warning' };
  if (delivery.status === 'WAIT_PREPARE') return { text: `待备料${suffix}`, tone: 'muted' };
  return { text: '备料单待生成', tone: 'muted' };
}

function fundingText(row) {
  const productionText = row.funding_status === 'passed' ? '生产已满足' : '生产资金待满足';
  const shipmentText = row.shipment_status === 'passed' ? '发货已满足' : '发货待付清';
  return `${productionText} · ${shipmentText}`;
}

function shortBlocker(blocker) {
  const count = Number(blocker.count || 1);
  if (blocker.type === 'production_funding') return '生产资金待满足';
  if (String(blocker.reason_code || '').includes('routing')) return `缺少工艺路线 ${count} 项`;
  if (String(blocker.reason_code || '').includes('bom')) return `BOM 条件未满足 ${count} 项`;
  if (String(blocker.reason_code || '').includes('material_supply')) return `物料供应规则缺失 ${count} 项`;
  return blocker.label || `发布条件未满足 ${count} 项`;
}

function masterView(row) {
  const status = MASTER_STATUS[row.display_status] || MASTER_STATUS.WAIT_CONDITION;
  const quantity = row.quantity_summary || {};
  const taskProgress = row.production_task_progress || {};
  const progressPct = Math.max(0, Math.min(100, Math.round(Number(taskProgress.ratio || 0) * 100)));
  const delivery = deliveryView(row.delivery || {});
  const customer = row.customer_snapshot || {};
  const blockers = (row.blockers || []).map(item => Object.assign({}, item, { shortLabel: shortBlocker(item) }));
  const comparable = quantity.comparable !== false;
  return Object.assign({}, row, {
    kind: 'master',
    documentLabel: '主生产单',
    number: row.master_order_no,
    statusLabel: status.label,
    statusTone: status.tone,
    sourceNo: row.sales_order_no_snapshot || '—',
    customerName: customer.customer_name || '—',
    salespersonName: (row.salesperson || {}).display_name || row.salesperson_name_snapshot || '—',
    productLines: (row.product_summary || []).map(product => Object.assign({}, product, { displayText: productText(product) })),
    workOrderCountText: `${Number(row.work_order_count || 0)} 张生产工单`,
    plannedText: comparable ? numberText(quantity.planned_qty) : '多单位',
    completedText: comparable ? numberText(quantity.completed_qty) : '分项',
    inProgressText: comparable ? numberText(quantity.in_progress_qty) : '分项',
    exceptionText: comparable ? numberText(quantity.exception_qty) : '分项',
    progressPct,
    progressText: `${Number(taskProgress.completed || 0)} / ${Number(taskProgress.total || 0)} · ${progressPct}%`,
    deliveryText: delivery.text,
    deliveryTone: delivery.tone,
    kittingText: (row.kitting || {}).progress_label || kittingText(row.kitting || {}),
    fundingText: fundingText(row),
    remarkText: row.order_remark_snapshot || '—',
    blockers,
    compact: blockers.length > 0,
    actionText: blockers.length > 0 ? '查看阻断' : '查看详情',
  });
}

function independentView(row) {
  const mappedStatus = row.status === 'IN_PROGRESS' ? 'IN_PROGRESS'
    : (['COMPLETED', 'CLOSED'].includes(row.status) ? 'COMPLETED'
      : (row.status === 'CANCELLED' ? 'EXCEPTION' : 'WAIT_CONDITION'));
  const status = MASTER_STATUS[mappedStatus];
  const product = row.product || {};
  const quantity = row.quantity || {};
  const gateBlockers = (((row.release || {}).gate_summary || {}).blockers || []).map(item => ({
    type: 'release_gate', reason_code: item.reason_code, label: item.message, count: 1, shortLabel: shortBlocker(item),
  }));
  return Object.assign({}, row, {
    kind: 'work_order',
    documentLabel: '生产工单',
    number: row.work_order_no,
    statusLabel: status.label,
    statusTone: status.tone,
    sourceNo: (row.source && (row.source.no || row.source.type_label)) || '—',
    customerName: '',
    salespersonName: '',
    productLines: [{ displayText: `${product.item_name || product.item_code || '未命名产品'} × ${numberText(quantity.target_qty)}${quantity.unit_name ? ` ${quantity.unit_name}` : ''}` }],
    workOrderCountText: '1 张生产工单',
    plannedText: numberText(quantity.target_base_qty),
    completedText: '—',
    inProgressText: '—',
    exceptionText: '—',
    progressPct: 0,
    progressText: '执行进度进入工单查看',
    deliveryText: '按生产工单执行',
    deliveryTone: 'muted',
    fundingText: '独立生产不适用销售资金 Gate',
    remarkText: (row.source && row.source.title) || '—',
    blockers: gateBlockers,
    compact: gateBlockers.length > 0,
    actionText: gateBlockers.length > 0 ? '查看阻断' : '查看详情',
  });
}

Page({
  data: {
    statusBarHeight: 20,
    navBarHeight: 44,
    scanRight: 64,
    loading: true,
    loadingMore: false,
    loaded: false,
    source: 'sales',
    activeStatus: '',
    keyword: '',
    page: 0,
    total: 0,
    rows: [],
    summary: { total: '—', in_progress: '—', wait_condition: '—', exception: '—', completed: '—' },
  },

  onLoad() {
    const system = wx.getSystemInfoSync ? wx.getSystemInfoSync() : {};
    const menu = wx.getMenuButtonBoundingClientRect ? wx.getMenuButtonBoundingClientRect() : null;
    const statusBarHeight = Number(system.statusBarHeight || 20);
    const navBarHeight = menu ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height) : 44;
    const scanRight = menu && system.windowWidth ? Math.max(54, system.windowWidth - menu.left + 12) : 64;
    this.setData({ statusBarHeight, navBarHeight, scanRight });
  },
  onShow() {
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onReachBottom() {
    if (!this.data.loading && !this.data.loadingMore && this.data.rows.length < this.data.total) this.load(true);
  },
  onUnload() {
    clearTimeout(this.searchTimer);
    this.requestSequence = (this.requestSequence || 0) + 1;
  },

  load(append = false) {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, loaded: false, rows: [], total: 0 });
      wx.showToast({ title: '请先登录统一账号', icon: 'none' });
      return Promise.resolve();
    }
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    const page = append ? this.data.page + 1 : 1;
    const params = {
      page,
      per_page: 10,
      keyword: this.data.keyword.trim(),
      status: this.data.activeStatus,
    };
    let request;
    if (this.data.source === 'sales') {
      request = production.masterOrders(params);
    } else {
      const sourceGroup = this.data.source === 'stock' ? 'stock_prebuild' : 'other';
      request = production.workOrders(Object.assign({}, params, {
        source_group: sourceGroup,
        include_summary: 1,
        status: '',
        display_status: this.data.activeStatus,
      }));
    }
    this.setData(append ? { loadingMore: true } : { loading: true, loaded: false, rows: [], page: 0, total: 0 });
    return request.then((response) => {
      if (sequence !== this.requestSequence) return;
      let incoming = (response.data || []).map(this.data.source === 'sales' ? masterView : independentView);
      const existing = append ? this.data.rows : [];
      const keys = new Set(existing.map(item => `${item.kind}:${item.id}`));
      const rows = existing.concat(incoming.filter(item => !keys.has(`${item.kind}:${item.id}`)));
      this.setData({
        rows,
        page: Number(response.current_page || page),
        total: Number(response.total || 0),
        summary: response.summary || this.data.summary,
        loading: false,
        loadingMore: false,
        loaded: true,
      });
    }).catch((error) => {
      if (sequence !== this.requestSequence) return;
      this.setData({ loading: false, loadingMore: false, loaded: append && this.data.loaded });
      wx.showToast({ title: error.message || '主生产工单加载失败', icon: 'none', duration: 2600 });
    });
  },

  setSource(event) {
    const source = event.currentTarget.dataset.source;
    if (!source || source === this.data.source) return;
    this.setData({ source, activeStatus: '', keyword: '', summary: { total: '—', in_progress: '—', wait_condition: '—', exception: '—', completed: '—' } });
    this.load();
  },
  setStatus(event) {
    const status = event.currentTarget.dataset.status || '';
    if (status === this.data.activeStatus) return;
    this.setData({ activeStatus: status });
    this.load();
  },
  onSearch(event) {
    this.setData({ keyword: event.detail.value || '' });
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.load(), 350);
  },
  clearSearch() { this.setData({ keyword: '' }); this.load(); },
  goBack() { wx.navigateBack({ delta: 1 }); },
  scan() {
    wx.scanCode({ success: result => wx.navigateTo({ url: `/pages/production/queue/index?type=trace&keyword=${encodeURIComponent(result.result)}` }) });
  },
  openRow(event) {
    const key = String(event.currentTarget.dataset.key || '');
    const row = this.data.rows.find(item => `${item.kind}:${item.id}` === key);
    if (!row) return;
    if (row.kind === 'master') {
      wx.navigateTo({ url: `/pages/production/master-detail/index?id=${row.id}` });
      return;
    }
    const blockerText = (row.blockers || []).map(item => `• ${item.label || item.shortLabel}`).join('\n');
    const detailText = row.kind === 'master'
      ? `${row.workOrderCountText}\nPT 进度 ${row.progressText}\n备料配送 ${row.deliveryText}\n齐套状态 ${row.kittingText}\n${row.fundingText}`
      : `${row.sourceNo}\n${row.productLines.map(item => item.displayText).join('\n')}\n状态：${row.statusLabel}`;
    wx.showModal({
      title: row.blockers.length ? '当前阻断' : row.number,
      content: row.blockers.length ? blockerText : detailText,
      showCancel: false,
      confirmText: '知道了',
    });
  },
});
