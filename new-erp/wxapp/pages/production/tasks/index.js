const production = require('../../../services/production');
const packing = require('../../../services/packing');
const view = require('../../../utils/warehouse-page');

const PACK_STATUS = { READY: '待作业', WAITING: '等待前序', IN_PROGRESS: '作业中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' };
function packMinutes(m) { const v = Number(m || 0); return v >= 60 ? `${Math.floor(v / 60)}时${Math.round(v % 60)}分` : `${Math.round(v)}分`; }

// 发货作业（试水 / 打包 / 装车）和生产工序放在同一个「我的待做」「可接工序」里
function decoratePack(row, tab) {
  const contents = row.contents || [];
  const owner = (row.participants || []).find(p => p.role === 'OWNER');
  const my = row.my || {};
  const salesOrderNo = (row.order_info && row.order_info.sales_order_no) || row.sales_order_no || '';
  return {
    id: row.id,
    title: row.operation_name,
    statusLabel: tab === 'mine' && row.status === 'COMPLETED' ? '待出库' : PACK_STATUS[row.status] || row.status,
    timing: Boolean(my.timing),
    salesOrderNo,
    product: contents.map(c => c.product_name || c.item_name).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join('、'),
    specText: contents.map(c => c.specification || c.item_spec || c.sku_name).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join('、'),
    line: [row.shipment_no, row.package_no, row.order_info && row.order_info.customer_name].filter(Boolean).join(' · '),
    ownerName: owner ? owner.name : '',
    myText: packMinutes(my.closed_minutes),
    repair: (row.serial_results || []).filter(s => s.repair && s.repair.state !== 'returned').length,
    changed: Boolean(row.order_info && row.order_info.changed && row.order_info.changed.length),
  };
}

const TABS = [{ key: 'mine', label: '我的待做' }, { key: 'available', label: '可接工序' }, { key: 'done', label: '已完工' }, { key: 'progress', label: '订单进度' }];
const REVIEW_TAB = { key: 'review', label: '待审核' };

function tabsFor(counts, tab) {
  return (counts && counts.review > 0) || tab === 'review'
    ? [TABS[0], TABS[1], REVIEW_TAB, TABS[2], TABS[3]]
    : TABS;
}

// 订单进度：主工单、子工单拉平成一列，子工单标出上级工单号。
function progressWorkOrders(workOrders, parentNo = '', rows = []) {
  (workOrders || []).forEach(wo => {
    rows.push({
      id: wo.id,
      work_order_no: wo.work_order_no,
      name: wo.name,
      status_label: wo.status_label,
      planned_text: wo.planned_text,
      operation_progress: wo.operation_progress,
      parentNo,
      units: wo.units || [],
      operations: (wo.operations || []).map(op => Object.assign({}, op, {
        key: `${op.id}-${op.lane}`,
        timingText: (op.timing_names || []).join('、'),
        materialText: (op.materials || []).map(m => `${m.name} ${view.quantity(m.received_qty)}/${view.quantity(m.required_qty)}${m.unit_name || ''}`).join('；'),
      })),
    });
    progressWorkOrders(wo.children, wo.work_order_no, rows);
  });
  return rows;
}

function buildSpecText(obj) {
  if (!obj) return '';
  if (obj.specification) return obj.specification;
  const parts = [obj.item_spec || obj.component_item_spec, obj.material_grade, obj.cut_spec].filter(Boolean);
  if (parts.length) return parts.join(' · ');
  if (obj.sku_name && obj.sku_name !== obj.product_name && obj.sku_name !== obj.item_name) return obj.sku_name;
  return obj.sku_name || '';
}

function decorate(row, tab, canExecute) {
  const planned = Number(row.planned_qty || 0);
  const done = Number(row.done_qty || 0);
  const canClaim = Boolean(canExecute && row.allowed && row.allowed.claim);
  const parent = row.parent_work_order;
  const parentOrderId = String(row.parent_work_order_id || (parent && parent.id) || '');
  const isSub = Boolean(row.is_sub_work_order || parentOrderId || parent);
  const specText = buildSpecText(row);
  let opStatusLabel = row.work_status_label || '';
  let opStatusTheme = 'gray';
  if (Number(row.pending_repair_return_count || 0) > 0) {
    opStatusLabel = '待返回试水';
    opStatusTheme = 'amber';
  } else if (row.timing) {
    opStatusLabel = '计时中';
    opStatusTheme = 'amber';
  } else if (row.paused) {
    opStatusLabel = '已暂停';
    opStatusTheme = 'gray';
  } else if (tab === 'done' || row.status === 'done') {
    opStatusLabel = '已完工';
    opStatusTheme = 'green';
  } else if (tab === 'mine' || row.owner_name) {
    opStatusLabel = (row.work_status === 'in_progress' || done > 0) ? '作业中' : '待作业';
    opStatusTheme = 'blue';
  } else if (tab === 'available') {
    opStatusLabel = row.work_status_label || '可接';
    opStatusTheme = canClaim ? 'red' : 'gray';
  }
  return Object.assign({}, row, {
    key: row.key || `${row.id}-${row.lane || 'normal'}`,
    lane: row.lane || 'normal',
    title: row.title || row.operation_name,
    unitShort: row.unit_short || '台',
    isSub,
    parentOrderId,
    parentOrderNo: row.parent_work_order_no || (parent && parent.work_order_no),
    specText,
    opStatusLabel,
    opStatusTheme,
    plannedText: view.quantity(row.planned_qty),
    doneText: view.quantity(row.done_qty),
    percent: planned > 0 ? Math.min(100, Math.round(done / planned * 100)) : 0,
    dueText: String(row.planned_finish_date || '').slice(5, 10),
    canClaim,
    buttonText: !canExecute || tab === 'done' || (tab === 'available' && !canClaim)
      ? '查看'
      : (tab === 'available'
        ? (row.lane === 'rework' ? '接返工' : '去接单')
        : (row.timing ? '继续报工' : (row.here_qty ? (row.lane === 'rework' ? '开始返工' : '去报工') : '查看'))),
  });
}

function buildGroups(rows, expandedMap) {
  const map = {};
  const list = [];
  const workOrders = new Map();
  const parentIds = new Set();
  rows.forEach(item => {
    const id = String(item.work_order_id);
    if (!workOrders.has(id)) workOrders.set(id, item);
    if (item.parentOrderId && item.parentOrderId !== id) parentIds.add(item.parentOrderId);
  });

  rows.forEach(item => {
    const parent = item.parent_work_order;
    const id = String(item.work_order_id);
    const parentRow = item.parentOrderId !== id && workOrders.get(item.parentOrderId);
    // 折叠区只展示一级，带有本页子工序的工单要保留自己的卡片。
    const nested = Boolean(parentRow && !parentIds.has(id));
    const card = nested ? parentRow : item;
    const groupKey = `wo-${card.work_order_id}`;
    if (!map[groupKey]) {
      const mainInfo = {
        id: card.work_order_id,
        work_order_no: card.work_order_no,
        product_name: card.product_name || card.item_name,
        item_name: card.item_name,
        item_code: card.item_code,
        sku_name: card.sku_name,
        specText: card.specText,
        customer_name: card.customer_name,
        sales_order_no: card.sales_order_no,
        planned_qty: card.planned_qty,
        plannedText: card.plannedText,
        unit_name: card.unit_name || card.unitShort || '台',
        dueText: card.dueText,
        status: card.work_order_status,
        status_label: card.work_order_status_label,
        priority: card.priority,
        priority_label: card.priority_label,
        is_urgent: card.is_urgent,
        paused: card.paused,
        pause_reason: card.pause_reason,
        is_rework_order: card.is_rework_order,
        is_custom: card.is_custom,
        has_drawing: card.has_drawing,
        drawings_updated: card.drawings_updated,
        source_label: card.source_label || '主工单',
        isSub: card.isSub,
        parentOrderNo: card.parentOrderNo,
        semi_summary: [],
      };
      map[groupKey] = {
        key: groupKey,
        expanded: false,
        main: mainInfo,
        mainTasks: [],
        subMap: {},
        subGroups: [],
      };
      list.push(map[groupKey]);
    }
    const group = map[groupKey];
    if (!group.main.sales_order_no && (item.sales_order_no || (parent && parent.sales_order_no))) {
      group.main.sales_order_no = (parent && parent.sales_order_no) || item.sales_order_no;
    }
    if (!group.main.customer_name && (item.customer_name || (parent && parent.customer_name))) {
      group.main.customer_name = (parent && parent.customer_name) || item.customer_name;
    }
    if (!nested && item.semi_summary && item.semi_summary.length && !group.main.semi_summary.length) {
      group.main.semi_summary = item.semi_summary.map(s => Object.assign({}, s, {
        specText: buildSpecText(s),
        plannedText: view.quantity(s.planned_qty),
        storedText: view.quantity(s.stored_qty),
      }));
    }
    if (nested) {
      const subKey = `sub-${item.work_order_id}`;
      if (!group.subMap[subKey]) {
        const subGroup = {
          id: item.work_order_id,
          work_order_no: item.work_order_no,
          product_name: item.product_name || item.item_name,
          item_name: item.item_name,
          item_code: item.item_code,
          specText: item.specText,
          plannedText: item.plannedText,
          storedText: view.quantity(item.stored_qty || 0),
          unit_name: item.unit_name || item.unitShort || '件',
          dueText: item.dueText,
          is_rework_order: item.is_rework_order,
          status_label: item.work_order_status_label,
          paused: item.paused,
          pause_reason: item.pause_reason,
          tasks: [],
        };
        group.subMap[subKey] = subGroup;
        group.subGroups.push(subGroup);
      }
      group.subMap[subKey].tasks.push(item);
    } else {
      group.mainTasks.push(item);
    }
  });

  // 合并 semi_summary 中的子工单信息，保证下挂子工单区域可查看全部子工单及生产内容
  list.forEach(group => {
    const semiList = group.main.semi_summary || [];
    semiList.forEach(semi => {
      if (map[`wo-${semi.id}`]) return;
      const subKey = `sub-${semi.id}`;
      if (!group.subMap[subKey]) {
        const subGroup = {
          id: semi.id,
          work_order_no: semi.work_order_no,
          product_name: semi.item_name || semi.product_name,
          item_name: semi.item_name,
          item_code: semi.item_code,
          specText: semi.specText || buildSpecText(semi),
          plannedText: semi.plannedText || view.quantity(semi.planned_qty),
          storedText: semi.storedText || view.quantity(semi.stored_qty),
          unit_name: semi.unit_name || '件',
          is_rework_order: false,
          status_label: semi.status_label || '',
          tasks: [],
        };
        group.subMap[subKey] = subGroup;
        group.subGroups.push(subGroup);
      } else if (!group.subMap[subKey].specText && semi.specText) {
        group.subMap[subKey].specText = semi.specText;
      }
    });

    // 子工单当前工序和人员：上级工序可先开工等子件，折叠时也要能看到谁在做。
    const progressById = {};
    semiList.forEach(semi => { progressById[String(semi.id)] = (semi.progress || []).join('；'); });
    group.subGroups.forEach(sub => { sub.progressText = progressById[String(sub.id)] || ''; });

    const mainOpNames = group.mainTasks
      .map(t => t.title)
      .filter(Boolean)
      .filter((v, i, a) => a.indexOf(v) === i)
      .slice(0, 3)
      .join('、');
    const subNames = group.subGroups
      .map(s => {
        const name = s.product_name || s.item_name || '';
        const state = s.progressText || s.status_label;
        return name && state ? `${name}（${state}）` : name;
      })
      .filter(Boolean)
      .filter((v, i, a) => a.indexOf(v) === i)
      .join('、');
    const subTaskCount = group.subGroups.reduce((sum, s) => sum + (s.tasks ? s.tasks.length : 0), 0);
    const hasExplicitExpand = Boolean(expandedMap && Object.prototype.hasOwnProperty.call(expandedMap, group.key));

    group.mainOpNames = mainOpNames;
    group.subCount = group.subGroups.length;
    group.subTaskCount = subTaskCount;
    group.subSummaryNames = subNames;
    group.expanded = hasExplicitExpand ? Boolean(expandedMap[group.key]) : subTaskCount > 0;
  });

  return list;
}

function reviewMedia(media, photos) {
  const items = (media || []).slice();
  (photos || []).forEach(url => { if (!items.some(item => item.url === url)) items.push({ type: 'image', url }); });
  return items;
}

function decorateReview(row, kind) {
  if (kind === 'scrap') {
    return Object.assign({}, row, {
      key: `s${row.id}`,
      kind,
      title: `${row.operation_name || ''} · 报废申请`,
      line: `${row.serial_no || ''} · ${row.work_order_no || ''}`,
      reasonText: [(row.reason_tags || []).join('、'), row.reason].filter(Boolean).join('：'),
      who: row.reporter_name,
      media: reviewMedia(row.media, row.photos),
    });
  }
  const plan = row.plan || {};
  const lines = (row.lines || []).map(l => `${l.component_item_name}${l.serial_no ? ' ' + l.serial_no : ' ' + view.quantity(l.qty) + (l.unit_name || '')}`).join('、');
  return Object.assign({}, row, {
    key: `p${row.id}`,
    kind,
    title: `不良退回处置 · ${row.plan_label || ''}${plan.action === 'rework' && plan.start_operation_name ? '（自' + plan.start_operation_name + '工序起）' : ''}`,
    line: `${row.return_no || ''} · ${row.work_order_no || ''} · ${row.operation_name || ''}`,
    reasonText: `${row.summary || lines}；${row.reason || ''}`,
    who: row.requested_by_name,
    media: reviewMedia(plan.media, plan.photos),
  });
}

Page({
  data: {
    tabs: TABS,
    tab: 'mine',
    counts: { mine: 0, available: 0 },
    rows: [],
    groups: [],
    expandedMap: {},
    reviews: [],
    packs: [],
    packCounts: { mine: 0, available: 0 },
    page: 1,
    lastPage: 1,
    total: 0,
    packPage: 1,
    packLastPage: 1,
    packError: '',
    loading: true,
    busy: false,
    error: '',
    keyword: '',
    userName: '',
    userInitial: '工',
    canExecute: false,
    progressScope: 'sales',
    progressRows: [],
    progressExpandedId: 0,
    progressDetail: null,
    progressDetailError: '',
  },
  onLoad(options) {
    const user = wx.getStorageSync('erp_user') || {};
    const userName = user.nickname || user.display_name || user.username || '';
    const userInitial = userName ? String(userName).trim().slice(0, 1) : '工';
    this.setData({
      tab: options && TABS.concat([REVIEW_TAB]).some(t => t.key === options.tab) ? options.tab : 'mine',
      keyword: options && options.keyword ? decodeURIComponent(options.keyword) : '',
      userName,
      userInitial,
    });
  },
  onShow() { if (this.mediaReturning) { this.mediaReturning = false; return; } this.load(); },
  onHide() { if (this.mediaActive) this.mediaReturning = true; },
  onUnload() { this.detailRequest = null; this.sequence = (this.sequence || 0) + 1; },
  mediaActivity(event) { this.mediaActive = event.detail.active; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.detailRequest = null;
    this.setData({ loading: true, error: '', progressDetail: null, progressDetailError: '' });
    if (this.data.tab === 'review') return this.loadReviews(sequence);
    if (this.data.tab === 'progress') return this.loadProgress(sequence);
    const packs = this.loadPacks(sequence);
    const tasks = production.tasks({ tab: this.data.tab, page: this.data.page, per_page: 10, keyword: this.data.keyword }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response);
      const canExecute = Boolean(response.permissions && response.permissions.execute);
      const counts = response.counts || this.data.counts;
      const rows = paged.rows.map(row => decorate(row, this.data.tab, canExecute));
      const groups = buildGroups(rows, this.data.expandedMap);
      this.setData({
        rows,
        groups,
        page: paged.page,
        lastPage: paged.lastPage,
        total: paged.total,
        canExecute,
        counts,
        tabs: tabsFor(counts, this.data.tab),
      });
    }).catch(error => { if (sequence === this.sequence) this.setData({ error: view.errorText(error) }); });
    return Promise.all([tasks, packs]).finally(() => { if (sequence === this.sequence) this.setData({ loading: false }); });
  },
  toggleGroup(event) {
    const key = event.currentTarget.dataset.key;
    if (!key) return;
    const target = (this.data.groups || []).find(g => g.key === key);
    const nextExpanded = target ? !target.expanded : !this.data.expandedMap[key];
    const expandedMap = Object.assign({}, this.data.expandedMap, {
      [key]: nextExpanded,
    });
    const groups = (this.data.groups || []).map(g => (
      g.key === key ? Object.assign({}, g, { expanded: nextExpanded }) : g
    ));
    this.setData({ expandedMap, groups });
  },
  // 车间公共订单进度：所有登录员工可看，不按接单人筛选。
  loadProgress(sequence) {
    return production.orderProgress({ scope: this.data.progressScope, keyword: this.data.keyword, page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response);
      if (paged.page > paged.lastPage && paged.lastPage >= 1) { this.setData({ page: paged.lastPage }); return this.loadProgress(sequence); }
      const expandedId = this.data.progressExpandedId;
      const keep = expandedId && paged.rows.some(row => row.id === expandedId);
      this.setData({
        progressRows: paged.rows, rows: [], groups: [], packs: [], reviews: [],
        page: paged.page, lastPage: paged.lastPage, total: paged.total,
        progressExpandedId: keep ? expandedId : 0,
        progressDetail: keep ? this.data.progressDetail : null,
        loading: false,
      });
      if (keep) this.loadProgressDetail(expandedId);
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  pickProgressScope(event) {
    const scope = event.currentTarget.dataset.scope;
    if (scope === this.data.progressScope) return;
    this.setData({ progressScope: scope, page: 1, progressRows: [], progressExpandedId: 0, progressDetail: null, progressDetailError: '' });
    this.load();
  },
  toggleProgress(event) {
    const id = Number(event.currentTarget.dataset.id);
    if (this.data.progressExpandedId === id) {
      this.detailRequest = null;
      this.setData({ progressExpandedId: 0, progressDetail: null, progressDetailError: '' });
      return;
    }
    this.loadProgressDetail(id);
  },
  loadProgressDetail(id) {
    if (this.data.tab !== 'progress' || this.data.loading) return;
    const request = this.detailRequest = {};
    const scope = this.data.progressScope;
    const isCurrent = () => request === this.detailRequest && this.data.tab === 'progress'
      && scope === this.data.progressScope && id === this.data.progressExpandedId;
    this.setData({ progressExpandedId: id, progressDetail: null, progressDetailError: '' });
    return production.orderProgressDetail(id, scope).then(response => {
      if (!isCurrent()) return;
      const body = response.data || {};
      const lines = (body.lines || []).map(line => ({ key: `${line.id || 0}-${line.name}`, name: line.name, workOrders: progressWorkOrders(line.work_orders) }));
      this.setData({ progressDetail: { lines } });
    }).catch(error => { if (isCurrent()) this.setData({ progressDetail: null, progressDetailError: view.errorText(error) }); });
  },
  retryProgressDetail() { if (this.data.progressExpandedId) this.loadProgressDetail(this.data.progressExpandedId); },
  loadPacks(sequence) {
    const can = view.permissions();
    const tab = this.data.tab;
    this.setData({ packError: '' });
    if (!(can('sales_order.shipment.packing.quality') || can('sales_order.shipment.packing.execute'))) {
      this.setData({ packs: [], packCounts: { mine: 0, available: 0 }, packLastPage: 1 });
      return;
    }
    return packing.operations({ scope: tab, page: this.data.packPage, per_page: 10, keyword: this.data.keyword, include_counts: 1 }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response);
      if (paged.page > paged.lastPage) { this.setData({ packPage: paged.lastPage }); return this.loadPacks(sequence); }
      this.setData({ packs: paged.rows.map(row => decoratePack(row, tab)), packPage: paged.page, packLastPage: paged.lastPage, packCounts: response.counts || this.data.packCounts });
    }).catch(error => { if (sequence === this.sequence) this.setData({ packs: [], packCounts: { mine: 0, available: 0 }, packLastPage: 1, packError: view.errorText(error) }); });
  },
  openPack(event) { wx.navigateTo({ url: `/pages/production/shipment-packing/index?operation_id=${Number(event.currentTarget.dataset.id)}` }); },
  loadReviews(sequence) {
    return production.reviews().then(response => {
      if (sequence !== this.sequence) return;
      const body = response.data || response || {};
      const reviews = (body.scraps || []).map(r => decorateReview(r, 'scrap')).concat((body.plans || []).map(r => decorateReview(r, 'plan')));
      const counts = Object.assign({}, this.data.counts, { review: reviews.length });
      this.setData({ reviews, rows: [], groups: [], counts, tabs: tabsFor(counts, 'review'), page: 1, lastPage: 1, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  review(event) {
    const { key, approve } = event.currentTarget.dataset;
    const row = this.data.reviews.find(item => item.key === key);
    if (!row || this.data.busy) return;
    const yes = approve === 'yes' || approve === true;
    const infoSub = row.kind === 'scrap'
      ? (yes ? `确认 ${row.serial_no || ''} 报废？确认后该单件将终止生产。` : '驳回后该单件保留在当前工序继续返工。')
      : (yes ? `处置方案：${row.plan_label}。方案通过后仍需仓库接收入库${row.plan && row.plan.action === 'rework' ? '，接收后下达返工子工单' : ''}。` : '驳回后退回物料保留在原工序。');
    view.modal(this, {
      mode: 'input',
      theme: yes ? 'default' : 'danger',
      title: yes ? (row.kind === 'scrap' ? '审核报废申请' : '审核不良处置申请') : '驳回申请',
      infoMain: row.kind === 'scrap' ? `${row.product_name || ''} · ${row.serial_no || ''}` : `${row.product_name || ''} · ${row.return_no || ''}`,
      infoSub,
      inputLabel: '审核意见 / 备注（选填）：',
      quickTagsLabel: '快捷意见：',
      quickTags: yes ? ['核实属实，同意处置', '按规范执行'] : ['现场复检可修复', '资料不全请补充照片'],
      placeholderText: yes ? '请填写审核意见（选填）' : '请填写驳回原因（选填）',
      confirmText: yes ? '确认审核通过' : '确认驳回',
      success: res => {
        if (!res.confirm) return;
        const payload = { approve: yes, remark: res.content || '', expected_version: row.business_version };
        this.setData({ busy: true });
        (row.kind === 'scrap' ? production.reviewScrap(row.id, payload) : production.reviewPlan(row.id, payload)).then(response => {
          wx.showToast({ title: response.message || '已处理', icon: 'none' });
          this.load();
        }).catch(error => view.modal(this, { theme: 'warn', title: '审核处理失败', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      },
    });
  },
  previewPhoto(event) {
    const { url, urls } = event.currentTarget.dataset;
    wx.previewImage({ current: url, urls: urls || [url] });
  },
  pickTab(event) {
    const tab = event.currentTarget.dataset.tab;
    if (tab === this.data.tab) return;
    this.setData({ tab, page: 1, packPage: 1, packLastPage: 1, packError: '', packs: [], rows: [], groups: [], reviews: [], progressRows: [], progressExpandedId: 0, progressDetail: null, progressDetailError: '' });
    this.load();
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  clearKeyword() {
    this.setData({ keyword: '', page: 1, packPage: 1 });
    this.load();
  },
  search() { this.setData({ page: 1, packPage: 1 }); this.load(); },
  turnPackPage(event) {
    const page = this.data.packPage + Number(event.currentTarget.dataset.delta);
    if (!this.data.loading && page > 0 && page <= this.data.packLastPage) { this.setData({ packPage: page }); this.load(); }
  },
  turnPage(event) {
    const page = this.data.page + Number(event.currentTarget.dataset.delta);
    if (!this.data.loading && page > 0 && page <= this.data.lastPage) { this.setData({ page }); this.load(); }
  },
  open(event) {
    const { id, lane } = event.currentTarget.dataset;
    wx.navigateTo({ url: `/pages/production/task/index?id=${Number(id)}&lane=${lane || 'normal'}` });
  },
  openWorkOrder(event) {
    const id = Number(event.currentTarget.dataset.id || 0);
    if (!id) return;
    wx.navigateTo({ url: `/pages/production/work-order/index?id=${id}` });
  },
  back() { view.back(); },
});
