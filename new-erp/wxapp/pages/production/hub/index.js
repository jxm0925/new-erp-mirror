const util = require('../../../utils/util.js');
const view = require('../../../utils/warehouse-page.js');
const production = require('../../../services/production.js');
const warehouse = require('../../../services/warehouse.js');
const packing = require('../../../services/packing.js');

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
  if (result.status === 'fulfilled') return { status: 'ready', label: '', message: '' };
  const error = result.reason || {};
  if (error.statusCode === 403) return { status: 'forbidden', label: '无权查看', message: '当前账号没有查看权限' };
  return { status: 'error', label: '加载失败', message: view.errorText(error) };
}

Page({
  data: {
    hasToken: false,
    loading: false,
    moduleStates: {},
    loadErrors: [],
    userName: '生产账号',
    userRole: '生产与仓储',
    userInitial: '生',
    statusSubtitle: '生产工单 · 仓储出入库 · 试水打包 · 实时看板',
    heroStats: {
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
      activeOrders: '--',
      todayHours: '--',
      warehouseTodoCount: '--',
      inboundToday: '--',
      outboundToday: '--',
      shipmentPendingCount: '--',
      recentReports: [],
      recentReceipts: []
    },
    serialScanResult: null
  },

  onLoad(options = {}) {
    this.options = options || {};
    if (this.options.panel === 'stats') {
      this.setData({ showStatsModal: true });
    }
  },

  onShow() {
    this._unloaded = false;
    this.syncSessionAndLoad();
  },

  onUnload() {
    this._unloaded = true;
    this._requestId = (this._requestId || 0) + 1;
  },

  onPullDownRefresh() {
    this.syncSessionAndLoad();
  },

  syncSessionAndLoad() {
    const token = wx.getStorageSync('erp_token');
    const erpUser = wx.getStorageSync('erp_user') || {};
    const hasToken = Boolean(token);
    const displayName =
      erpUser.nickname || erpUser.display_name || erpUser.realname || erpUser.name || erpUser.username || '生产账号';
    const roleName = erpUser.role_name || erpUser.department_name || '生产与仓储';
    const initial = String(displayName).trim().slice(0, 1) || '生';
    if (this._sessionToken !== token) {
      this._sessionToken = token;
      this._requestId = (this._requestId || 0) + 1;
      this._loadPromise = null;
      this.setData({ loading: false, serialScanResult: null });
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

    return this.loadHubData();
  },

  loadHubData() {
    const token = wx.getStorageSync('erp_token');
    if (!token || token !== this._sessionToken) return this.syncSessionAndLoad();
    if (this._loadPromise) return this._loadPromise;
    const requestId = this._requestId = (this._requestId || 0) + 1;

    this.setData({ loading: true });
    const loadingState = { status: 'loading', label: '同步中', message: '' };
    this.applyAggregatedData(null, null, null, null, {
      workOrder: loadingState, warehouse: loadingState, shipment: loadingState, stats: loadingState
    });

    this._loadPromise = Promise.allSettled([
      production.tasks({ tab: 'mine', page: 1, per_page: 10 }),
      warehouse.summary(),
      packing.operations({ page: 1, per_page: 10, unfinished: 1, include_summary: 1 }),
      production.board({})
    ])
      .then(([tasksRes, warehouseRes, packingRes, boardRes]) => {
        if (this._unloaded || requestId !== this._requestId) return;
        if (wx.getStorageSync('erp_token') !== token) {
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
    const taskReady = Boolean(taskRaw);
    const warehouseReady = Boolean(warehouseRaw);
    const packingReady = Boolean(packingRaw);
    const boardReady = Boolean(boardRaw);
    const labels = { workOrder: '工单', warehouse: '仓库', shipment: '发货', stats: '统计' };
    const loadErrors = Object.keys(moduleStates).filter(key => ['error', 'forbidden'].includes(moduleStates[key].status))
      .map(key => ({ key, name: labels[key], message: moduleStates[key].message }));
    taskRaw = taskRaw || {};
    warehouseRaw = warehouseRaw || {};
    packingRaw = packingRaw || {};
    boardRaw = boardRaw || {};
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
    const packingItems = Array.isArray(packingPaged.rows) ? packingPaged.rows : [];
    const activePackingItems = packingItems.filter(
      op => op.status !== 'COMPLETED' && op.status !== 'CANCELLED'
    );
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
        id: `task-${task.id}`,
        type: 'task',
        targetId: task.id,
        tagText: isTiming ? '工单 · 计时中' : '工单 · 待报工',
        tagClass: 'tag-red',
        code: `${task.work_order_no || '工单'} · ${task.operation_name || '工序'}`,
        desc: `${task.product_name || '生产任务'}（当前可报 ${hereQty || toNum(task.planned_qty, 1)} 台）`,
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
        tagText: isWaterTest ? '发货 · 待试水' : isPacking ? '发货 · 打包中' : isLoading ? '发货 · 装车中' : '发货 · 待办理',
        tagClass: 'tag-blue',
        code: `${op.package_no || `包裹 #${op.id}`} · ${op.operation_name || '发货作业'}`,
        desc: contentDesc,
        btnText: isWaterTest ? '去检验' : isPacking ? '去打包' : '去办理',
        btnClass: 'btn-outline-red'
      });
    });

    this.setData({
      moduleStates,
      loadErrors,
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
    if (this.data.hasToken && token && token === this._sessionToken) {
      return true;
    }
    this.syncSessionAndLoad();
    if (token) return false;
    util.BadgePopup('production');
    return false;
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
    return this.loadHubData();
  },

  openModule(e) {
    if (!this.ensureLogin()) return;
    const moduleKey = e.currentTarget.dataset.module;
    this.navigateByModuleKey(moduleKey);
  },

  openModuleFromSheet(e) {
    if (!this.ensureLogin()) return;
    const moduleKey = e.currentTarget.dataset.module;
    this.setData({
      showStatsModal: false,
      serialScanResult: null
    });
    this.navigateByModuleKey(moduleKey);
  },

  navigateByModuleKey(moduleKey) {
    if (moduleKey === 'workOrder') {
      wx.navigateTo({ url: '/pages/production/tasks/index' });
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

  openWorkItem(e) {
    if (!this.ensureLogin()) return;
    const item = e.currentTarget.dataset.item;
    if (!item) return;

    if (item.type === 'task' && item.targetId) {
      wx.navigateTo({
        url: `/pages/production/task/index?id=${item.targetId}`
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
    if (!this.ensureLogin()) return;
    const token = this._sessionToken;
    wx.scanCode({
      onlyFromCamera: false,
      success: res => {
        const raw = String((res && res.result) || '').trim();
        if (!raw) return;

        production
          .serialLookup({ serial_no: raw })
          .then(lookupRes => {
            if (this._unloaded || wx.getStorageSync('erp_token') !== token) {
              if (!this._unloaded) this.syncSessionAndLoad();
              return;
            }
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
            wx.navigateTo({
              url: `/pages/production/tasks/index?keyword=${encodeURIComponent(raw)}`
            });
          })
          .catch(error => {
            if (this._unloaded) return;
            if (wx.getStorageSync('erp_token') !== token) {
              this.syncSessionAndLoad();
              return;
            }
            if (error.statusCode !== 404) {
              wx.showToast({ title: view.errorText(error), icon: 'none' });
              return;
            }
            wx.navigateTo({
              url: `/pages/production/tasks/index?keyword=${encodeURIComponent(raw)}`
            });
          });
      }
    });
  },

  closeScanModal() {
    this.setData({ serialScanResult: null });
  },

  openScannedTask(e) {
    if (!this.ensureLogin()) return;
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
