const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const pad = n => (n < 10 ? '0' : '') + n;
function clock(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`;
}
function minutesText(m) {
  const v = Number(m || 0);
  return v >= 60 ? `${Math.floor(v / 60)}时${Math.round(v % 60)}分` : `${Math.round(v)}分`;
}
function buildSpecText(obj) {
  if (!obj) return '';
  if (obj.specification) return obj.specification;
  const parts = [obj.item_spec || obj.component_item_spec, obj.material_grade, obj.cut_spec].filter(Boolean);
  if (parts.length) return parts.join(' · ');
  if (obj.sku_name && obj.sku_name !== obj.product_name && obj.sku_name !== obj.item_name) return obj.sku_name;
  return obj.sku_name || '';
}

Page({
  data: {
    id: 0, lane: '', task: null, loading: true, busy: false, error: '', selected: {}, selectedCount: 0, selectedCompletionCount: 0, clockText: '00:00:00',
    shareText: '', isImage: {}, panel: '', rejectCurrent: false, cutQty: 1, prepQty: 1, runPick: 0,
    reject: { mode: 'return', returnId: 0, tags: {}, reason: '', media: [] }, uploading: false,
    peopleKeyword: '', peopleRows: [], picked: [], shareMode: 'labor', ownerPercent: '', showMaterials: false, showSemiMaterials: false,
    completionLocatorOpen: false,
    returnForm: { types: [], type: 'surplus', rows: [], picked: {}, qty: {}, reason: '', hint: '',
      planActions: [], planNeedsReview: false, planReviewers: '', planAction: '', planMaterial: 0, planStart: 0, planMedia: [], hasMade: false },
  },
  onLoad(options) { this.setData({ id: Number(options.id || 0), lane: options.lane === 'rework' ? 'rework' : (options.lane === 'normal' ? 'normal' : '') }); this.load(); },
  onShow() { this.hidden = false; if (view.resumingScan(this) || this.mediaReturning) { this.mediaReturning = false; this.startClock(); return; } if (this.data.task) this.load(true); },
  onHide() { this.hidden = true; this.stopClock(); if (this.mediaActive || this._warehouseScanActive || this._warehouseScanReturning) this.mediaReturning = true; else this.closeCompletionLocator(); },
  mediaActivity(event) { this.mediaActive = event.detail.active; },
  mediaBusy(event) { this.setData({ uploading: event.detail.busy }); },
  rejectMediaChanged(event) { this.setData({ 'reject.media': event.detail.value }); },
  planMediaChanged(event) { this.setData({ 'returnForm.planMedia': event.detail.value }); },
  onUnload() { this.completionJob = null; this.stopClock(); this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load(keepSelection) {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.completionJob = null;
    this.setData({ loading: true, error: '', completionLocatorOpen: false });
    return production.task(this.data.id, this.data.lane).then(response => {
      if (sequence === this.sequence) this.apply(response.data || response, keepSelection);
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  apply(task, keepSelection) {
    const selected = {};
    const old = keepSelection ? this.data.selected : {};
    (task.units || []).forEach(u => { if (u.selectable && old[u.id]) selected[u.id] = true; });
    const me = Number((wx.getStorageSync('erp_user') || {}).legacy_id || 0);
    const owner = (task.participants || []).find(p => p.role === 'owner');
    const others = (task.participants || []).filter(p => p.role !== 'owner');
    const shareText = (task.participants || []).length > 1
      ? task.participants.map(p => p.employee_name + (task.share_mode === 'fixed' ? ` ${view.quantity(p.share_percent)}%` : ` ${minutesText(p.labor_minutes)}`)).join(' · ')
      : '';
    const isImage = {};
    (task.drawings || []).forEach(d => { isImage[d.id] = /^image\//.test(d.mime_type || '') || /\.(png|jpe?g|webp|gif)$/i.test(d.url || ''); });
    wx.setNavigationBarTitle({ title: task.title || task.operation_name || '工序作业' });
    const unitShort = task.unit_short || '台';
    const noun = task.unit_word === '台设备' || !task.unit_word ? '设备' : (task.work_order.item_name || '件');
    const wo = task.work_order || {};
    const parentWo = wo.parent_work_order ? Object.assign({}, wo.parent_work_order, {
      specText: buildSpecText(wo.parent_work_order),
      plannedText: view.quantity(wo.parent_work_order.planned_qty),
    }) : null;
    const childWos = (wo.child_work_orders || []).map(c => Object.assign({}, c, {
      specText: buildSpecText(c),
      plannedText: view.quantity(c.planned_qty),
      storedText: view.quantity(c.stored_qty),
    }));
    const materials = (task.materials || []).map((m, idx) => {
      const req = Number(m.required_qty || 0);
      const iss = Number(m.issued_qty || 0);
      const isShort = iss + 0.00001 < req;
      return Object.assign({}, m, {
        rowKey: m.id || `${m.component_item_code || 'm'}-${idx}`,
        specText: buildSpecText(m),
        unitQtyText: view.quantity(m.unit_qty),
        requiredText: view.quantity(req),
        issuedText: view.quantity(iss),
        isShort,
        shortText: view.quantity(Math.max(0, req - iss)),
      });
    });
    const matReady = materials.filter(m => !m.isShort).length;
    const materialSummary = { total: materials.length, ready: matReady, short: materials.length - matReady };

    const semiRows = (task.semi_components || []).map(r => Object.assign({}, r, {
      specText: buildSpecText(r),
      requiredText: view.quantity(r.required_qty), issuedText: view.quantity(r.issued_qty),
      availableText: view.quantity(r.available_qty), openText: view.quantity(r.open_qty), heldText: view.quantity(r.held_qty), gapText: view.quantity(r.gap_qty),
    }));
    const semiReady = semiRows.filter(r => !(Number(r.gap_qty || 0) > 0.00001)).length;
    const semiSummary = { total: semiRows.length, ready: semiReady, short: semiRows.length - semiReady };

    const matById = {};
    const matByItemId = {};
    materials.forEach(m => {
      if (m.id) matById[m.id] = m;
      if (m.component_item_id) matByItemId[m.component_item_id] = m;
    });
    const deliveries = (task.deliveries || []).map(d => {
      const tableRows = (d.lines || []).map(l => {
        const matched = matById[l.material_id] || matByItemId[l.component_item_id] || {};
        return {
          name: l.component_item_name || matched.component_item_name || '物料',
          code: l.component_item_code || matched.component_item_code || '',
          spec: buildSpecText(l) || matched.specText || '标准规格',
          qty: `${view.quantity(l.qty)} ${l.unit_name || matched.unit_name || ''}`.trim(),
        };
      });
      return Object.assign({}, d, { tableRows, lineCount: tableRows.length });
    });

    this.setData({
      lane: task.lane || 'normal',
      task: Object.assign({}, task, {
        unitShort, noun, isRework: task.lane === 'rework',
        work_order: Object.assign({}, wo, {
          specText: buildSpecText(wo),
          parent_work_order: parentWo,
          child_work_orders: childWos,
        }),
        materials,
        materialSummary,
        deliveries,
        orderChangedText: ((task.order_info && task.order_info.changed) || []).map(c => c.label).join('、'),
        semiRows,
        semiSummary,
        participants: (task.participants || []).map(p => Object.assign({}, p, { is_me: Number(p.employee_legacy_id) === me })),
        plannedText: view.quantity(task.progress.planned), doneText: view.quantity(task.progress.done),
        ownerName: owner ? owner.employee_name : '', collaboratorNames: others.map(p => p.employee_name).join('、'),
        dueText: String(task.work_order.planned_finish_date || '').slice(5, 10),
        selectableCount: (task.units || []).filter(u => u.selectable).length,
        // 下料工序自己没计过时：底部按「已备料」交下道；开始计时后按正常报合格。
        prepMode: Boolean(task.prep && task.allowed && task.allowed.prepare && !task.my.timing && !(Number(task.my.closed_minutes || 0) > 0)),
      }),
      selected, selectedCount: Object.keys(selected).length,
      selectedCompletionCount: (task.units || []).filter(u => selected[u.id] && u.needs_completion).length,
      shareText, isImage, loading: false,
      cutQty: task.cutting ? Math.max(1, Math.min(this.data.cutQty || 1, task.cutting.reportable_units || 1)) : 1,
      prepQty: Math.max(1, Math.min(this.data.prepQty || 1, (task.units || []).filter(u => u.selectable).length || 1)),
      runPick: task.cutting_runs && (task.cutting_runs.runs || []).some(r => r.id === this.data.runPick) ? this.data.runPick : 0,
    });
    this.startClock();
  },
  startClock() {
    this.stopClock();
    const task = this.data.task;
    if (!task) return;
    const base = Number(task.my.closed_minutes || 0) * 60;
    const started = task.my.timing && task.my.started_at ? new Date(task.my.started_at).getTime() : 0;
    const tick = () => this.setData({ clockText: clock(base + (started ? (Date.now() - started) / 1000 : 0)) });
    tick();
    if (started) this.timer = setInterval(tick, 1000);
  },
  stopClock() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },

  toggleUnit(event) {
    const id = Number(event.currentTarget.dataset.id);
    const unit = (this.data.task.units || []).find(u => u.id === id);
    if (!unit || !unit.selectable) return;
    const selected = Object.assign({}, this.data.selected);
    if (selected[id]) delete selected[id]; else selected[id] = true;
    this.setSelection(selected);
  },
  selectAll() {
    const units = (this.data.task.units || []).filter(u => u.selectable);
    const all = this.data.selectedCount === units.length;
    const selected = {};
    if (!all) units.forEach(u => { selected[u.id] = true; });
    this.setSelection(selected);
  },
  scan() {
    wx.scanCode({
      success: result => {
        const code = String(result.result || '').trim();
        const unit = (this.data.task.units || []).find(u => u.serial_no && (code === u.serial_no || code.endsWith(u.serial_no) || code.indexOf(u.serial_no) >= 0));
        if (!unit) { wx.showToast({ title: `该序列号不在本工序${this.data.task.isRework ? '返工' : ''}范围内`, icon: 'none' }); return; }
        if (!unit.selectable) { wx.showToast({ title: '该序列号正在等待报废审核', icon: 'none' }); return; }
        const selected = Object.assign({}, this.data.selected, { [unit.id]: true });
        this.setSelection(selected);
        wx.showToast({ title: `已选中 ${unit.serial_no}`, icon: 'none' });
      },
    });
  },
  setSelection(selected) {
    this.setData({ selected, selectedCount: Object.keys(selected).length,
      selectedCompletionCount: (this.data.task.units || []).filter(u => selected[u.id] && u.needs_completion).length });
  },
  selectedIds() { return Object.keys(this.data.selected).map(Number); },

  run(payload, okText) {
    if (this.data.busy) return Promise.resolve(false);
    this.completionJob = null;
    this.setData({ busy: true, completionLocatorOpen: false });
    return production.action(this.data.id, Object.assign({ lane: this.data.lane || 'normal' }, payload)).then(response => {
      wx.showToast({ title: response.message || okText || '已完成', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      if (response.data) this.apply(response.data); else this.load();
      return true;
    }).catch(error => {
      if (error && error.errorCode === 'material_not_received') {
        view.modal(this, {
          theme: 'warn',
          title: '工序物料待领用',
          infoMain: '当前工序尚有已齐套物料未领用',
          infoSub: view.errorText(error),
          confirmText: '立即领料',
          cancelText: '暂不领料',
          success: res => { if (res.confirm) this.receiveMaterials(); },
        });
      } else if (error && error.errorCode === 'cutting_not_settled') {
        view.modal(this, {
          theme: 'warn',
          title: '开料产出待登记',
          infoMain: '当前工单尚有开料批次未完成结算登记',
          infoSub: view.errorText(error),
          confirmText: '前往开料',
          cancelText: '稍后处理',
          success: res => { if (res.confirm) this.openCutting(); },
        });
      } else if (error && error.errorCode === 'material_not_signed') {
        view.modal(this, {
          theme: 'warn',
          title: '配送物料待签收',
          infoMain: '请先签收仓库配送物料后再继续操作',
          infoSub: view.errorText(error),
          showCancel: false,
        });
      } else {
        view.modal(this, { theme: 'warn', title: '操作失败', content: view.errorText(error), showCancel: false });
      }
      this.load(true);
      return false;
    }).finally(() => this.setData({ busy: false }));
  },
  receiveMaterials() {
    const task = this.data.task;
    if (!task || !task.work_order) return;
    production.receiveMaterials(task.work_order.id, task.id).then(response => {
      wx.showToast({ title: response.message || '已领料', icon: 'none', duration: 2500 });
      this.load(true);
    }).catch(error => view.modal(this, { theme: 'warn', title: '领料失败', content: view.errorText(error), showCancel: false }));
  },
  signDelivery(e) {
    const id = Number(e.currentTarget.dataset.id);
    const row = (this.data.task.deliveries || []).find(d => d.id === id);
    if (!row) return;
    view.modal(this, {
      title: '确认签收配送物料',
      infoMain: `配送单号：${row.delivery_no}（共 ${row.lineCount || (row.tableRows || []).length} 项物料）`,
      infoSub: '请对照下方物料明细表，核对实物名称、规格型号与数量无误后确认签收：',
      tableRows: row.tableRows || [],
      confirmText: '确认签收',
      success: res => { if (res.confirm) this.deliveryAction(id, 'sign', {}, '已签收'); },
    });
  },
  rejectDelivery(e) {
    const id = Number(e.currentTarget.dataset.id);
    const row = (this.data.task.deliveries || []).find(d => d.id === id);
    view.modal(this, {
      mode: 'input',
      theme: 'danger',
      title: '拒收配送物料',
      infoMain: row ? `配送单号：${row.delivery_no}（共 ${row.lineCount || (row.tableRows || []).length} 项物料）` : '拒收仓库配送物料',
      infoSub: '请核对下方配送物料明细，并选择或填写拒收原因：',
      tableRows: row ? (row.tableRows || []) : [],
      quickTagsLabel: '快捷选择拒收原因：',
      quickTags: ['数量不符', '规格型号不符', '外观磕碰受损', '发错物料'],
      placeholderText: '请填写拒收原因（如数量不符、规格不符、外观受损）',
      inputRequired: true,
      inputRequiredToast: '请填写拒收原因',
      confirmText: '确认拒收',
      success: res => {
        if (!res.confirm) return;
        this.deliveryAction(id, 'reject', { reason: res.content }, '已拒收，等待仓库处理');
      },
    });
  },
  deliveryAction(id, action, payload, okText) {
    production.deliveryAction(id, action, payload).then(response => {
      wx.showToast({ title: response.message || okText, icon: 'none', duration: 2500 });
      this.load(true);
    }).catch(error => { view.modal(this, { theme: 'warn', title: '操作失败', content: view.errorText(error), showCancel: false }); this.load(true); });
  },
  openMaterialReturn() {
    const task = this.data.task;
    if (!task || !task.work_order || this.data.busy) return;
    production.returnOptions(task.work_order.id, task.id).then(response => {
      const d = response.data || response;
      this.returnOptions = d.materials || [];
      this.setData({ panel: 'return', returnForm: { types: d.types || [], type: 'surplus', rows: [], picked: {}, qty: {}, reason: '', hint: '',
        planActions: d.plan_actions || [], planNeedsReview: Boolean(d.plan_needs_review), planReviewers: d.plan_reviewers || '',
        planAction: '', planMaterial: 0, planStart: 0, planMedia: [], hasMade: false } });
      this.refreshReturnRows();
    }).catch(error => view.modal(this, { theme: 'warn', title: '读取可退物料失败', content: view.errorText(error), showCancel: false }));
  },
  refreshReturnRows() {
    const type = this.data.returnForm.type;
    const hints = { surplus: '余料或领错退回：核准后冲减本工序已领数量。', defective: '来料或半成品不良退回：核准后冲减已领数量，需重新领料。', scrapped: '报废设备拆回可用物料：核准后入库，不冲减原工序已领数量。' };
    const rows = (this.returnOptions || []).map(m => {
      const serials = m.by_number ? (m.serials || []).filter(s => (s.kind === 'scrapped') === (type === 'scrapped')) : [];
      const limitQty = m.by_qty ? Number((m.limits || {})[type] || 0) : 0;
      return Object.assign({}, m, { specText: buildSpecText(m), serials, limitQty, limit: view.quantity(limitQty), showSerials: serials.length > 0, showQty: limitQty > 0 });
    }).filter(m => m.showSerials || m.showQty);
    this.setData({ 'returnForm.rows': rows, 'returnForm.picked': {}, 'returnForm.qty': {}, 'returnForm.hint': hints[type] || '',
      'returnForm.hasMade': type === 'defective' && rows.some(r => r.made), 'returnForm.planAction': '', 'returnForm.planMaterial': 0, 'returnForm.planStart': 0, 'returnForm.planMedia': [] });
  },
  pickPlanAction(event) {
    if (this.data.busy || this.data.uploading) return;
    const action = event.currentTarget.dataset.action;
    this.setData({ 'returnForm.planAction': this.data.returnForm.planAction === action ? '' : action, 'returnForm.planStart': 0, 'returnForm.planMaterial': 0 });
  },
  pickPlanStart(event) {
    this.setData({ 'returnForm.planMaterial': Number(event.currentTarget.dataset.material), 'returnForm.planStart': Number(event.currentTarget.dataset.seq) });
  },
  pickReturnType(event) {
    if (this.data.busy || this.data.uploading) return;
    this.setData({ 'returnForm.type': event.currentTarget.dataset.type });
    this.refreshReturnRows();
  },
  toggleReturnSerial(event) {
    const id = Number(event.currentTarget.dataset.id);
    const picked = Object.assign({}, this.data.returnForm.picked);
    if (picked[id]) delete picked[id]; else picked[id] = true;
    this.setData({ 'returnForm.picked': picked });
  },
  returnQtyInput(event) { this.setData({ [`returnForm.qty.${event.currentTarget.dataset.id}`]: event.detail.value }); },
  returnReasonInput(event) { this.setData({ 'returnForm.reason': event.detail.value }); },
  submitMaterialReturn() {
    const f = this.data.returnForm;
    const task = this.data.task;
    const lines = f.rows.map(row => ({
      material_id: row.material_id,
      serial_ids: row.serials.filter(s => f.picked[s.id]).map(s => s.id),
      qty: row.showQty ? Number(f.qty[row.material_id] || 0) : 0,
    })).filter(l => l.serial_ids.length || l.qty > 0);
    const over = f.rows.find(row => row.showQty && Number(f.qty[row.material_id] || 0) > row.limitQty + 0.00001);
    if (over) { wx.showToast({ title: `${over.component_item_name} 可退上限为 ${over.limit}`, icon: 'none' }); return; }
    if (!lines.length) { wx.showToast({ title: '请选择退回序列号或填写退料数量', icon: 'none' }); return; }
    if (!String(f.reason || '').trim()) { wx.showToast({ title: '请填写退料原因', icon: 'none' }); return; }
    let plan = null;
    if (f.type === 'defective' && f.planAction) {
      const notMade = lines.find(l => !(f.rows.find(r => r.material_id === l.material_id) || {}).made);
      if (notMade) { wx.showToast({ title: '指定处置方案时仅限退回自制半成品，外购件请分开提交', icon: 'none' }); return; }
      if (f.planAction === 'rework') {
        if (!f.planStart) { wx.showToast({ title: '请选择返工起始工序', icon: 'none' }); return; }
        if (lines.length !== 1 || lines[0].material_id !== f.planMaterial) { wx.showToast({ title: '返工处置单次仅限退回一种半成品并与所选工序一致', icon: 'none' }); return; }
      }
      if (f.planAction === 'scrap' && !f.planNeedsReview && !f.planMedia.some(item => item.type === 'image')) { wx.showToast({ title: '报废直接生效需至少上传 1 张现场照片', icon: 'none' }); return; }
      plan = { action: f.planAction, start_sequence: f.planAction === 'rework' ? f.planStart : null, media: f.planMedia };
    }
    if (this.data.busy) return;
    this.setData({ busy: true });
    production.createReturn(task.work_order.id, task.id, Object.assign({ return_type: f.type, reason: f.reason.trim(), lines }, plan ? { disposition_plan: plan } : {})).then(response => {
      wx.showToast({ title: response.message || '退料申请已提交', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      this.load(true);
    }).catch(error => view.modal(this, { theme: 'warn', title: '退料提交失败', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  claim() { this.run({ action: 'claim' }, '接单成功'); },
  switchLane() {
    const other = this.data.task && this.data.task.other_lane;
    if (!other || !other.count) return;
    this.setData({ lane: other.lane, selected: {}, selectedCount: 0, selectedCompletionCount: 0, panel: '' });
    this.load();
  },
  semiOrder(event) {
    const id = Number(event.currentTarget.dataset.id);
    const row = (this.data.task.semiRows || []).find(r => r.component_item_id === id);
    if (!row || !row.can_order || this.data.busy) return;
    view.modal(this, {
      mode: 'stepper',
      title: '下达半成品子工单',
      infoMain: `${row.component_item_name}${row.component_item_code ? ' · ' + row.component_item_code : ''}`,
      infoSub: row.specText ? `规格型号：${row.specText}` : '',
      stats: [
        { num: row.requiredText, label: '本单需用' },
        { num: `${row.availableText}/${row.openText}`, label: '可用/在制' },
        { num: row.gapText, label: '当前缺口', highlight: true },
      ],
      stepperLabel: `本次下达数量（上限 ${row.max_order_qty}）`,
      qty: row.max_order_qty,
      minQty: 1,
      maxQty: row.max_order_qty,
      confirmText: '确认下达子工单',
      success: res => {
        if (!res.confirm) return;
        this.run({ action: 'semi_order', component_item_id: id, qty: res.qty }, '半成品子工单已下达');
      },
    });
  },
  callPhone(event) { const phone = event.currentTarget.dataset.phone; if (phone) wx.makePhoneCall({ phoneNumber: String(phone) }); },
  scrollOrder() { wx.pageScrollTo({ selector: '.oi-anchor', duration: 200 }); },
  start() { this.run({ action: 'start' }, '开始计时'); },
  pause() { this.run({ action: 'pause' }, '已暂停'); },
  stopTiming(event) {
    const { id, name } = event.currentTarget.dataset;
    view.modal(this, {
      title: '停止人员计时',
      subtitle: '后续如需继续作业可重新开始计时',
      kvRows: [
        { k: '作业人员', v: name, red: true },
        { k: '当前工序', v: this.data.task.title || this.data.task.operation_name },
      ],
      confirmText: '确认停止计时',
      success: res => { if (res.confirm) this.run({ action: 'stop', employee_legacy_id: Number(id) }, '已停止计时'); },
    });
  },
  pass() {
    const ids = this.selectedIds();
    if (!ids.length) { wx.showToast({ title: '请先勾选或扫码选择' + this.data.task.noun, icon: 'none' }); return; }
    const task = this.data.task;
    if (task.completion && this.data.selectedCompletionCount) return this.chooseCompletion({ action: 'pass', unit_ids: ids });
    const next = task.completion ? null : task.progress.next_operation_name;
    const done = task.rework_of_work_order_no ? '返工完成并解冻回可用库存' : (task.shipment_repair ? '返修完成，待仓管返回成品位重新试水' : '工序完工待入库');
    view.modal(this, {
      mediaEnabled: true,
      title: task.isRework ? '确认返工合格' : '确认完工报工',
      subtitle: '请核对本次完工设备数量与流转去向',
      kvRows: [
        { k: '当前工序', v: `${task.operation_name || task.title}（${task.isRework ? '返工作业' : '正常作业'}）` },
        { k: '完工数量', v: `${ids.length} ${task.unitShort}（合格）`, red: true },
        { k: '流转去向', v: next ? `交接至${next}工序` : done },
      ],
      cancelText: '返回核对',
      confirmText: '确认提交',
      success: res => { if (res.confirm && !this.hidden && !this.data.loading && task === this.data.task) this.run({ action: 'pass', unit_ids: ids, media: res.media || [] }); },
    });
  },

  prepare() {
    const ids = this.selectedIds();
    if (!ids.length) { wx.showToast({ title: '请先勾选或扫码选择' + this.data.task.noun, icon: 'none' }); return; }
    const task = this.data.task;
    const runPick = this.data.runPick;
    const next = task.progress.next_operation_name;
    view.modal(this, {
      mode: 'input',
      mediaEnabled: true,
      title: '确认备料交接',
      infoMain: `本次备料：${ids.length} ${task.unitShort}`,
      infoSub: `流转去向：${task.completion ? '下一步选择完工流转去向' : (next ? '交接至' + next + '工序' : '完成本工序')}`,
      quickTagsLabel: '快捷备注（选填）：',
      quickTags: ['批次齐套', '板材余料切出', '按单正常备料'],
      placeholderText: '备料备注（选填，如批次说明）',
      confirmText: '确认备料',
      success: res => { if (res.confirm && !this.hidden && !this.data.loading && task === this.data.task) this.chooseCompletion(Object.assign({ action: 'prepare', unit_ids: ids, media: res.media || [], remark: String(res.content || '').trim() || null }, runPick ? { cutting_run_id: runPick } : {}), '已完成备料交接'); },
    });
  },

  changeCutQty(event) { this.setCutQty(this.data.cutQty + Number(event.currentTarget.dataset.delta || 0)); },
  cutQtyInput(event) { this.setCutQty(parseInt(event.detail.value, 10) || 1); },
  setCutQty(value) {
    const max = (this.data.task.cutting || {}).reportable_units || 1;
    this.setData({ cutQty: Math.max(1, Math.min(max, value)) });
  },
  changePrepQty(event) { this.setPrepQty(this.data.prepQty + Number(event.currentTarget.dataset.delta || 0)); },
  prepQtyInput(event) { this.setPrepQty(parseInt(event.detail.value, 10) || 1); },
  setPrepQty(value) { this.setData({ prepQty: Math.max(1, Math.min(this.data.task.selectableCount || 1, value)) }); },
  prepareCut() {
    const task = this.data.task;
    const qty = this.data.prepQty;
    const ids = (task.units || []).filter(u => u.selectable).slice(0, qty).map(u => u.id);
    if (!ids.length || ids.length < qty) { wx.showToast({ title: '当前工序可备料套数不足', icon: 'none' }); return; }
    const next = task.progress.next_operation_name;
    const runPick = this.data.runPick;
    view.modal(this, {
      mode: 'input',
      mediaEnabled: true,
      title: '确认备料交接',
      infoMain: `本次备料：${qty} 套`,
      infoSub: `流转去向：${task.completion ? '下一步选择完工流转去向' : (next ? '交接至' + next + '工序' : '完成本工序')}`,
      quickTagsLabel: '快捷备注（选填）：',
      quickTags: ['板材批次齐套', '集中开料领入', '按单正常备料'],
      placeholderText: '备料备注（选填，如板材批次）',
      confirmText: '确认备料',
      success: res => { if (res.confirm && !this.hidden && !this.data.loading && task === this.data.task) this.chooseCompletion(Object.assign({ action: 'prepare', unit_ids: ids, media: res.media || [], remark: String(res.content || '').trim() || null }, runPick ? { cutting_run_id: runPick } : {}), '已完成备料交接'); },
    });
  },
  openSharedCutting() { wx.navigateTo({ url: '/pages/production/cutting-run/index' }); },
  pickRun(event) { const id = Number(event.currentTarget.dataset.id); this.setData({ runPick: this.data.runPick === id ? 0 : id }); },
  passCut() {
    const task = this.data.task;
    const qty = this.data.cutQty;
    const ids = (task.units || []).filter(u => u.selectable).slice(0, qty).map(u => u.id);
    if (!ids.length || ids.length < qty) { wx.showToast({ title: '当前工序可报工套数不足', icon: 'none' }); return; }
    if (task.completion) return this.chooseCompletion({ action: 'pass', unit_ids: ids });
    const next = task.progress.next_operation_name;
    view.modal(this, {
      mediaEnabled: true,
      title: '确认下料完工',
      subtitle: '请核对本次下料完工套数与流转去向',
      kvRows: [
        { k: '当前工序', v: task.operation_name || '下料' },
        { k: '完工套数', v: `${qty} 套`, red: true },
        { k: '流转去向', v: next ? `交接至${next}工序` : '完成生产' },
      ],
      cancelText: '返回核对',
      confirmText: '确认提交',
      success: res => { if (res.confirm && !this.hidden && !this.data.loading && task === this.data.task) this.run({ action: 'pass', unit_ids: ids, media: res.media || [] }, '已完成下料报工'); },
    });
  },
  chooseCompletion(payload, okText) {
    if (this.hidden || this.data.busy || this.data.loading) return;
    const task = this.data.task;
    const completion = task.completion;
    const count = (task.units || []).filter(u => u.needs_completion && payload.unit_ids.indexOf(u.id) >= 0).length;
    if (!completion || !count) return this.run(payload, okText);
    const job = this.completionJob = { task, payload, okText, count,
      repairText: count < payload.unit_ids.length ? `；其余 ${payload.unit_ids.length - count} ${task.unitShort}按返修完成处理` : '' };
    if (!completion.next_operation_name) return this.setData({ completionLocatorOpen: true });
    const wo = task.work_order || {};
    const target = `${completion.target_work_order_no || wo.work_order_no || ''} · ${completion.next_operation_name}`;
    // 随单半成品最多交到主工单还缺的数量，多出的只能入库
    const remaining = completion.remaining_qty === null || completion.remaining_qty === undefined ? null : Number(completion.remaining_qty);
    const over = remaining !== null && count > remaining + 0.00001;
    const canHandoff = Boolean(completion.handoff_available) && !over;
    const overText = over ? `${target} 还需 ${view.quantity(remaining)} ${task.unitShort}，本次选了 ${count} ${task.unitShort}，超出的不能交接。请返回只选 ${view.quantity(remaining)} ${task.unitShort}交接，或全部入库。` : '';
    // 路线设为「直接交接」：有接收方就直接交，不用选；交不了（没有接收方、超出缺口）才入库
    if (completion.output_mode === 'flow_only') {
      view.modal(this, {
        mediaEnabled: true, media: payload.media || [],
        title: canHandoff ? '交接给下一道' : '本次入库',
        content: canHandoff ? `本次完工 ${count} ${task.unitShort}${job.repairText}，直接交接至 ${target}（系统自动记账）。` : (overText || completion.blocked_reason || '没有可交接的下一道，请入库。'),
        cancelText: '返回',
        confirmText: canHandoff ? '确认交接' : '选择库位',
        success: res => {
          if (!this.completionIsCurrent(job)) return;
          if (!res.confirm) return this.closeCompletionLocator();
          payload.media = res.media || [];
          if (!canHandoff) return this.setData({ completionLocatorOpen: true });
          this.run(Object.assign({}, payload, { completion_action: 'handoff', handoff_operation_id: completion.next_operation_id }), okText);
        },
      });
      return;
    }
    view.modal(this, {
      mode: 'completion', mediaEnabled: true, media: payload.media || [],
      title: '选择完工流转去向',
      infoMain: `本次完工：${count} ${task.unitShort}${job.repairText}`,
      infoSub: `工单：${wo.work_order_no || ''} · ${wo.product_name || wo.item_name || ''}`,
      handoffAvailable: canHandoff,
      handoffTitle: `直接交接至${completion.next_operation_name}`,
      handoffDesc: canHandoff
        ? `目标：${target}（人员直接交接，系统自动记账）${remaining !== null ? '，还需 ' + view.quantity(remaining) + ' ' + task.unitShort : ''}`
        : (overText || completion.blocked_reason || '请先执行完工入库'),
      storeTitle: '完工入库（入成品/半成品库）',
      storeDesc: '下一步选择目标仓库与库位后正式办理完工入库',
      handoffConfirmText: `确认交接 (${count}${task.unitShort})`,
      storeConfirmText: '下一步：选择入库库位',
      success: result => {
        if (!this.completionIsCurrent(job)) return;
        if (!result.confirm) {
          this.closeCompletionLocator();
          return;
        }
        payload.media = result.media || [];
        if (result.choice === 'store') {
          this.setData({ completionLocatorOpen: true });
          return;
        }
        this.run(Object.assign({}, payload, { completion_action: 'handoff', handoff_operation_id: completion.next_operation_id }), okText);
      },
    });
  },
  completionIsCurrent(job) { return Boolean(job && this.completionJob === job && this.data.task === job.task && !this.hidden && !this.data.busy && !this.data.loading); },
  completionLocationChosen(event) {
    const job = this.completionJob;
    if (!this.completionIsCurrent(job)) return;
    const location = event.detail || {};
    this.setData({ completionLocatorOpen: false });
    if (!location.location_id) return this.closeCompletionLocator();
    view.modal(this, {
      mediaEnabled: true, media: job.payload.media || [],
      title: '确认完工入库',
      subtitle: '请核对完工入库数量与目标库位',
      kvRows: [
        { k: '完工数量', v: `${job.count} ${job.task.unitShort}${job.repairText}`, red: true },
        { k: '目标仓库', v: location.warehouse_name || '-' },
        { k: '目标库位', v: location.location_name || '-' },
      ],
      confirmText: '确认入库',
      success: res => {
        if (!this.completionIsCurrent(job)) return;
        if (res.confirm) this.run(Object.assign({}, job.payload, { completion_action: 'store', completion_location_id: location.location_id, media: res.media || [] }), job.okText);
        else this.closeCompletionLocator();
      },
    });
  },
  closeCompletionLocator() { this.completionJob = null; this.setData({ completionLocatorOpen: false }); },
  openReject() {
    if (!this.selectedIds().length) { wx.showToast({ title: '请先勾选不合格的' + this.data.task.noun, icon: 'none' }); return; }
    const options = this.data.task.return_options || [];
    const current = options.find(o => o.is_current);
    this.setData({ panel: 'reject', rejectCurrent: Boolean(current && current.is_current),
      reject: { mode: current ? 'return' : 'scrap', returnId: current ? current.id : 0, tags: {}, reason: '', media: [] } });
  },
  pickReturn(event) {
    const id = Number(event.currentTarget.dataset.id);
    const option = (this.data.task.return_options || []).find(o => o.id === id);
    this.setData({ 'reject.mode': id ? 'return' : 'scrap', 'reject.returnId': id, rejectCurrent: Boolean(option && option.is_current) });
  },
  toggleTag(event) {
    const tag = event.currentTarget.dataset.tag;
    const tags = Object.assign({}, this.data.reject.tags);
    if (tags[tag]) delete tags[tag]; else tags[tag] = true;
    this.setData({ 'reject.tags': tags });
  },
  reasonInput(event) { this.setData({ 'reject.reason': event.detail.value }); },
  submitReject() {
    const r = this.data.reject;
    const ids = this.selectedIds();
    if (r.mode === 'return' && !r.returnId) { wx.showToast({ title: '请选择退回返工工序', icon: 'none' }); return; }
    const tags = Object.keys(r.tags);
    if (!tags.length && !r.reason.trim()) { wx.showToast({ title: '请选择不良原因标签或填写说明', icon: 'none' }); return; }
    const payload = { action: 'reject', unit_ids: ids, mode: r.mode, return_operation_id: r.mode === 'return' ? r.returnId : null,
      reason_tags: tags, reason: r.reason.trim() || null, media: r.media };
    if (r.mode === 'scrap') {
      const review = this.data.task.scrap_review || {};
      const unit = this.data.task.unitShort;
      if (!review.needs_review && !r.media.some(item => item.type === 'image')) { wx.showToast({ title: '报废直接生效需至少上传 1 张照片', icon: 'none' }); return; }
      view.modal(this, {
        theme: 'danger',
        title: review.needs_review ? '提交报废申请' : '确认报废',
        infoMain: `本次报废数量：${ids.length} ${unit}`,
        infoSub: review.needs_review
          ? `提交后待 ${review.reviewers} 审核通过后生效。是否确认提交？`
          : '提交后将直接生效报废并终止对应单件生产。是否确认？',
        confirmText: review.needs_review ? '提交申请' : '确认报废',
        success: res => { if (res.confirm) this.run(payload); },
      });
      return;
    }
    this.run(payload);
  },

  openPeople() {
    const task = this.data.task;
    const owner = (task.participants || []).find(p => p.role === 'owner');
    const picked = (task.participants || []).filter(p => p.role !== 'owner')
      .map(p => ({ id: p.employee_legacy_id, name: p.employee_name, percent: p.share_percent === null ? '' : view.quantity(p.share_percent) }));
    this.setData({ panel: 'people', picked, shareMode: task.share_mode || 'labor', ownerPercent: owner && owner.share_percent !== null ? view.quantity(owner.share_percent) : '', peopleKeyword: '', peopleRows: [] });
    this.searchPeople();
  },
  openAssign() { this.setData({ panel: 'assign', peopleKeyword: '', peopleRows: [] }); this.searchPeople(); },
  peopleInput(event) { this.setData({ peopleKeyword: event.detail.value }); },
  clearPeopleKeyword() { this.setData({ peopleKeyword: '' }); this.searchPeople(); },
  decoratePeopleRows(rawRows, pickedList) {
    const pickedIds = (pickedList || this.data.picked || []).map(p => Number(p.id));
    return (rawRows || []).map(u => Object.assign({}, u, {
      selected: pickedIds.includes(Number(u.id)),
      avatarText: String(u.name || u.username || '工')[0],
    }));
  },
  searchPeople() {
    const me = Number((wx.getStorageSync('erp_user') || {}).legacy_id || 0);
    const sequence = this.peopleSequence = (this.peopleSequence || 0) + 1;
    return production.people(this.data.id, { keyword: this.data.peopleKeyword }).then(response => {
      if (sequence !== this.peopleSequence) return;
      const rows = (response.data || []).filter(u => this.data.panel === 'assign' || u.id !== me);
      this.rawPeopleRows = rows;
      this.setData({ peopleRows: this.decoratePeopleRows(rows) });
    }).catch(error => wx.showToast({ title: view.errorText(error), icon: 'none' }));
  },
  togglePerson(event) {
    const id = Number(event.currentTarget.dataset.id);
    const name = event.currentTarget.dataset.name;
    const picked = this.data.picked.slice();
    const index = picked.findIndex(p => Number(p.id) === id);
    if (index >= 0) picked.splice(index, 1); else picked.push({ id, name, percent: '' });
    this.setData({
      picked,
      peopleRows: this.decoratePeopleRows(this.rawPeopleRows || this.data.peopleRows, picked),
    });
  },
  equalTaskShares() {
    const n = this.data.picked.length + 1;
    const each = Math.floor(100 / n);
    this.setData({
      shareMode: 'fixed',
      ownerPercent: String(100 - each * (n - 1)),
      picked: this.data.picked.map(p => Object.assign({}, p, { percent: String(each) })),
    });
  },
  pickShareMode(event) {
    const mode = event.currentTarget.dataset.mode;
    if (mode === 'fixed' && this.data.shareMode !== 'fixed') {
      this.equalTaskShares();
    } else this.setData({ shareMode: mode });
  },
  ownerPercentInput(event) { this.setData({ ownerPercent: event.detail.value }); },
  percentInput(event) { this.setData({ [`picked[${Number(event.currentTarget.dataset.index)}].percent`]: event.detail.value }); },
  savePeople() {
    const fixed = this.data.shareMode === 'fixed' && this.data.picked.length > 0;
    if (fixed) {
      const total = Number(this.data.ownerPercent || 0) + this.data.picked.reduce((s, p) => s + Number(p.percent || 0), 0);
      if (Math.abs(total - 100) > 0.01) { wx.showToast({ title: `分摊比例合计须为 100%，当前为 ${view.quantity(total)}%`, icon: 'none' }); return; }
    }
    this.run({ action: 'collaborators', collaborators: this.data.picked.map(p => ({ employee_legacy_id: p.id, share_percent: fixed ? Number(p.percent || 0) : null })),
      owner_share_percent: fixed ? Number(this.data.ownerPercent || 0) : null }, '协同人员配置已保存');
  },
  assignTo(event) {
    const id = Number(event.currentTarget.dataset.id);
    const name = event.currentTarget.dataset.name;
    view.modal(this, {
      title: '确认主管派工',
      kvRows: [
        { k: '派工工序', v: this.data.task.title || this.data.task.operation_name },
        { k: '指派人员', v: name, red: true },
      ],
      confirmText: '确认派工',
      success: res => { if (res.confirm) this.run({ action: 'assign', employee_legacy_id: id }, '派工成功'); },
    });
  },
  personTap(event) { return this.data.panel === 'assign' ? this.assignTo(event) : this.togglePerson(event); },
  closePanel() { if (!this.data.busy && !this.data.uploading) this.setData({ panel: '' }); },
  stop() {},

  openDrawing(event) {
    const drawing = (this.data.task.drawings || []).find(d => d.id === Number(event.currentTarget.dataset.id));
    if (!drawing) return;
    if (this.data.isImage[drawing.id]) {
      const urls = this.data.task.drawings.filter(d => this.data.isImage[d.id]).map(d => d.url);
      wx.previewImage({ urls, current: drawing.url });
      return;
    }
    wx.showLoading({ title: '打开图纸…', mask: true });
    wx.downloadFile({ url: drawing.url,
      success: res => {
        wx.hideLoading();
        if (res.statusCode !== 200) { wx.showToast({ title: '图纸下载失败', icon: 'none' }); return; }
        wx.openDocument({ filePath: res.tempFilePath, showMenu: true,
          fail: () => wx.setClipboardData({ data: drawing.url, success: () => wx.showToast({ title: '当前设备暂不支持直接预览该格式，图纸链接已复制', icon: 'none' }) }) });
      },
      fail: () => { wx.hideLoading(); wx.showToast({ title: '图纸下载失败', icon: 'none' }); } });
  },
  openCutting() {
    const task = this.data.task;
    if (!task || !task.cutting) return;
    if (!task.allowed.cutting) { wx.showToast({ title: '请先接单后再执行开料', icon: 'none' }); return; }
    wx.navigateTo({ url: `/pages/production/cutting/index?wo=${Number(task.work_order.id)}&op=${Number(task.id)}&name=${encodeURIComponent(task.operation_name || '')}` });
  },
  toggleMaterials() { this.setData({ showMaterials: !this.data.showMaterials }); },
  toggleSemiMaterials() { this.setData({ showSemiMaterials: !this.data.showSemiMaterials }); },
  openWorkOrder(event) {
    const targetId = event && event.currentTarget && event.currentTarget.dataset && event.currentTarget.dataset.id
      ? Number(event.currentTarget.dataset.id)
      : Number(this.data.task && this.data.task.work_order && this.data.task.work_order.id);
    if (!targetId) return;
    wx.navigateTo({ url: `/pages/production/work-order/index?id=${targetId}` });
  },
});
