const production = require('../../../services/production');

const STATUS_MAP = {
  IN_PROGRESS: { label: '生产中', color: '#d81e06', bg: '#ffebeb' },
  WAIT_CONDITION: { label: '待条件', color: '#ff976a', bg: '#fff7e6' },
  EXCEPTION: { label: '异常', color: '#ee0a24', bg: '#fff1f0' },
  COMPLETED: { label: '已完成', color: '#00a870', bg: '#e6f8f0' },
  UNKNOWN: { label: '未知', color: '#7a8291', bg: '#f2f3f5' },
};

function num(value, fallback = '—') {
  if (value === null || value === undefined || value === '') return fallback;
  const n = Number(value);
  return Number.isFinite(n) ? String(n).replace(/\.0+$/, '') : fallback;
}

function formatMoney(value) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return '¥' + n.toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function formatDateTime(value) {
  if (!value) return '—';
  return String(value).slice(0, 16).replace('T', ' ');
}

function formatSize(value) {
  const bytes = Number(value);
  if (!Number.isFinite(bytes) || bytes < 0) return '—';
  if (bytes < 1024) return `${bytes}B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)}KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)}MB`;
}

function attachmentView(item) {
  const mime = String(item.mime_type || '').toLowerCase();
  const isImage = mime.startsWith('image/');
  return Object.assign({}, item, {
    name: item.name || '未命名附件',
    type: mime === 'application/pdf' ? 'PDF' : (isImage ? '图片' : '文件'),
    size: formatSize(item.file_size),
    uploader: item.uploader || '—',
    time: formatDateTime(item.uploaded_at),
    icon: mime === 'application/pdf' ? 'pdf' : (isImage ? 'image' : 'file'),
  });
}

function view(master, workOrders) {
  const quantity = master.quantity_summary || {};
  const tasks = master.production_task_progress || {};
  const delivery = master.delivery || {};
  const deliveryOverview = master.delivery_overview || {};
  const kitting = master.kitting || {};
  const funding = master.funding || {};
  const shipment = master.shipment || {};
  const statusInfo = STATUS_MAP[master.display_status] || STATUS_MAP.UNKNOWN;
  const plannedQty = quantity.planned_qty;
  const completedQty = quantity.completed_qty;
  const inProgressQty = quantity.in_progress_qty;
  const exceptionQty = quantity.exception_qty;
  const waitingQty = quantity.waiting_qty !== undefined
    ? quantity.waiting_qty
    : Math.max(0, Number(plannedQty || 0) - Number(completedQty || 0) - Number(inProgressQty || 0) - Number(exceptionQty || 0));
  const taskCompleted = Number(tasks.completed || 0);
  const taskTotal = Number(tasks.total || 0);
  const taskRatio = taskTotal > 0 ? Math.round((taskCompleted / taskTotal) * 100) : 0;
  const productLines = Array.isArray(master.product_summary) ? master.product_summary : [];
  const attachments = (Array.isArray(master.attachments) ? master.attachments : []).map(attachmentView);
  const remarkLogs = (Array.isArray(master.remarks) ? master.remarks : []).map(item => Object.assign({}, item, {
    time: formatDateTime(item.created_at),
    sourceTag: item.source_label || '生产订单',
    author: item.author || '—',
  }));

  return Object.assign({}, master, {
    statusLabel: statusInfo.label,
    statusColor: statusInfo.color,
    statusBg: statusInfo.bg,
    master_order_no: master.master_order_no || '—',
    sales_order_no_snapshot: master.sales_order_no_snapshot || '—',
    customerName: (master.customer_snapshot || {}).customer_name || (master.customer_snapshot || {}).name || '—',
    productName: productLines.length
      ? productLines.map(p => `${p.item_name || '未命名产品'} × ${num(p.planned_qty, '0')}${p.unit_name || ''}`).join('、')
      : '—',
    productLines,
    plannedQty: num(plannedQty),
    plannedUnitName: quantity.unit_name || '',
    completedQty: num(completedQty, '0'),
    inProgressQty: num(inProgressQty, '0'),
    waitingQty: num(waitingQty, '0'),
    exceptionQty: num(exceptionQty, '0'),
    taskCompleted: num(taskCompleted, '0'),
    taskTotal: num(taskTotal, '0'),
    taskRatio,
    deliveryDate: master.required_delivery_date_snapshot || '—',
    deliveryDelivered: num(delivery.received_line_count, '0'),
    deliveryTotal: num(delivery.total_line_count, '0'),
    kittingConfirmed: num(kitting.confirmed_target_count, '0'),
    kittingTotal: num(kitting.required_target_count, '0'),
    productionSatisfied: funding.production_funds_satisfied === true,
    shipmentSatisfied: funding.shipment_funds_satisfied === true,
    contractAmountText: formatMoney(funding.contract_amount),
    receivedAmountText: formatMoney(funding.net_received_amount),
    outstandingAmountText: formatMoney(funding.outstanding_amount),
    shipmentOutstandingText: formatMoney(funding.outstanding_amount),
    shipmentGateDescription: funding.shipment_funds_satisfied === true
      ? '已达到发货门槛'
      : `尚欠 ${formatMoney(funding.outstanding_amount)}`,
    shippedQty: shipment.comparable === false ? '分单位' : num(shipment.shipped_qty, '0'),
    shippedTotal: shipment.comparable === false ? '分单位' : num(shipment.total_qty, '0'),
    shippedRatio: shipment.comparable !== false && Number(shipment.total_qty) > 0
      ? Math.round(Number(shipment.shipped_qty || 0) / Number(shipment.total_qty) * 100) : 0,
    shipmentBlockReason: funding.shipment_block_message || '—',
    orderRemark: master.order_remark_snapshot || '—',
    attachments,
    remarkLogs,
    workOrders: (Array.isArray(workOrders) ? workOrders : []).map(row => {
      const q = row.quantity_summary || {};
      const t = row.production_task_progress || {};
      const total = Number(t.total || 0);
      return Object.assign({}, row, {
        status: row.display_status_label || '待条件',
        productName: (row.output_item || {}).item_name || '—',
        unitName: q.unit_name || '',
        plannedQty: num(q.planned_qty),
        completedQty: num(q.completed_qty, '0'),
        inProgressQty: num(q.in_progress_qty, '0'),
        waitingQty: num(q.waiting_qty, '0'),
        exceptionQty: num(q.exception_qty, '0'),
        taskCompleted: num(t.completed, '0'),
        taskTotal: num(t.total, '0'),
        taskRatio: total > 0 ? Math.round(Number(t.completed || 0) / total * 100) : 0,
      });
    }),
    deliveryTab: {
      totalRequired: num(deliveryOverview.total_required_line_count, '0'),
      prepared: num(deliveryOverview.prepared_line_count, '0'),
      delivered: num(deliveryOverview.delivered_line_count, '0'),
      received: num(deliveryOverview.received_line_count, '0'),
      waitingKittingOps: num(deliveryOverview.waiting_kitting_operation_count, '0'),
      pendingAlerts: Array.isArray(deliveryOverview.pending_alerts) ? deliveryOverview.pending_alerts : [],
      activeDeliveries: Array.isArray(deliveryOverview.active_deliveries) ? deliveryOverview.active_deliveries : [],
      upcomingDeliveries: Array.isArray(deliveryOverview.upcoming_deliveries) ? deliveryOverview.upcoming_deliveries : [],
    },
  });
}

function emptyView() {
  return view({}, []);
}

Page({
  data: {
    id: 0,
    statusBarHeight: 20,
    navBarHeight: 44,
    capsuleWidth: 88,
    loading: true,
    activeTab: 'overview',
    master: emptyView(),
    tabs: [
      { key: 'overview', text: '概览' },
      { key: 'execution', text: '生产执行' },
      { key: 'delivery', text: '备料配送' },
      { key: 'funding', text: '回款发货' },
      { key: 'notes', text: '备注附件' },
    ],
  },
  onLoad(options) {
    const system = wx.getSystemInfoSync ? wx.getSystemInfoSync() : {};
    const menu = wx.getMenuButtonBoundingClientRect ? wx.getMenuButtonBoundingClientRect() : null;
    const statusBarHeight = Number(system.statusBarHeight || 20);
    const navBarHeight = menu ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height) : 44;
    const capsuleWidth = menu && system.windowWidth ? (system.windowWidth - menu.left) : 88;
    const id = Number(options.id || 0);
    this.setData({ id, statusBarHeight, navBarHeight, capsuleWidth });
    if (id > 0) this.load();
    else {
      this.setData({ loading: false });
      wx.showToast({ title: '主生产工单参数无效', icon: 'none' });
    }
  },
  onShow() {
    if (this.data.id && !this.data.loading) this.load();
  },
  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },
  load() {
    if (!this.data.id) return Promise.resolve();
    this.setData({ loading: true });
    return Promise.all([
      production.masterOrder(this.data.id),
      production.masterOrderWorkOrders(this.data.id, { page: 1, per_page: 50 }),
    ]).then(([detail, orders]) => {
      this.setData({ master: view(detail.data || {}, orders.data || []), loading: false });
    }).catch((error) => {
      this.setData({ master: emptyView(), loading: false });
      wx.showToast({ title: error.message || '主生产工单加载失败', icon: 'none' });
    });
  },
  setTab(event) {
    const key = event.currentTarget.dataset.key;
    if (key) this.setData({ activeTab: key });
  },
  back() {
    const pages = getCurrentPages();
    if (pages && pages.length > 1) wx.navigateBack({ delta: 1 });
    else wx.switchTab({ url: '/pages/index/index' });
  },
  openWorkOrder(event) {
    const workOrderId = Number(event.currentTarget.dataset.id || 0);
    if (!workOrderId || !this.data.id) return;
    wx.navigateTo({
      url: `/pages/production/work-order-detail/index?masterId=${this.data.id}&masterOrderNo=${encodeURIComponent(this.data.master.master_order_no)}&workOrderId=${workOrderId}`,
    });
  },
  previewAttachment(event) {
    const item = event.currentTarget.dataset.item;
    if (!item || !item.id || !item.can_preview) {
      wx.showToast({ title: '该附件暂不支持预览', icon: 'none' });
      return;
    }
    production.previewSalesOrderAttachment(item.id, item).catch(error => {
      wx.showToast({ title: error.message || '附件预览失败', icon: 'none' });
    });
  },
  viewReceivableDetail() {
    if (this.data.master.sales_order_id) wx.navigateTo({ url: `/pages/orders/detail/index?id=${this.data.master.sales_order_id}` });
  },
  openDeliveryAlerts() {
    wx.showToast({ title: `已显示 ${this.data.master.deliveryTab.pendingAlerts.length} 条待处理`, icon: 'none' });
  },
  openActiveDelivery(event) {
    const item = event.currentTarget.dataset.item;
    if (item && item.first_delivery_id) wx.navigateTo({ url: `/pages/production/delivery-detail/index?id=${item.first_delivery_id}` });
  },
  openUpcomingDelivery(event) {
    const item = event.currentTarget.dataset.item;
    if (item) wx.showToast({ title: item.expected_release_text || '待配送', icon: 'none' });
  },
  openDeliveryHistory() { wx.navigateTo({ url: '/pages/production/workbench/index' }); },
  alertAction(event) {
    const type = event.currentTarget.dataset.type;
    if (type === 'exception') this.setData({ activeTab: 'execution' });
    else if (type === 'kitting') this.setData({ activeTab: 'delivery' });
    else if (type === 'funding') this.setData({ activeTab: 'funding' });
  },
});
