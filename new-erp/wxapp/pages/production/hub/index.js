const util = require('../../../utils/util.js');
const view = require('../../../utils/warehouse-page.js');
const production = require('../../../services/production.js');
const warehouse = require('../../../services/warehouse.js');
const packing = require('../../../services/packing.js');
const auth = require('../../../services/erp-auth.js');

function toNum(value, fallback = 0) {
  const num = Number(value);
  return Number.isFinite(num) ? num : fallback;
}

function formatHours(value) {
  const num = toNum(value, 0);
  if (num <= 0) return '0h';
  const rounded = Math.round(num * 10) / 10;
  return `${rounded}h`;
}

function moduleState(result) {
  if (result.status === 'fulfilled' && result.value === null) return { status: 'hidden', label: '', message: '' };
  if (result.status === 'fulfilled') return { status: 'ready', label: '', message: '' };
  const error = result.reason || {};
  if (error.statusCode === 403) return { status: 'forbidden', label: '无权查看', message: '当前账号没有查看权限' };
  return { status: 'error', label: '加载失败', message: view.errorText(error) };
}

function hubAccess() {
  const can = view.permissions();
  return {
    workOrder: can('production.task.execute') || can('production.task.view'),
    warehouse: ['inventory.post.view', 'sales_return.view', 'sales_order.shipment.view', 'sales_order.shipment.post', 'purchase_return.view'].some(can),
    shipment: can('sales_order.shipment.packing.execute') || can('sales_order.shipment.packing.quality'),
    stats: can('production.board') || can('production.task.view'),
    scan: can('production.serial.view')
  };
}

function orderProgressSession() {
  const token = wx.getStorageSync('erp_token');
  const user = wx.getStorageSync('erp_user') || {};
  return token ? `${token}:${user.legacy_id || ''}` : '';
}

function orderProgressWorkOrders(workOrders, parentNo = '', rows = []) {
  workOrders.forEach(({ id, work_order_no, name, status_label, operations, children }) => {
    rows.push({
      id, work_order_no, name, status_label, parentNo,
      operations: operations.map(operation => Object.assign({}, operation, {
        key: `${operation.id}-${operation.lane}`,
        timingText: operation.timing_names.join('、'),
        materials: operation.materials.map((material, index) => ({
          key: index,
          name: material.name,
          quantityText: `${view.quantity(material.received_qty)} / ${view.quantity(material.required_qty)} ${material.unit_name || ''}`
        }))
      }))
    });
    orderProgressWorkOrders(children, work_order_no, rows);
  });
  return rows;
}

Page({
  data: {
    hasToken: false,
    access: { workOrder: false, warehouse: false, shipment: false, stats: false, scan: false },
    loading: false,
    moduleStates: {},
    loadErrors: [],
    userName: '生产账号',
    userRole: '生产与仓储',
    userInitial: '生',
    statusSubtitle: '生产工单 · 仓储出入库 · 试水打包 · 实时看板',
    heroStats: {
      availableOrders: '--',
      activeOrders: '--',
      warehouseTodoCount: '--',
      shipmentPendingCount: '--',
      todayHours: '--'
    },
    moduleStats: {
      workOrder: {
        badge: '车间作业',
        mineCount: '--',
        claimableCount: '--'
      },
      warehouse: {
        badge: '出入库',
        inboundToday: '--',
        outboundToday: '--'
      },
      shipment: {
        badge: '试水打包',
        waterTestCount: '--',
        packingCount: '--'
      },
      stats: {
        badge: '实时看板',
        completedUnitsToday: '--',
        secondaryValue: '--',
        secondaryLabel: '待入库'
      }
    },
    activeWorkList: [],
    showStatsModal: false,
    statsDetail: {
      completedUnitsToday: '--',
      inProgressUnits: '--',
      pendingReceiptUnits: '--',
      reworkUnits: '--',
      availableOrders: '--',
      activeOrders: '--',
      todayHours: '--',
      warehouseTodoCount: '--',
      inboundToday: '--',
      outboundToday: '--',
      shipmentPendingCount: '--',
      recentReports: [],
      recentReceipts: []
    },
    serialScanResult: null,
    orderProgress: null
  },

  onLoad(options = {}) {
    this.options = options || {};
    this._openStatsOnLoad = this.options.panel === 'stats';
  },

  onShow() {
    this._unloaded = false;
    this.syncSessionAndLoad();
    this.refreshOrderProgress();
  },

  onHide() {
    if (!this.data.orderProgress) return;
    this._progressListRequest = null;
    this._progressDetailRequest = null;
    this.setData({
      'orderProgress.rows': [], 'orderProgress.loading': true, 'orderProgress.error': '',
      'orderProgress.detail': null, 'orderProgress.detailError': ''
    });
  },

  onUnload() {
    this._unloaded = true;
    this._requestId = (this._requestId || 0) + 1;
    this._sessionRequestId = (this._sessionRequestId || 0) + 1;
  },

  onPullDownRefresh() {
    this.syncSessionAndLoad();
    this.refreshOrderProgress();
  },

  syncSessionAndLoad(refreshSession = true) {
    const token = wx.getStorageSync('erp_token');
    const erpUser = wx.getStorageSync('erp_user') || {};
    const hasToken = Boolean(token);
    if (this.data.orderProgress && this._progressSession !== orderProgressSession()) this.closeOrderProgress();
    const permissionSnapshot = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    const displayName =
      erpUser.nickname || erpUser.display_name || erpUser.realname || erpUser.name || erpUser.username || '生产账号';
    const roleName = erpUser.role_name || erpUser.department_name || '生产与仓储';
    const initial = String(displayName).trim().slice(0, 1) || '生';
    if (this._sessionToken !== token || this._permissionSnapshot !== permissionSnapshot) {
      if (this._sessionToken !== token || refreshSession) {
        this._sessionRequestId = (this._sessionRequestId || 0) + 1;
        this._sessionPromise = null;
      }
      this._sessionToken = token;
      this._permissionSnapshot = permissionSnapshot;
      this._requestId = (this._requestId || 0) + 1;
      this._loadPromise = null;
      this.setData({
        loading: false,
        access: hasToken ? hubAccess() : { workOrder: false, warehouse: false, shipment: false, stats: false, scan: false },
        showStatsModal: false,
        serialScanResult: null
      });
      this.applyAggregatedData(null, null, null, null, {});
    }

    this.setData({
      hasToken,
      userName: hasToken ? displayName : '生产账号',
      userRole: hasToken ? roleName : '未登录',
      userInitial: hasToken ? initial : '生'
    });
    if (!hasToken) {
      wx.stopPullDownRefresh();
      return;
    }

    if (!refreshSession) {
      if (this._openStatsOnLoad && this.data.access.stats) {
        this._openStatsOnLoad = false;
        this.setData({ showStatsModal: true });
      }
      return this.loadHubData();
    }
    if (this._sessionPromise) return this._sessionPromise;
    const sessionRequestId = this._sessionRequestId = (this._sessionRequestId || 0) + 1;
    this._requestId = (this._requestId || 0) + 1;
    this._loadPromise = null;
    this.setData({ loading: true, loadErrors: [] });
    this._sessionPromise = auth.me({ silent: true })
      .then(response => {
        if (this._unloaded || sessionRequestId !== this._sessionRequestId) return;
        if (wx.getStorageSync('erp_token') !== token) return this.syncSessionAndLoad();
        wx.setStorageSync('erp_user', response.user || {});
        wx.setStorageSync('erp_permissions', response.permissions || []);
        // 权限可能已在后台调整；每次成功刷新都重新放行，让后续接口重新确认上次的403。
        this.setData({ access: hubAccess() });
        return this.syncSessionAndLoad(false);
      })
      .catch(error => {
        if (this._unloaded || sessionRequestId !== this._sessionRequestId) return;
        if (wx.getStorageSync('erp_token') !== token) return this.syncSessionAndLoad();
        this.setData({
          loading: false,
          loadErrors: [{ key: 'session', name: '账号权限', message: view.errorText(error) }],
          statusSubtitle: '账号权限未能更新，请重试'
        });
      })
      .finally(() => {
        if (this._unloaded || sessionRequestId !== this._sessionRequestId) return;
        this._sessionPromise = null;
        wx.stopPullDownRefresh();
      });
    return this._sessionPromise;
  },

  loadHubData() {
    const token = wx.getStorageSync('erp_token');
    if (!token || token !== this._sessionToken) return this.syncSessionAndLoad();
    if (this._loadPromise) return this._loadPromise;
    const requestId = this._requestId = (this._requestId || 0) + 1;
    const permissionSnapshot = this._permissionSnapshot;
    const access = this.data.access;

    this.setData({ loading: true });
    const loadingState = { status: 'loading', label: '同步中', message: '' };
    const hiddenState = { status: 'hidden', label: '', message: '' };
    this.applyAggregatedData(null, null, null, null, {
      workOrder: access.workOrder ? loadingState : hiddenState,
      warehouse: access.warehouse ? loadingState : hiddenState,
      shipment: access.shipment ? loadingState : hiddenState,
      stats: access.stats ? loadingState : hiddenState
    });

    this._loadPromise = Promise.allSettled([
      access.workOrder ? production.tasks({ tab: 'mine', page: 1, per_page: 10 }) : null,
      access.warehouse ? warehouse.summary() : null,
      access.shipment ? packing.operations({ page: 1, per_page: 10, unfinished: 1, include_summary: 1 }) : null,
      access.stats ? production.board({}) : null
    ])
      .then(([tasksRes, warehouseRes, packingRes, boardRes]) => {
        if (this._unloaded || requestId !== this._requestId) return;
        if (wx.getStorageSync('erp_token') !== token || JSON.stringify(wx.getStorageSync('erp_permissions') || []) !== permissionSnapshot) {
          this.syncSessionAndLoad();
          return;
        }
        const taskRaw = tasksRes.status === 'fulfilled' ? tasksRes.value : null;
        const warehouseRaw = warehouseRes.status === 'fulfilled' ? warehouseRes.value : null;
        const packingRaw = packingRes.status === 'fulfilled' ? packingRes.value : null;
        const boardRaw = boardRes.status === 'fulfilled' ? boardRes.value : null;

        this.applyAggregatedData(taskRaw, warehouseRaw, packingRaw, boardRaw, {
          workOrder: moduleState(tasksRes), warehouse: moduleState(warehouseRes),
          shipment: moduleState(packingRes), stats: moduleState(boardRes)
        });
      })
      .finally(() => {
        if (this._unloaded || requestId !== this._requestId) return;
        this._loadPromise = null;
        this.setData({ loading: false });
        wx.stopPullDownRefresh();
      });
    return this._loadPromise;
  },

  applyAggregatedData(taskRaw, warehouseRaw, packingRaw, boardRaw, moduleStates) {
    const access = Object.assign({}, this.data.access);
    Object.keys(moduleStates).forEach(key => {
      if (moduleStates[key].status === 'forbidden') access[key] = false;
    });
    const taskReady = access.workOrder && Boolean(taskRaw);
    const warehouseReady = access.warehouse && Boolean(warehouseRaw);
    const packingReady = access.shipment && Boolean(packingRaw);
    const boardReady = access.stats && Boolean(boardRaw);
    const labels = { workOrder: '工单', warehouse: '仓库', shipment: '发货', stats: '统计' };
    const loadErrors = Object.keys(moduleStates).filter(key => moduleStates[key].status === 'error')
      .map(key => ({ key, name: labels[key], message: moduleStates[key].message }));
    taskRaw = taskReady ? taskRaw : {};
    warehouseRaw = warehouseReady ? warehouseRaw : {};
    packingRaw = packingReady ? packingRaw : {};
    boardRaw = boardReady ? boardRaw : {};
    // 1. 工单任务数据（TaskQueryService::tasks）
    const taskPaged = view.rows(taskRaw);
    const taskItems = Array.isArray(taskPaged.rows) ? taskPaged.rows : [];
    const taskCounts = taskRaw.counts || (taskRaw.data && taskRaw.data.counts) || {};
    const mineCount = toNum(taskCounts.mine, taskPaged.total || taskItems.length);
    const claimableCount = toNum(taskCounts.available, 0);
    const timingCount = taskItems.filter(item => Boolean(item.timing)).length;

    // 2. 仓库工作设备数据（Warehouse summary）
    const whBody = warehouseRaw.data || warehouseRaw || {};
    const whTodosRaw = (whBody.todos && whBody.todos.data) || (Array.isArray(whBody.todos) ? whBody.todos : []);
    const whTodoCount = toNum(whBody.todos && whBody.todos.total, whTodosRaw.length);
    const inboundToday = toNum(whBody.today_inbound, 0);
    const outboundToday = toNum(whBody.today_outbound, 0);

    // 3. 发货（试水与打包）数据（Packing operations）
    const packingPaged = view.rows(packingRaw);
    const activePackingItems = Array.isArray(packingPaged.rows) ? packingPaged.rows : [];
    const packingSummary = packingRaw.summary || {};
    const waterTestCount = toNum(packingSummary.water_test);
    const packingCount = toNum(packingSummary.packing);
    const shipmentPendingCount = toNum(packingSummary.total);

    // 4. 统计看板数据（TaskQueryService::board）
    const boardBody = boardRaw.data || boardRaw || {};
    const boardStats = boardBody.stats || {};
    const completedUnitsToday = toNum(boardStats.today_passed, 0);
    const inProgressUnits = toNum(boardStats.in_progress_units, 0);
    const pendingReceiptUnits = toNum(boardStats.waiting_receipt, 0);
    const reworkUnits = toNum(boardStats.rework_units, 0);
    const availableOrders = toNum(taskCounts.available_work_orders);
    const activeOrders = toNum(taskCounts.active_work_orders);
    const displayHours = formatHours(boardStats.today_hours);
    const recentReports = Array.isArray(boardBody.recent_reports) ? boardBody.recent_reports.slice(0, 5) : [];
    const recentReceipts = Array.isArray(boardBody.today_receipts) ? boardBody.today_receipts.slice(0, 5) : [];

    // 5. 构建【我的当前作业】直达待办列表
    const activeWorkList = [];

    taskItems.slice(0, 3).forEach(task => {
      const isTiming = Boolean(task.timing);
      const hereQty = toNum(task.here_qty, Math.max(0, toNum(task.planned_qty, 0) - toNum(task.done_qty, 0)));
      activeWorkList.push({
        id: `task-${task.key || task.id}`,
        type: 'task',
        targetId: task.id,
        lane: task.lane || 'normal',
        tagText: isTiming ? '工单 · 计时中' : '工单 · 待报工',
        tagClass: 'tag-red',
        code: `${task.work_order_no || '工单'} · ${task.title || task.operation_name || '工序'}`,
        desc: `${task.product_name || '生产任务'}（本道 ${hereQty} ${task.unit_short || '台'}）`,
        btnText: isTiming ? '继续报工' : '去报工',
        btnClass: isTiming ? 'btn-solid-red' : 'btn-outline-red'
      });
    });

    whTodosRaw.slice(0, 2).forEach(todo => {
      const decorated = view.decorate(todo);
      activeWorkList.push({
        id: `wh-${todo.key || todo.id}`,
        type: 'warehouse',
        rawTodo: decorated,
        tagText: `仓库 · ${todo.title || '待办理'}`,
        tagClass: 'tag-orange',
        code: todo.number || todo.work_order_no || todo.title || '仓库单据',
        desc: `${todo.item_name || '仓储物料'}${decorated.quantityText ? ` · ${decorated.quantityText} ${todo.unit_name || ''}` : ''}`,
        btnText: '去办理',
        btnClass: 'btn-outline-red'
      });
    });

    activePackingItems.slice(0, 2).forEach(op => {
      const waitingOutbound = op.status === 'COMPLETED';
      const isWaterTest = op.shipment_operation_type === 'water_test';
      const isPacking = op.shipment_operation_type === 'packing';
      const isLoading = op.shipment_operation_type === 'loading';
      const firstContent = Array.isArray(op.contents) && op.contents[0] ? op.contents[0] : null;
      const contentDesc = firstContent
        ? `${firstContent.product_name || firstContent.item_name || ''} · ${firstContent.packaging_scheme_name || ''}`
        : op.operation_name || '试水与扫码打包';
      activeWorkList.push({
        id: `pack-${op.id}`,
        type: 'shipment',
        targetId: op.id,
        tagText: waitingOutbound ? '发货 · 待出库' : isWaterTest ? '发货 · 待试水' : isPacking ? '发货 · 打包中' : isLoading ? '发货 · 装车中' : '发货 · 待办理',
        tagClass: 'tag-blue',
        code: `${op.package_no || `包裹 #${op.id}`} · ${op.operation_name || '发货作业'}`,
        desc: contentDesc,
        btnText: waitingOutbound ? '查看出库' : isWaterTest ? '去检验' : isPacking ? '去打包' : '去办理',
        btnClass: 'btn-outline-red'
      });
    });

    this.setData({
      access,
      moduleStates,
      loadErrors,
      showStatsModal: this.data.showStatsModal && access.stats,
      statusSubtitle:
        loadErrors.length > 0
          ? '部分数据未能加载，请查看提示后重试'
          : this.data.loading && !taskReady
          ? '正在同步生产与仓储数据'
          : timingCount > 0
          ? `当前有 ${timingCount} 道工序正在计时作业`
          : boardReady && warehouseReady
          ? `今日合格 ${completedUnitsToday} 台 · 待办出入库 ${whTodoCount} 单`
          : '生产工单 · 仓储出入库 · 试水打包 · 实时看板',
      heroStats: {
        availableOrders: taskReady ? availableOrders : '--',
        activeOrders: taskReady ? activeOrders : '--',
        warehouseTodoCount: warehouseReady ? whTodoCount : '--',
        shipmentPendingCount: packingReady ? shipmentPendingCount : '--',
        todayHours: boardReady ? displayHours : '--'
      },
      moduleStats: {
        workOrder: {
          badge: !taskReady ? ((moduleStates.workOrder || {}).label || '待登录') : mineCount > 0 ? `${mineCount} 项待报工` : claimableCount > 0 ? `${claimableCount} 项可接` : '车间作业',
          mineCount: taskReady ? mineCount : '--',
          claimableCount: taskReady ? claimableCount : '--'
        },
        warehouse: {
          badge: !warehouseReady ? ((moduleStates.warehouse || {}).label || '待登录') : whTodoCount > 0 ? `${whTodoCount} 单待办` : '出入库',
          inboundToday: warehouseReady ? inboundToday : '--',
          outboundToday: warehouseReady ? outboundToday : '--'
        },
        shipment: {
          badge:
            !packingReady ? ((moduleStates.shipment || {}).label || '待登录') : waterTestCount > 0
              ? `${waterTestCount} 单待试水`
              : packingCount > 0
              ? `${packingCount} 单打包中`
              : '试水打包',
          waterTestCount: packingReady ? waterTestCount : '--',
          packingCount: packingReady ? packingCount : '--'
        },
        stats: {
          badge: boardReady ? '实时看板' : ((moduleStates.stats || {}).label || '待登录'),
          completedUnitsToday: boardReady ? `${completedUnitsToday} 台` : '--',
          secondaryValue: boardReady ? `${pendingReceiptUnits} 台` : '--',
          secondaryLabel: '待入库'
        }
      },
      activeWorkList: activeWorkList.slice(0, 5),
      statsDetail: {
        completedUnitsToday: boardReady ? completedUnitsToday : '--',
        inProgressUnits: boardReady ? inProgressUnits : '--',
        pendingReceiptUnits: boardReady ? pendingReceiptUnits : '--',
        reworkUnits: boardReady ? reworkUnits : '--',
        availableOrders: taskReady ? availableOrders : '--',
        activeOrders: taskReady ? activeOrders : '--',
        todayHours: boardReady ? displayHours : '--',
        warehouseTodoCount: warehouseReady ? whTodoCount : '--',
        inboundToday: warehouseReady ? inboundToday : '--',
        outboundToday: warehouseReady ? outboundToday : '--',
        shipmentPendingCount: packingReady ? shipmentPendingCount : '--',
        recentReports,
        recentReceipts
      }
    });
  },

  ensureLogin() {
    const token = wx.getStorageSync('erp_token');
    const permissionSnapshot = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    if (this.data.hasToken && token && token === this._sessionToken && permissionSnapshot === this._permissionSnapshot) {
      return true;
    }
    this.syncSessionAndLoad();
    if (token) return false;
    util.BadgePopup('production');
    return false;
  },

  ensureAccess(moduleKey) {
    return this.ensureLogin() && this.data.access[moduleKey] === true;
  },

  openLogin() {
    util.BadgePopup('production');
  },

  handleUserTap() {
    if (!this.data.hasToken) {
      util.BadgePopup('production');
    }
  },

  refreshAll() {
    if (!this.ensureLogin()) return;
    return this.syncSessionAndLoad();
  },

  openModule(e) {
    if (!this.ensureLogin()) return;
    const moduleKey = e.currentTarget.dataset.module;
    this.navigateByModuleKey(moduleKey, e.currentTarget.dataset.tab);
  },

  openModuleFromSheet(e) {
    if (!this.ensureLogin()) return;
    const moduleKey = e.currentTarget.dataset.module;
    this.setData({
      showStatsModal: false,
      serialScanResult: null
    });
    this.navigateByModuleKey(moduleKey, e.currentTarget.dataset.tab);
  },

  navigateByModuleKey(moduleKey, tab) {
    if (!this.ensureAccess(moduleKey)) return;
    if (moduleKey === 'workOrder') {
      wx.navigateTo({ url: `/pages/production/tasks/index?tab=${tab === 'available' ? 'available' : 'mine'}` });
      return;
    }
    if (moduleKey === 'warehouse') {
      wx.navigateTo({ url: '/pages/warehouse/workbench/index' });
      return;
    }
    if (moduleKey === 'shipment') {
      wx.navigateTo({ url: '/pages/production/shipment-packing/index' });
      return;
    }
    if (moduleKey === 'stats') {
      this.setData({ showStatsModal: true });
    }
  },

  closeStatsModal() {
    this.setData({ showStatsModal: false });
  },

  openOrderProgress() {
    const session = orderProgressSession();
    if (!session) {
      this.syncSessionAndLoad();
      this.openLogin();
      return;
    }
    this._progressSession = session;
    this.setData({ orderProgress: { input: '', keyword: '', rows: [], page: 1, lastPage: 1, total: 0 } });
    return this.loadOrderProgress(1, '');
  },

  closeOrderProgress() {
    this._progressSession = null;
    this._progressListRequest = null;
    this._progressDetailRequest = null;
    this.setData({ orderProgress: null });
  },

  currentOrderProgress() {
    if (this._unloaded || !this.data.orderProgress) return null;
    if (this._progressSession === orderProgressSession()) return this.data.orderProgress;
    this.closeOrderProgress();
    this.syncSessionAndLoad();
    return null;
  },

  orderProgressInput(e) {
    if (this.currentOrderProgress()) this.setData({ 'orderProgress.input': e.detail.value });
  },

  searchOrderProgress() {
    const state = this.currentOrderProgress();
    if (state) return this.loadOrderProgress(1, state.input.trim());
  },

  clearOrderProgressSearch() {
    if (!this.currentOrderProgress()) return;
    this.setData({ 'orderProgress.input': '' });
    return this.loadOrderProgress(1, '');
  },

  refreshOrderProgress() {
    const state = this.currentOrderProgress();
    if (state) return this.loadOrderProgress(state.page, state.keyword, state.expandedId);
  },

  turnOrderProgressPage(e) {
    const state = this.currentOrderProgress();
    if (!state || state.loading) return;
    const page = state.page + Number(e.currentTarget.dataset.delta);
    if (page >= 1 && page <= state.lastPage) return this.loadOrderProgress(page, state.keyword);
  },

  loadOrderProgress(page, keyword, expandedId = 0) {
    if (!this.currentOrderProgress()) return;
    // 新查询或切换账号后，旧请求不能覆盖当前列表或工序入口。
    const request = this._progressListRequest = {};
    this._progressDetailRequest = null;
    this.setData({
      'orderProgress.page': page, 'orderProgress.keyword': keyword, 'orderProgress.rows': [],
      'orderProgress.expandedId': expandedId, 'orderProgress.detail': null, 'orderProgress.detailError': '',
      'orderProgress.loading': true, 'orderProgress.error': ''
    });
    return production.orderProgress({ keyword, page, per_page: 10 })
      .then(response => {
        if (request !== this._progressListRequest || !this.currentOrderProgress()) return;
        const result = view.rows(response);
        if (page > result.lastPage) return this.loadOrderProgress(result.lastPage, keyword);
        this.setData({
          'orderProgress.rows': result.rows, 'orderProgress.page': result.page,
          'orderProgress.lastPage': result.lastPage, 'orderProgress.total': result.total,
          'orderProgress.loading': false
        });
        if (expandedId && result.rows.some(row => row.id === expandedId)) return this.loadOrderProgressDetail(expandedId);
        this.setData({ 'orderProgress.expandedId': 0 });
      })
      .catch(error => {
        if (request !== this._progressListRequest || !this.currentOrderProgress()) return;
        this.setData({ 'orderProgress.loading': false, 'orderProgress.error': view.errorText(error) });
      });
  },

  toggleOrderProgress(e) {
    const state = this.currentOrderProgress();
    if (!state) return;
    const id = Number(e.currentTarget.dataset.id);
    if (state.expandedId !== id) return this.loadOrderProgressDetail(id);
    this._progressDetailRequest = null;
    this.setData({ 'orderProgress.expandedId': 0, 'orderProgress.detail': null, 'orderProgress.detailError': '' });
  },

  retryOrderProgressDetail() {
    const state = this.currentOrderProgress();
    if (state) return this.loadOrderProgressDetail(state.expandedId);
  },

  loadOrderProgressDetail(id) {
    const state = this.currentOrderProgress();
    if (!state || !state.rows.some(row => row.id === id)) return;
    const request = this._progressDetailRequest = {};
    this.setData({ 'orderProgress.expandedId': id, 'orderProgress.detail': null, 'orderProgress.detailError': '' });
    return production.orderProgressDetail(id)
      .then(response => {
        if (request !== this._progressDetailRequest || !this.currentOrderProgress()) return;
        const lines = response.data.lines.map(line => ({
          id: line.id, name: line.name, workOrders: orderProgressWorkOrders(line.work_orders)
        }));
        this.setData({ 'orderProgress.detail': { lines } });
      })
      .catch(error => {
        if (request !== this._progressDetailRequest || !this.currentOrderProgress()) return;
        this.setData({ 'orderProgress.detailError': view.errorText(error) });
      });
  },

  openOrderProgressOperation(e) {
    const state = this.currentOrderProgress();
    const { id, lane } = e.currentTarget.dataset;
    const operationId = Number(id);
    if (!state || !state.detail || !operationId || !['normal', 'rework'].includes(lane)) return;
    const canOpen = state.detail.lines.some(line => line.workOrders.some(order =>
      order.operations.some(operation => operation.id === operationId && operation.lane === lane && operation.can_open === true)
    ));
    if (canOpen) wx.navigateTo({ url: `/pages/production/task/index?id=${operationId}&lane=${lane}` });
  },

  openWorkItem(e) {
    const item = e.currentTarget.dataset.item;
    if (!item || !this.ensureAccess(item.type === 'task' ? 'workOrder' : item.type)) return;

    if (item.type === 'task' && item.targetId) {
      wx.navigateTo({
        url: `/pages/production/task/index?id=${item.targetId}&lane=${item.lane || 'normal'}`
      });
      return;
    }
    if (item.type === 'warehouse') {
      if (item.rawTodo && item.rawTodo.id) {
        view.openDocument(item.rawTodo);
        return;
      }
      wx.navigateTo({
        url: '/pages/warehouse/workbench/index'
      });
      return;
    }
    if (item.type === 'shipment') {
      const url = item.targetId
        ? `/pages/production/shipment-packing/index?operation_id=${item.targetId}`
        : '/pages/production/shipment-packing/index';
      wx.navigateTo({ url });
    }
  },

  scanCode() {
    if (!this.ensureAccess('scan')) return;
    const token = this._sessionToken;
    const permissionSnapshot = this._permissionSnapshot;
    wx.scanCode({
      onlyFromCamera: false,
      success: res => {
        if (this._unloaded || token !== this._sessionToken || permissionSnapshot !== this._permissionSnapshot || !this.ensureAccess('scan')) return;
        const raw = String((res && res.result) || '').trim();
        if (!raw) return;

        production
          .serialLookup({ serial_no: raw })
          .then(lookupRes => {
            if (this._unloaded || token !== this._sessionToken || permissionSnapshot !== this._permissionSnapshot) return;
            if (wx.getStorageSync('erp_token') !== token || JSON.stringify(wx.getStorageSync('erp_permissions') || []) !== permissionSnapshot) {
              if (!this._unloaded) this.syncSessionAndLoad();
              return;
            }
            if (!this.data.access.scan) return;
            const detail = (lookupRes && lookupRes.data) || lookupRes || null;
            if (detail && detail.serial_no) {
              const wo = detail.work_order || {};
              this.setData({
                serialScanResult: {
                  serial_no: detail.serial_no,
                  status: detail.status,
                  status_label: detail.status_label,
                  current_operation_name: detail.current_operation_name || '',
                  current_task_id: detail.current_operation_id || null,
                  work_order_no: wo.work_order_no || '',
                  product_name: wo.product_name || wo.item_name || ''
                }
              });
              return;
            }
            this.openScannedTasks(raw);
          })
          .catch(error => {
            if (this._unloaded || token !== this._sessionToken || permissionSnapshot !== this._permissionSnapshot) return;
            if (wx.getStorageSync('erp_token') !== token || JSON.stringify(wx.getStorageSync('erp_permissions') || []) !== permissionSnapshot) {
              this.syncSessionAndLoad();
              return;
            }
            if (error.statusCode === 403) {
              this.setData({ 'access.scan': false, serialScanResult: null });
              return;
            }
            if (error.statusCode !== 404) {
              wx.showToast({ title: view.errorText(error), icon: 'none' });
              return;
            }
            this.openScannedTasks(raw);
          });
      }
    });
  },

  closeScanModal() {
    this.setData({ serialScanResult: null });
  },

  openScannedTasks(keyword) {
    if (!this.ensureAccess('workOrder')) {
      wx.showToast({ title: '未找到对应设备', icon: 'none' });
      return;
    }
    wx.navigateTo({ url: `/pages/production/tasks/index?keyword=${encodeURIComponent(keyword)}` });
  },

  openScannedTask(e) {
    if (!this.ensureAccess('scan') || !this.ensureAccess('workOrder')) return;
    const id = e.currentTarget.dataset.id;
    this.setData({ serialScanResult: null });
    if (id) {
      wx.navigateTo({
        url: `/pages/production/task/index?id=${id}`
      });
    }
  },

  noop() {}
});
