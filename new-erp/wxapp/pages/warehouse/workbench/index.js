const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const util = require('../../../utils/util');

const KIND_TAGS = {
  mfg_receipt: 'tag-red',
  material_preparation: 'tag-orange',
  material_delivery: 'tag-blue',
  material_return: 'tag-amber',
  stocktake: 'tag-purple',
  inventory_transfer: 'tag-blue',
  material_physical_transfer: 'tag-blue',
  purchase_receipt: 'tag-red',
  sales_shipment: 'tag-blue',
  sales_return: 'tag-orange',
  purchase_return: 'tag-amber',
};

const INBOUND_KINDS = [
  'mfg_receipt',
  'inventory_transfer',
  'material_physical_transfer',
  'purchase_receipt',
  'sales_return',
  'material_return',
];

const OUTBOUND_KINDS = [
  'sales_shipment',
  'purchase_return',
  'material_preparation',
  'material_delivery',
];

Page({
  data: {
    loading: true,
    authenticated: false,
    error: '',
    summary: null,
    todos: [],
    freezes: [],
    freezeTotal: 0,
    freezeOverdue: false,
    actorName: '',
    actorInitial: '仓',
    today: '',
    shortcuts: [],
    heroMetrics: {
      total: 0,
      inbound: 0,
      outbound: 0,
      stocktake: 0,
    },
  },
  onShow() {
    if (!page.resumingScan(this)) this.load();
  },
  onUnload() {
    this.sequence = (this.sequence || 0) + 1;
  },
  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },
  load() {
    const sequence = (this.sequence = (this.sequence || 0) + 1);
    if (!wx.getStorageSync('erp_token')) {
      this.setData({
        authenticated: false,
        loading: false,
        summary: null,
        todos: [],
        freezes: [],
        error: '',
      });
      return Promise.resolve();
    }
    const user = wx.getStorageSync('erp_user') || {};
    const can = page.permissions();
    const shortcuts = [
      { label: '入库办理', subtitle: '采购 · 销退入库', theme: 'red', icon: 'wap-home-o', url: '/pages/warehouse/list/index?direction=inbound', visible: can('inventory.post.view') || can('sales_return.view') },
      { label: '出库办理', subtitle: '发货 · 采退出库', theme: 'blue', icon: 'upgrade', url: '/pages/warehouse/list/index?direction=outbound', visible: can('sales_order.shipment.view') || can('purchase_return.view') },
      { label: '生产配料', subtitle: '按工单齐套拣料', theme: 'orange', icon: 'setting-o', url: '/pages/warehouse/deliveries/index', visible: can('production.material_delivery.manage') },
      { label: '退料接收', subtitle: '车间退料核对', theme: 'amber', icon: 'revoke', url: '/pages/warehouse/list/index?direction=all&kind=material_return', visible: can('inventory.inbound.confirm') || can('production.material_return.approve') },
      { label: '入库确认', subtitle: '生产完工入库', theme: 'green', icon: 'passed', url: '/pages/warehouse/list/index?direction=inbound&kind=mfg_receipt', visible: can('inventory.inbound.confirm') },
      { label: '调拨', subtitle: '跨仓 · 库位移动', theme: 'purple', icon: 'exchange', url: '/pages/warehouse/transfer/index', visible: can('inventory.transfer.view') || can('inventory.transfer.create') || can('inventory.inbound.confirm') || can('inventory.physical.manage') },
      { label: '库存查询', subtitle: '批次 · 序列号 · 实物', theme: 'blue', icon: 'search', url: '/pages/warehouse/inventory/index', visible: can('inventory.balance.view') },
      { label: '盘点', subtitle: '实盘录入 · 复核', theme: 'red', icon: 'records-o', url: '/pages/warehouse/stocktake/index', visible: can('inventory.stocktake.view') },
      { label: '打包', subtitle: '发货打包与复核', theme: 'orange', icon: 'logistics', url: '/pages/production/shipment-packing/index', visible: can('sales_order.shipment.packing.execute') || can('sales_order.shipment.packing.quality') },
    ].filter(row => row.visible);
    const now = new Date();
    const today = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, '0'),
      String(now.getDate()).padStart(2, '0'),
    ].join('-');
    const actorName = user.nickname || user.name || user.username || '';
    this.setData({
      authenticated: true,
      loading: true,
      error: '',
      actorName,
      actorInitial: String(actorName || '仓').trim().slice(0, 1) || '仓',
      today,
      shortcuts,
    });
    return warehouse
      .summary()
      .then(response => {
        if (sequence !== this.sequence) return;
        const summary = response.data || response;
        const freezes = summary.stocktake_freezes || {};
        const pendingCounts = summary.pending_counts || {};
        const rawTodos = (summary.todos || {}).data || [];
        const todosMeta = (summary.todos || {}).meta || summary.todos || {};
        const todos = rawTodos.filter(row => row.kind !== 'cutting_remnant').map(row =>
          Object.assign(page.decorate(row), {
            displayTitle: row.kind === 'stocktake' ? row.party : row.item_name || row.number,
            detailText:
              row.kind === 'material_preparation' && row.waiting_units > 0
                  ? row.waiting_units + ' 台等料'
                  : row.kind === 'stocktake'
                    ? '共 ' + row.item_count + ' 项 · ' + row.status_label
                    : row.related_number || row.status_label,
            actionText:
              row.kind === 'material_preparation'
                ? '去配料'
                : row.kind === 'material_return'
                  ? '去接收'
                  : row.kind === 'mfg_receipt'
                    ? '去确认'
                    : row.kind === 'stocktake'
                      ? '继续盘点'
                      : '去办理',
            icon:
              row.kind === 'material_preparation'
                ? 'setting-o'
                : row.kind === 'material_return'
                  ? 'revoke'
                  : 'records-o',
            tagClass: KIND_TAGS[row.kind] || 'tag-red',
          })
        );
        const inboundPending = INBOUND_KINDS.reduce((sum, k) => sum + Number(pendingCounts[k] || 0), 0);
        const outboundPending = OUTBOUND_KINDS.reduce((sum, k) => sum + Number(pendingCounts[k] || 0), 0);
        this.setData({
          summary,
          todos,
          loading: false,
          freezes: freezes.data || [],
          freezeTotal: freezes.total || 0,
          freezeOverdue: !!freezes.overdue,
          heroMetrics: {
            total: Number(todosMeta.total || todos.length || 0),
            inbound: inboundPending,
            outbound: outboundPending,
            stocktake: Number(summary.pending_stocktakes || freezes.total || 0),
          },
        });
      })
      .catch(error => {
        if (sequence === this.sequence) {
          this.setData({
            loading: false,
            summary: null,
            todos: [],
            error: page.errorText(error),
          });
        }
      });
  },
  openShortcut(event) {
    wx.navigateTo({ url: event.currentTarget.dataset.url });
  },
  openList() {
    wx.navigateTo({ url: '/pages/warehouse/list/index?direction=all' });
  },
  openTodo(event) {
    const row = this.data.todos.find(item => item.key === event.currentTarget.dataset.key);
    if (row) page.openDocument(row);
  },
  resolved(event) {
    const result = event.detail.result;
    if (result.type === 'document') {
      const kind = result.kind === 'sales_return_receipt' ? 'sales_return' : result.kind;
      if (
        ![
          'mfg_receipt',
          'cutting_remnant',
          'inventory_transfer',
          'material_physical_transfer',
          'material_delivery',
          'material_return',
          'stocktake',
          'purchase_receipt',
          'purchase_return',
          'sales_return',
          'sales_shipment',
        ].includes(kind)
      ) {
        return wx.showToast({ title: '请在对应业务入口查看此单据', icon: 'none' });
      }
      if (result.target && result.target.path.startsWith('inventory/warehouse-workspace/documents/')) {
        return page.openDocument({
          kind,
          id: result.id,
          stage: result.kind === 'sales_return' ? 'receive' : 'post',
        });
      }
    }
    if (!page.permissions()('inventory.balance.view')) {
      return wx.showModal({
        title: result.name || '扫码结果',
        content: [
          result.code,
          result.item_name,
          result.spec,
          result.warehouse_name,
          result.location_code,
          result.status_label,
        ]
          .filter(Boolean)
          .join('\n'),
        showCancel: false,
      });
    }
    wx.navigateTo({
      url: '/pages/warehouse/inventory/index?code=' + encodeURIComponent(event.detail.code),
    });
  },
  openLogin() {
    util.BadgePopup();
  },
});
