const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const util = require('../../../utils/util');
Page({
  data: { loading: true, authenticated: false, error: '', summary: null, todos: [], freezes: [], actorName: '', today: '', shortcuts: [] },
  onShow() { if (!page.resumingScan(this)) this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    if (!wx.getStorageSync('erp_token')) { this.setData({ authenticated: false, loading: false, summary: null, todos: [], error: '' }); return Promise.resolve(); }
    const user = wx.getStorageSync('erp_user') || {}; const can = page.permissions();
    const shortcuts = [
      { label: '入库办理', icon: 'wap-home-o', url: '/pages/warehouse/list/index?direction=inbound', visible: can('inventory.post.view') || can('sales_return.view') },
      { label: '出库办理', icon: 'upgrade', url: '/pages/warehouse/list/index?direction=outbound', visible: can('sales_order.shipment.view') || can('purchase_return.view') },
      { label: '生产配料', icon: 'setting-o', url: '/pages/warehouse/deliveries/index', visible: can('production.material_delivery.manage') },
      { label: '退料接收', icon: 'revoke', url: '/pages/warehouse/list/index?direction=all&kind=material_return', visible: can('production.material_return.approve') },
      { label: '库存查询', icon: 'search', url: '/pages/warehouse/inventory/index', visible: can('inventory.balance.view') },
      { label: '盘点', icon: 'records-o', url: '/pages/warehouse/stocktake/index', visible: can('inventory.stocktake.view') },
      { label: '打包', icon: 'logistics', url: '/pages/production/shipment-packing/index', visible: can('sales_order.shipment.packing.execute') || can('sales_order.shipment.packing.quality') },
    ].filter(row => row.visible);
    const now = new Date(); const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
    this.setData({ authenticated: true, loading: true, error: '', actorName: user.nickname || user.name || user.username || '', today, shortcuts });
    return warehouse.summary().then(response => {
      if (sequence !== this.sequence) return;
      const summary = response.data || response; const freezes = summary.stocktake_freezes || {};
      this.setData({ summary, todos: ((summary.todos || {}).data || []).map(row => Object.assign(page.decorate(row), {
        displayTitle: row.kind === 'stocktake' ? row.party : row.item_name || row.number,
        detailText: row.kind === 'material_preparation' && row.waiting_units > 0 ? row.waiting_units + ' 台等料' : row.kind === 'stocktake' ? '共 ' + row.item_count + ' 项 · ' + row.status_label : row.related_number || row.status_label,
        actionText: row.kind === 'material_preparation' ? '去配料' : row.kind === 'material_return' ? '去接收' : row.kind === 'stocktake' ? '继续盘点' : '去办理',
        icon: row.kind === 'material_preparation' ? 'setting-o' : row.kind === 'material_return' ? 'revoke' : 'records-o',
      })), loading: false, freezes: freezes.data || [], freezeTotal: freezes.total || 0, freezeOverdue: !!freezes.overdue });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, summary: null, todos: [], error: page.errorText(error) }); });
  },
  openShortcut(event) { wx.navigateTo({ url: event.currentTarget.dataset.url }); },
  openList() { wx.navigateTo({ url: '/pages/warehouse/list/index?direction=all' }); },
  openTodo(event) { const row = this.data.todos.find(row => row.key === event.currentTarget.dataset.key); if (row) page.openDocument(row); },
  resolved(event) {
    const result = event.detail.result;
    if (result.type === 'document') {
      const kind = result.kind === 'sales_return_receipt' ? 'sales_return' : result.kind;
      if (!['material_delivery', 'material_return', 'stocktake', 'purchase_receipt', 'purchase_return', 'sales_return', 'sales_shipment'].includes(kind)) return wx.showToast({ title: '请在对应业务入口查看此单据', icon: 'none' });
      if (result.target && result.target.path.startsWith('inventory/warehouse-workspace/documents/')) return page.openDocument({ kind, id: result.id, stage: result.kind === 'sales_return' ? 'receive' : 'post' });
    }
    if (!page.permissions()('inventory.balance.view')) return wx.showModal({ title: result.name || '扫码结果', content: [result.code, result.item_name, result.spec, result.warehouse_name, result.location_code, result.status_label].filter(Boolean).join('\n'), showCancel: false });
    wx.navigateTo({ url: '/pages/warehouse/inventory/index?code=' + encodeURIComponent(event.detail.code) });
  },
  openLogin() { util.BadgePopup(); },
});
