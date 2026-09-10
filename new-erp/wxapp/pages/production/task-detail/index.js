const production = require('../../../services/production');

const STATUS_CONFIG = {
  WAIT_CLAIM: { label: '待接单', theme: 'gray', color: '#8f959e', bg: '#f2f3f5' },
  CLAIMED: { label: '已接单', theme: 'blue', color: '#3370ff', bg: '#eef2f8' },
  WAIT_MATERIAL: { label: '待齐套', theme: 'amber', color: '#ff7d00', bg: '#fff7e8' },
  WAIT_HANDOVER: { label: '待交接', theme: 'purple', color: '#722ed1', bg: '#f9f0ff' },
  READY: { label: '待开工', theme: 'blue', color: '#165dff', bg: '#e8f3ff' },
  IN_PROGRESS: { label: '加工中', theme: 'red', color: '#d81e06', bg: '#feeceb' },
  PAUSED: { label: '已暂停', theme: 'amber', color: '#ff7d00', bg: '#fff7e8' },
  WAIT_QUALITY: { label: '待质检', theme: 'cyan', color: '#00b2b6', bg: '#e8fcfc' },
  WAIT_WAREHOUSE: { label: '待入库', theme: 'cyan', color: '#14c9c9', bg: '#e6fffa' },
  REWORK: { label: '返工', theme: 'red', color: '#f53f3f', bg: '#ffece8' },
  COMPLETED: { label: '已完成', theme: 'green', color: '#00b42a', bg: '#e8ffea' },
  CANCELLED: { label: '已取消', theme: 'gray', color: '#86909c', bg: '#f2f3f5' },
};

const QUALITY_LABELS = {
  required: '必须质检',
  first_article: '首件检验 (FAI)',
  sample: '抽样巡检',
  free: '免检转序',
  none: '免检转序',
  full: '全数检验',
};

const OUTPUT_LABELS = {
  flow_only: '纯工序流转（不入库）',
  warehouse_optional: '可直接交接或入库',
  warehouse_required: '必须入库后再领用',
  direct_handover: '直接交接下一工序',
  warehouse: '工序完工入库',
};

const SUPPLY_MODE_LABELS = {
  workstation_stock: { text: '工位常备料', tagClass: 'mode-stock' },
  dedicated_delivery: { text: '生产配送', tagClass: 'mode-delivery' },
  handover: { text: '工序交接', tagClass: 'mode-handover' },
};

const OUTPUT_STATUS_LABELS = {
  CREATED: '已登记', WAIT_QUALITY: '待质检', QUALITY_FAILED: '质检不合格',
  WAIT_COMPLETION: '待提交完工', PENDING_COMPLETION_REVIEW: '完工待审核',
  WAIT_WAREHOUSE: '待入库', WAREHOUSED: '已入库', HANDED_OVER: '已交接',
};

function pad(num) {
  return String(num).padStart(2, '0');
}

function formatDuration(seconds) {
  const s = Math.max(0, Math.floor(seconds || 0));
  const hrs = Math.floor(s / 3600);
  const mins = Math.floor((s % 3600) / 60);
  const secs = s % 60;
  return `${pad(hrs)}:${pad(mins)}:${pad(secs)}`;
}

function stamp(value) {
  return value ? String(value).replace('T', ' ').slice(0, 19) : '-';
}

function stampClock(value) {
  if (!value) return '-';
  const str = String(value).replace('T', ' ');
  return str.length >= 16 ? str.slice(11, 16) : str;
}

function targetView(row) {
  const conf = STATUS_CONFIG[row.status] || { label: row.status, theme: 'gray', color: '#8f959e', bg: '#f2f3f5' };
  const laborMinutes = Number(row.actual_labor_minutes || 0);

  let initialElapsedSec = Math.round(laborMinutes * 60);
  if (row.status === 'IN_PROGRESS' && row.started_at) {
    const startTs = new Date(row.started_at).getTime();
    if (Number.isFinite(startTs) && startTs > 0) {
      const diffSec = Math.floor((Date.now() - startTs) / 1000);
      if (diffSec > 0) {
        initialElapsedSec = Math.max(initialElapsedSec, diffSec);
      }
    }
  }

  const hasClaimed = Boolean(row.claimed_at) || row.status !== 'WAIT_CLAIM';
  const hasKitted = !row.kitting_required || Boolean(row.kitting_confirmed_at) || ['READY', 'IN_PROGRESS', 'PAUSED', 'WAIT_QUALITY', 'WAIT_WAREHOUSE', 'COMPLETED'].includes(row.status);
  const isStarted = Boolean(row.started_at) || ['IN_PROGRESS', 'PAUSED', 'WAIT_QUALITY', 'WAIT_WAREHOUSE', 'COMPLETED'].includes(row.status);
  const isCompleted = row.status === 'COMPLETED' || Boolean(row.completed_at);

  return Object.assign({}, row, {
    statusConfig: conf,
    statusLabel: row.status_label || conf.label || '状态异常，请刷新',
    claimedText: stamp(row.claimed_at),
    kittingText: stamp(row.kitting_confirmed_at),
    startedText: stamp(row.started_at),
    completedText: stamp(row.completed_at),
    claimedClock: stampClock(row.claimed_at),
    kittingClock: stampClock(row.kitting_confirmed_at),
    startedClock: stampClock(row.started_at),
    completedClock: stampClock(row.completed_at),
    laborText: `${laborMinutes.toFixed(1)}分`,
    elapsedSeconds: initialElapsedSec,
    timerDisplay: formatDuration(initialElapsedSec),
    readyForCompletion: row.target_type !== 'quantity_operation' || Number(row.remaining_base_qty || 0) <= 0,
    qualityLabel: QUALITY_LABELS[row.quality_mode_snapshot] || row.quality_mode_snapshot || '未配置',
    outputLabel: OUTPUT_LABELS[row.output_mode_snapshot] || row.output_mode_snapshot || '未配置',
    outputStatusLabel: row.output_record ? (OUTPUT_STATUS_LABELS[row.output_record.status] || row.output_record.status) : '',
    allMaterialsReady: null,
    pipeline: {
      hasClaimed,
      hasKitted,
      isStarted,
      isCompleted,
      activeStep: isCompleted ? 4 : (isStarted ? 3 : (hasKitted ? 2 : 1)),
    },
  });
}

Page({
  data: {
    id: 0,
    loading: true,
    busy: false,
    task: null,
    targets: [],
    materials: {},
    userId: 0,
    userName: '',
    workersList: [],
    totalWorkersCount: 1,
    showCollabModal: false,
    collabSearchKeyword: '',
    selectedCollabIds: [],
    collabCandidates: [],
  },

  timerId: null,

  onLoad(options) {
    const user = wx.getStorageSync('erp_user') || {};
    this.setData({
      id: Number(options.id || 0),
      userId: Number(user.legacy_id || 0),
      userName: user.nickname || user.name || user.real_name || user.username || '',
    });
  },

  onShow() {
    if (this.data.id) {
      this.load();
    }
  },

  onHide() {
    this.stopTimer();
  },

  onUnload() {
    this.stopTimer();
  },

  onPullDownRefresh() {
    this.load().finally(() => {
      wx.stopPullDownRefresh();
    });
  },

  stopTimer() {
    if (this.timerId) {
      clearInterval(this.timerId);
      this.timerId = null;
    }
  },

  startTimer() {
    this.stopTimer();
    const hasRunning = this.data.targets.some((t) => t.status === 'IN_PROGRESS');
    if (!hasRunning) return;

    this.timerId = setInterval(() => {
      let changed = false;
      const updated = this.data.targets.map((t) => {
        if (t.status === 'IN_PROGRESS') {
          changed = true;
          const nextSec = (t.elapsedSeconds || 0) + 1;
          return Object.assign({}, t, {
            elapsedSeconds: nextSec,
            timerDisplay: formatDuration(nextSec),
          });
        }
        return t;
      });
      if (changed) {
        this.setData({ targets: updated });
      }
    }, 1000);
  },

  load() {
    if (!wx.getStorageSync('erp_token')) {
      this.setData({ loading: false, task: null });
      wx.showToast({ title: '请先登录统一账号', icon: 'none' });
      return Promise.resolve();
    }
    this.setData({ loading: true });
    return production.task(this.data.id).then((response) => {
      const task = response.data || {};
      const conf = STATUS_CONFIG[task.status] || { label: task.status, theme: 'gray', color: '#8f959e', bg: '#f2f3f5' };
      task.statusConfig = conf;
      task.statusLabel = conf.label;

      const item = (task.work_order && task.work_order.output_item) || {};
      task.productName = item.item_name || item.name || '未指定产品';
      task.productSpec = item.spec || item.specification || '-';
      task.productCode = item.item_code || '';
      task.workOrderNo = (task.work_order && task.work_order.work_order_no) || '-';
      task.claimedText = stamp(task.claimed_at);

      const targets = (task.target_details || []).map(targetView);
      this.buildWorkersList(task, targets);

      this.setData({ task, targets, loading: false });
      this.loadMaterials();
      this.startTimer();
    }).catch((error) => {
      this.setData({ loading: false });
      wx.showToast({ title: error.message || '加载任务失败', icon: 'none' });
    });
  },

  buildWorkersList(task, targets) {
    const list = [];
    const sessions = task.labor_sessions || task.laborSessions || [];
    const laborFor = employeeId => sessions.filter(s => Number(s.employee_legacy_id) === Number(employeeId))
      .reduce((total, session) => total + Number(session.actual_labor_minutes || 0), 0);
    const owner = task.assignee_user || {};
    const ownerId = Number(task.assignee_user_legacy_id || this.data.userId || 0);
    const ownerName = owner.display_name || (ownerId === this.data.userId ? this.data.userName : '') || `负责人 #${ownerId}`;

    // 1. Primary Owner
    list.push({
      id: ownerId,
      name: ownerName,
      avatar: ownerName.slice(0, 1),
      isOwner: true,
      roleTag: '责任作业员',
      roleClass: 'owner',
      meta: `人员ID: ${ownerId || '-'} · ${owner.department_name || '未配置部门'}`,
      laborText: `${laborFor(ownerId).toFixed(1)} 分钟`,
    });

    // 2. Active Collaborators
    const activeCollabs = (task.collaborators || []).filter((c) => !c.left_at && c.role !== 'owner'
      && Number(c.employee_legacy_id) !== ownerId);
    activeCollabs.forEach((c) => {
      const employee = c.employee || {};
      const name = employee.display_name || `协同人员 #${c.employee_legacy_id}`;
      const joinedClock = stampClock(c.joined_at);

      list.push({
        id: c.employee_legacy_id,
        name,
        avatar: name[0],
        isOwner: false,
        roleTag: '协同作业',
        roleClass: 'collab',
        meta: `人员ID: ${c.employee_legacy_id} · ${employee.department_name || '未配置部门'}${joinedClock !== '-' ? ` · ${joinedClock}加入` : ''}`,
        laborText: `${laborFor(c.employee_legacy_id).toFixed(1)} 分钟`,
      });
    });

    this.setData({
      workersList: list,
      totalWorkersCount: list.length,
    });
  },

  loadMaterials() {
    this.data.targets.forEach((target) => {
      if (!target.kitting_required) return;
      production.kittingRequirements(this.data.id, target.target_type, target.target_id).then((response) => {
        const rows = (response.data || []).map((row) => {
          const modeKey = (row.source_facts && row.source_facts.supply_mode) || 'workstation_stock';
          const modeInfo = SUPPLY_MODE_LABELS[modeKey] || { text: '正式来源', tagClass: 'mode-other' };
          const shortage = Number(row.shortage_base_qty || 0);
          return Object.assign({}, row, {
            modeText: modeInfo.text,
            modeTagClass: modeInfo.tagClass,
            isShortage: shortage > 0,
            shortageText: shortage > 0 ? `缺 ${shortage}` : '已满足',
          });
        });
        const allReady = rows.every(row => !row.isShortage);
        this.setData({
          [`materials.${target.target_id}`]: rows,
          targets: this.data.targets.map(row => row.target_id === target.target_id
            ? Object.assign({}, row, { allMaterialsReady: allReady,
              materialStatusText: rows.length ? (allReady ? '全部满足' : `缺料 ${rows.filter(item => item.isShortage).length} 项`) : '无物料需求' })
            : row),
        });
      }).catch(() => null);
    });
  },

  claim() {
    this.run(() => production.claimTask(this.data.id, this.data.task.business_version), '接单成功');
  },

  confirmKitting(event) {
    const t = this.findTarget(event);
    if (!t) return;
    const workstationRows = (this.data.materials[t.target_id] || []).filter(
      (row) => row.source_facts && row.source_facts.supply_mode === 'workstation_stock'
    );
    this.collectWorkstationStock(workstationRows, 0, []).then((confirmations) => {
      this.run(() => production.confirmKitting(this.data.id, t.target_type, t.target_id, {
        expected_version: t.business_version,
        workstation_stock_confirmations: confirmations,
      }), '齐套确认成功');
    }).catch(() => null);
  },

  collectWorkstationStock(rows, index, result) {
    if (index >= rows.length) return Promise.resolve(result);
    const row = rows[index];
    return new Promise((resolve, reject) => {
      wx.showModal({
        title: `核对工位常备料 (${index + 1}/${rows.length})`,
        content: `组件：${row.component_item_name}\n需求：${row.required_base_qty}\n请输入现场实盘可用数量：`,
        editable: true,
        placeholderText: '现场可用数量',
        success: (modal) => {
          if (!modal.confirm) return reject(new Error('cancelled'));
          const quantity = Number(modal.content);
          if (!Number.isFinite(quantity) || quantity < Number(row.required_base_qty)) {
            wx.showToast({ title: '现场数量不足，无法确认齐套', icon: 'none' });
            return reject(new Error('insufficient'));
          }
          resolve(this.collectWorkstationStock(rows, index + 1, result.concat([{
            requirement_id: row.id,
            onsite_available_base_qty: quantity,
          }])));
        },
        fail: reject,
      });
    });
  },

  start(event) {
    const t = this.findTarget(event);
    if (!t) return;
    this.run(() => production.start(this.data.id, t.target_type, t.target_id, {
      expected_version: t.business_version,
    }), '已开始加工');
  },

  pause(event) {
    const t = this.findTarget(event);
    if (!t) return;
    this.run(() => production.pause(this.data.id, t.target_type, t.target_id, {
      expected_version: t.business_version,
    }), '已暂停加工');
  },

  resume(event) {
    const t = this.findTarget(event);
    if (!t) return;
    this.run(() => production.resume(this.data.id, t.target_type, t.target_id, {
      expected_version: t.business_version,
    }), '已继续加工');
  },

  complete(event) {
    const t = this.findTarget(event);
    if (!t) return;
    const payload = { expected_version: t.business_version, disposition: 'direct_handover' };
    if (t.target_type === 'quantity_operation' && !t.readyForCompletion) {
      return wx.showToast({ title: '请先完成剩余数量报工', icon: 'none' });
    }
    wx.showModal({
      title: '确认完成工序',
      content: '完成后将生成正式工序产出并推进后续交接或质检。',
      confirmColor: '#d81e06',
      success: (result) => {
        if (result.confirm) {
          this.run(() => production.complete(this.data.id, t.target_type, t.target_id, payload), '工序已完成');
        }
      },
    });
  },

  openReport(event) {
    const t = this.findTarget(event);
    if (!t || t.target_type !== 'quantity_operation') return;
    wx.navigateTo({
      url: `/pages/production/report/index?taskId=${this.data.id}&targetType=${t.target_type}&targetId=${t.target_id}`,
    });
  },

  openCompletion() {
    const workOrderId = Number(this.data.task && this.data.task.work_order && this.data.task.work_order.id);
    if (!workOrderId) return wx.showToast({ title: '无法定位当前工单', icon: 'none' });
    wx.navigateTo({ url: `/pages/production/completion/index?workOrderId=${workOrderId}` });
  },

  copyText(event) {
    const text = event.currentTarget.dataset.text;
    if (!text || text === '-') return;
    wx.setClipboardData({
      data: String(text),
      success: () => wx.showToast({ title: '已复制', icon: 'success' }),
    });
  },

  openHandover() {
    wx.navigateTo({ url: '/pages/production/queue/index?type=handover' });
  },

  openOutputAction(event) {
    const target = this.findTarget(event);
    const output = target && target.output_record;
    if (!output) return wx.showToast({ title: '产出记录尚未生成', icon: 'none' });
    const type = output.allowed_actions && output.allowed_actions.quality_inspect ? 'outputs_quality' : 'outputs_warehouse';
    wx.navigateTo({ url: `/pages/production/queue/index?type=${type}` });
  },

  openMaterialActions(event) {
    const target = this.findTarget(event);
    if (!target) return;
    wx.navigateTo({
      url: `/pages/production/material-actions/index?taskId=${this.data.id}&targetType=${target.target_type}&targetId=${target.target_id}`,
    });
  },

  findTarget(event) {
    const targetId = Number(event.currentTarget.dataset.id || (this.data.targets[0] && this.data.targets[0].target_id) || 0);
    return this.data.targets.find((row) => row.target_id === targetId) || this.data.targets[0];
  },

  // Collaborator Modal Methods
  openAddCollaborator() {
    if (!this.data.task) return;
    if (Number(this.data.task.assignee_user_legacy_id) !== Number(this.data.userId)) {
      return wx.showToast({ title: '只有任务负责人可添加协同人员', icon: 'none' });
    }
    if (!(this.data.task.work_order && this.data.task.work_order.collaboration_enabled)) {
      return wx.showToast({ title: '该工单未开启协同生产', icon: 'none' });
    }
    if (this.data.task.status === 'WAIT_CLAIM') {
      return wx.showToast({ title: '任务尚未接单，不能添加协同', icon: 'none' });
    }
    production.collaborationCandidates({ page: 1, per_page: 100 }).then((response) => {
      const rows = response.data || [];
      this.allCollabCandidates = rows.filter(row => Number(row.user_id) !== Number(this.data.userId)).map(row => ({
        id: Number(row.user_id), name: row.display_name || `人员 #${row.user_id}`,
        empNo: String(row.user_id), dept: row.department_name || '未配置部门',
      }));
      this.setData({ showCollabModal: true, collabSearchKeyword: '', selectedCollabIds: [],
        collabCandidates: this.filterCandidates('') });
    }).catch(error => wx.showToast({ title: error.message || '协同人员加载失败', icon: 'none' }));
  },

  closeCollabModal() {
    this.setData({ showCollabModal: false, selectedCollabIds: [] });
  },

  filterCandidates(keyword) {
    const existingIds = (this.data.workersList || []).map((w) => Number(w.id));
    const kw = (keyword || '').trim().toLowerCase();
    return (this.allCollabCandidates || []).filter((m) =>
      !kw || m.name.toLowerCase().includes(kw) || m.empNo.toLowerCase().includes(kw)
    ).map((m) => Object.assign({}, m, {
      alreadyJoined: existingIds.includes(Number(m.id)),
    }));
  },

  onCollabSearch(event) {
    const keyword = typeof event.detail === 'string'
      ? event.detail
      : ((event.detail && event.detail.value) || '');
    this.setData({
      collabSearchKeyword: keyword,
      collabCandidates: this.filterCandidates(keyword),
    });
  },

  onCollabClear() {
    this.setData({
      collabSearchKeyword: '',
      collabCandidates: this.filterCandidates(''),
    });
  },

  onCheckboxChange(event) {
    const rawList = event.detail || [];
    const selected = rawList.map(Number);
    this.setData({ selectedCollabIds: selected });
  },

  toggleCollabSelect(event) {
    const id = Number(event.currentTarget.dataset.id);
    const candidate = this.data.collabCandidates.find((m) => m.id === id);
    if (candidate && candidate.alreadyJoined) {
      return wx.showToast({ title: '该成员已在协同中', icon: 'none' });
    }
    const current = this.data.selectedCollabIds;
    const exists = current.includes(id);
    const next = exists ? current.filter((x) => x !== id) : current.concat([id]);
    this.setData({ selectedCollabIds: next });
  },

  submitAddCollaborators() {
    const ids = this.data.selectedCollabIds;
    if (!ids || ids.length === 0) {
      return wx.showToast({ title: '请至少勾选一位协同人员', icon: 'none' });
    }
    this.setData({ busy: true });
    production.addCollaborators(this.data.id, {
      expected_version: this.data.task.business_version,
      employee_legacy_ids: ids,
    }).then(() => {
      wx.showToast({ title: `已成功添加 ${ids.length} 位协同人员`, icon: 'success' });
      this.closeCollabModal();
      return this.load();
    }).catch((err) => {
      wx.showToast({ title: err.message || '协同人员添加失败', icon: 'none' });
      this.closeCollabModal();
      return this.load();
    }).finally(() => {
      this.setData({ busy: false });
    });
  },

  run(factory, message) {
    if (this.data.busy) return;
    this.setData({ busy: true });
    factory().then(() => {
      wx.showToast({ title: message, icon: 'success' });
      return this.load();
    }).catch((error) => {
      wx.showToast({ title: error.message || '操作失败', icon: 'none', duration: 2500 });
      return this.load();
    }).finally(() => {
      this.setData({ busy: false });
    });
  },
});
