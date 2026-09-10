const production = require('../../../services/production');

const STATUS_LABELS = {
  WAIT_PREVIOUS: '待前工序',
  WAIT_CLAIM: '待接单',
  CLAIMED: '已接单',
  WAIT_MATERIAL: '待齐套',
  WAIT_HANDOVER: '待交接',
  READY: '待开工',
  IN_PROGRESS: '进行中',
  PAUSED: '已暂停',
  WAIT_QUALITY: '待质检',
  WAIT_WAREHOUSE: '待入库',
  REWORK: '返工',
  COMPLETED: '已完成',
  CANCELLED: '已取消',
};

function formatElapsed(startedAt, now) {
  if (!startedAt) return '00:00:00';
  const start = new Date(startedAt).getTime();
  if (isNaN(start)) return '00:00:00';
  const current = now || Date.now();
  const diffSec = Math.max(0, Math.floor((current - start) / 1000));
  const h = String(Math.floor(diffSec / 3600)).padStart(2, '0');
  const m = String(Math.floor((diffSec % 3600) / 60)).padStart(2, '0');
  const s = String(diffSec % 60).padStart(2, '0');
  return `${h}:${m}:${s}`;
}

function targetOf(task) {
  const targets = task.target_details || [];
  return (
    targets.find(row => row.status === 'IN_PROGRESS') ||
    targets.find(row => row.status === 'PAUSED') ||
    targets.find(row => !['COMPLETED', 'CANCELLED'].includes(row.status)) ||
    targets.find(row => row.status === 'COMPLETED') ||
    targets[0] ||
    {}
  );
}

function enrichTaskView(task, now) {
  const target = targetOf(task);
  const targetStatus = target.status || task.status || 'READY';
  const item = (task.work_order && task.work_order.output_item) || {};
  const planned = (task.target_details || []).reduce((sum, row) => sum + Number(row.planned_base_qty || 0), 0) || Number(task.planned_qty || 1);
  const completed = (task.target_details || []).reduce((sum, row) => sum + Number(row.completed_base_qty || 0), 0) || Number(task.completed_qty || 0);
  const progressPct = planned > 0 ? Math.min(100, Math.max(0, Math.round((completed / planned) * 100))) : 0;
  const unitName = task.execution_mode === 'unit' ? '台' : (item.unit && item.unit.name ? item.unit.name : '件');

  // 工序编号与名称
  const seq = task.sequence_no_snapshot || '010';
  const opName = task.operation_name_snapshot || '加工作业';
  const operationSnapshot = `${seq} · ${opName}`;

  // 状态分类与徽章文案
  let statusCategory = 'waiting';
  let statusTagText = STATUS_LABELS[targetStatus] || '待处理';

  if (targetStatus === 'IN_PROGRESS') {
    statusCategory = 'running';
    statusTagText = '加工中 ' + formatElapsed(target.started_at, now);
  } else if (targetStatus === 'WAIT_MATERIAL') {
    statusCategory = 'kitting';
    statusTagText = '待齐套 (需领料)';
  } else if (targetStatus === 'READY' || targetStatus === 'CLAIMED') {
    statusCategory = 'ready';
    statusTagText = '待开工';
  } else if (targetStatus === 'COMPLETED') {
    statusCategory = 'completed';
    statusTagText = '已完工';
  } else if (targetStatus === 'PAUSED') {
    statusCategory = 'paused';
    statusTagText = '已暂停';
  } else if (targetStatus === 'WAIT_QUALITY') {
    statusCategory = 'ready';
    statusTagText = '待首检/过程检';
  } else if (targetStatus === 'WAIT_WAREHOUSE') {
    statusCategory = 'ready';
    statusTagText = '待完工入库';
  }

  // 齐套状态判定
  let kittingText = '待确认';
  let kittingClass = 'col-info';
  if (target.kitting_confirmed_at) {
    kittingText = '全部齐套';
    kittingClass = 'col-success';
  } else if (targetStatus === 'WAIT_MATERIAL') {
    kittingText = '常备料待盘点 (缺)';
    kittingClass = 'col-warning';
  } else if (target.kitting_required === false) {
    kittingText = '无需配料';
    kittingClass = 'col-neutral';
  }

  // 执行单元/批次
  let unitText = '批量加工 (' + planned + unitName + ')';
  if (target.production_unit_no) {
    unitText = target.production_unit_no;
  } else if (task.execution_mode === 'unit') {
    unitText = '单件流转';
  }

  // 当前节拍/质检
  let rhythmText = '等待开工';
  if (targetStatus === 'IN_PROGRESS') {
    rhythmText = '阶段 3: 加工中';
  } else if (targetStatus === 'WAIT_QUALITY') {
    rhythmText = '首件检验 (FAI)';
  } else if (targetStatus === 'WAIT_WAREHOUSE') {
    rhythmText = '入库交接中';
  } else if (targetStatus === 'COMPLETED') {
    rhythmText = '工序已完工';
  } else if (targetStatus === 'WAIT_MATERIAL') {
    rhythmText = '物料准备阶段';
  }

  // 作业人员信息
  const currentUserName = wx.getStorageSync('erp_user_name') || '李师傅';
  const operatorName = (task.assignee_user && (task.assignee_user.name || task.assignee_user.username)) || currentUserName;
  const avatarChar = (operatorName || '我').slice(0, 1);
  const collabCount = (task.collaborators && task.collaborators.length) || 0;
  const workerDesc = collabCount > 0 ? `${operatorName} (责任人) · 协同 ${collabCount}人` : `${operatorName} (独立作业)`;

  // 状态感知 CTA 按钮
  let ctaText = '继续作业 ➔';
  let ctaClass = 'cta-danger';
  if (targetStatus === 'IN_PROGRESS') {
    ctaText = '继续作业 ➔';
    ctaClass = 'cta-danger';
  } else if (targetStatus === 'WAIT_MATERIAL') {
    ctaText = '物料核对 ➔';
    ctaClass = 'cta-warning';
  } else if (targetStatus === 'READY' || targetStatus === 'CLAIMED' || targetStatus === 'WAIT_PREVIOUS') {
    ctaText = '开工加工 ➔';
    ctaClass = 'cta-primary';
  } else if (targetStatus === 'COMPLETED') {
    ctaText = '查看详情 ➔';
    ctaClass = 'cta-outline';
  } else {
    ctaText = '进入作业 ➔';
    ctaClass = 'cta-primary';
  }

  return Object.assign({}, task, {
    targetStatus,
    statusCategory,
    statusTagText,
    operationSnapshot,
    productName: item.item_name || item.name || '定制加工产品',
    workOrderNo: (task.work_order && task.work_order.work_order_no) || '-',
    itemSpecification: item.specification || item.specs || item.model || '',
    planned,
    completed,
    progressPct,
    unitName,
    kittingText,
    kittingClass,
    unitText,
    rhythmText,
    avatarChar,
    workerDesc,
    ctaText,
    ctaClass,
    _startedAt: target.started_at,
  });
}

Page({
  data: {
    loading: true,
    loaded: false,
    rows: [],
    filteredRows: [],
    active: 'all',
    keyword: '',
    page: 0,
    total: 0,
    loadingMore: false,
    syncedAt: '',
    teamName: '',
    stats: {
      total: '—',
      running: '—',
      waiting: '—',
      completed: '—',
    },
  },

  onLoad(options) {
    if (options && options.execution_filter) {
      this.setData({ active: options.execution_filter });
    }
    const teamName = wx.getStorageSync('erp_team_name') || '电子装配一组';
    this.setData({ teamName });
  },

  onShow() {
    this.load();
    this.startLiveTimer();
  },

  onHide() {
    this.stopLiveTimer();
  },

  onUnload() {
    this.stopLiveTimer();
    clearTimeout(this.searchTimer);
    this.requestSequence = (this.requestSequence || 0) + 1;
  },

  onPullDownRefresh() {
    this.load().finally(() => wx.stopPullDownRefresh());
  },

  onReachBottom() {
    if (!this.data.loading && !this.data.loadingMore && this.data.rows.length < this.data.total) {
      this.load(true);
    }
  },

  load(append = false) {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({
        loading: false,
        loaded: false,
        rows: [],
        filteredRows: [],
        stats: { total: '—', running: '—', waiting: '—', completed: '—' },
      });
      wx.showToast({ title: '请先登录统一账号', icon: 'none' });
      return Promise.resolve();
    }

    const sequence = (this.requestSequence = (this.requestSequence || 0) + 1);
    const page = append ? this.data.page + 1 : 1;

    this.setData(
      append
        ? { loadingMore: true }
        : {
            loading: true,
            loaded: false,
            rows: [],
            filteredRows: [],
            stats: { total: '—', running: '—', waiting: '—', completed: '—' },
          }
    );

    const now = Date.now();
    return production
      .myTasks({
        page,
        per_page: 20,
        keyword: this.data.keyword.trim(),
        execution_filter: this.data.active,
        include_stats: 1,
      })
      .then((response) => {
        if (sequence !== this.requestSequence) return;
        const existing = append ? this.data.rows : [];
        const ids = new Set(existing.map((row) => row.id));
        const newRows = (response.data || [])
          .filter((row) => !ids.has(row.id))
          .map((row) => enrichTaskView(row, now));
        const rows = existing.concat(newRows);
        const stats = response.stats || {};

        this.setData({
          rows,
          filteredRows: rows,
          page,
          total: response.total || 0,
          stats: {
            total: stats.today_total !== undefined ? stats.today_total : (stats.total || 0),
            running: stats.running || 0,
            waiting: stats.waiting || 0,
            completed: stats.completed_today !== undefined ? stats.completed_today : (stats.completed || 0),
          },
          loaded: true,
          loading: false,
          loadingMore: false,
          syncedAt: new Date().toLocaleTimeString(),
        });
      })
      .catch((error) => {
        if (sequence !== this.requestSequence) return;
        this.setData({ loading: false, loadingMore: false, loaded: append && this.data.loaded });
        wx.showToast({ title: error.message || '加载任务失败', icon: 'none' });
      });
  },

  setTab(event) {
    clearTimeout(this.searchTimer);
    const value = event.currentTarget.dataset.value;
    if (value === this.data.active) return;
    this.setData({ active: value });
    this.load();
  },

  onSearch(event) {
    this.setData({ keyword: event.detail.value || '' });
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.load(), 300);
  },

  clearSearch() {
    this.setData({ keyword: '' });
    clearTimeout(this.searchTimer);
    this.load();
  },

  openTask(event) {
    const id = event.currentTarget.dataset.id;
    if (!id) return;
    wx.navigateTo({
      url: `/pages/production/task-detail/index?id=${id}`,
    });
  },

  scan() {
    wx.scanCode({
      success: (result) => {
        const code = (result.result || '').trim();
        if (!code) return;
        wx.navigateTo({
          url: `/pages/production/queue/index?type=trace&keyword=${encodeURIComponent(code)}`,
        });
      },
      fail: (err) => {
        if (err && err.errMsg && !err.errMsg.includes('cancel')) {
          wx.showToast({ title: '扫码识别失败', icon: 'none' });
        }
      },
    });
  },

  startLiveTimer() {
    this.stopLiveTimer();
    this.timerInterval = setInterval(() => {
      const rows = this.data.filteredRows || [];
      let hasRunning = false;
      const now = Date.now();
      const updates = {};

      rows.forEach((row, index) => {
        if (row.targetStatus === 'IN_PROGRESS' && row._startedAt) {
          hasRunning = true;
          const text = '加工中 ' + formatElapsed(row._startedAt, now);
          if (text !== row.statusTagText) {
            updates[`filteredRows[${index}].statusTagText`] = text;
          }
        }
      });

      if (hasRunning && Object.keys(updates).length > 0) {
        this.setData(updates);
      }
    }, 1000);
  },

  stopLiveTimer() {
    if (this.timerInterval) {
      clearInterval(this.timerInterval);
      this.timerInterval = null;
    }
  },
});
