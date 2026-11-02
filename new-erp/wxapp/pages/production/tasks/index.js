const production = require('../../../services/production');
const packing = require('../../../services/packing');
const view = require('../../../utils/warehouse-page');

const PACK_STATUS = { READY: '待作业', WAITING: '等待前序', IN_PROGRESS: '作业中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' };
function packMinutes(m) { const v = Number(m || 0); return v >= 60 ? `${Math.floor(v / 60)}时${Math.round(v % 60)}分` : `${Math.round(v)}分`; }

// 发货作业（试水 / 打包 / 装车）和生产工序放在同一个「我的待做」「可接工序」里
function decoratePack(row) {
  const contents = row.contents || [];
  const owner = (row.participants || []).find(p => p.role === 'OWNER');
  const my = row.my || {};
  return {
    id: row.id,
    title: row.operation_name,
    statusLabel: PACK_STATUS[row.status] || row.status,
    timing: Boolean(my.timing),
    product: contents.map(c => c.product_name || c.item_name).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join('、'),
    specText: contents.map(c => c.specification || c.item_spec || c.sku_name).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join('、'),
    line: [row.shipment_no, row.package_no, row.order_info && row.order_info.customer_name].filter(Boolean).join(' · '),
    ownerName: owner ? owner.name : '',
    myText: packMinutes(my.closed_minutes),
    repair: (row.serial_results || []).filter(s => s.repair && s.repair.state !== 'returned').length,
    changed: Boolean(row.order_info && row.order_info.changed && row.order_info.changed.length),
  };
}

const TABS = [{ key: 'mine', label: '我的待做' }, { key: 'available', label: '可接工序' }, { key: 'done', label: '已完工' }];
const REVIEW_TAB = { key: 'review', label: '待审核' };

function tabsFor(counts, tab) {
  return (counts && counts.review > 0) || tab === 'review'
    ? [TABS[0], TABS[1], REVIEW_TAB, TABS[2]]
    : TABS;
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
  const isSub = Boolean(row.is_sub_work_order || row.parent_work_order_id || row.parent_work_order);
  const specText = buildSpecText(row);
  return Object.assign({}, row, {
    key: row.key || `${row.id}-${row.lane || 'normal'}`,
    lane: row.lane || 'normal',
    title: row.title || row.operation_name,
    unitShort: row.unit_short || '台',
    isSub,
    specText,
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
  rows.forEach(item => {
    const parent = item.parent_work_order;
    const isSub = Boolean(item.isSub && parent && parent.id);
    const rootId = isSub ? parent.id : item.work_order_id;
    const groupKey = `wo-${rootId}`;
    if (!map[groupKey]) {
      const mainInfo = isSub ? {
        id: parent.id,
        work_order_no: parent.work_order_no,
        product_name: parent.product_name || parent.item_name,
        item_name: parent.item_name,
        item_code: parent.item_code,
        sku_name: parent.sku_name,
        specText: buildSpecText(parent),
        customer_name: parent.customer_name || item.customer_name,
        sales_order_no: parent.sales_order_no || item.sales_order_no,
        planned_qty: parent.planned_qty,
        plannedText: view.quantity(parent.planned_qty),
        unit_name: parent.unit_name || '台',
        dueText: String(parent.planned_finish_date || '').slice(5, 10),
        status: parent.status,
        status_label: parent.status_label,
        priority: parent.priority,
        priority_label: parent.priority_label,
        is_urgent: parent.is_urgent,
        paused: parent.paused,
        pause_reason: parent.pause_reason,
        is_rework_order: parent.is_rework_order,
        is_custom: parent.is_custom,
        has_drawing: parent.has_drawing,
        drawings_updated: parent.drawings_updated,
        source_label: parent.source_label || '主工单',
        semi_summary: [],
      } : {
        id: item.work_order_id,
        work_order_no: item.work_order_no,
        product_name: item.product_name || item.item_name,
        item_name: item.item_name,
        item_code: item.item_code,
        sku_name: item.sku_name,
        specText: item.specText,
        customer_name: item.customer_name,
        sales_order_no: item.sales_order_no,
        planned_qty: item.planned_qty,
        plannedText: item.plannedText,
        unit_name: item.unit_name || item.unitShort || '台',
        dueText: item.dueText,
        status: item.work_order_status,
        status_label: item.work_order_status_label,
        priority: item.priority,
        priority_label: item.priority_label,
        is_urgent: item.is_urgent,
        paused: item.paused,
        pause_reason: item.pause_reason,
        is_rework_order: item.is_rework_order,
        is_custom: item.is_custom,
        has_drawing: item.has_drawing,
        drawings_updated: item.drawings_updated,
        source_label: item.source_label || '主工单',
        semi_summary: (item.semi_summary || []).map(s => Object.assign({}, s, {
          specText: buildSpecText(s),
          plannedText: view.quantity(s.planned_qty),
          storedText: view.quantity(s.stored_qty),
        })),
      };
      map[groupKey] = {
        key: groupKey,
        expanded: Boolean(expandedMap && expandedMap[groupKey]),
        main: mainInfo,
        mainTasks: [],
        subMap: {},
        subGroups: [],
      };
      list.push(map[groupKey]);
    }
    const group = map[groupKey];
    if (!isSub && item.semi_summary && item.semi_summary.length && (!group.main.semi_summary || !group.main.semi_summary.length)) {
      group.main.semi_summary = item.semi_summary.map(s => Object.assign({}, s, {
        specText: buildSpecText(s),
        plannedText: view.quantity(s.planned_qty),
        storedText: view.quantity(s.stored_qty),
      }));
    }
    if (isSub) {
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

  // 合并 semi_summary 中的子工单信息，保证主工单折叠时始终能看到全部子工单数量及生产内容
  list.forEach(group => {
    const semiList = group.main.semi_summary || [];
    semiList.forEach(semi => {
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

    const mainOpNames = group.mainTasks
      .map(t => t.title)
      .filter(Boolean)
      .filter((v, i, a) => a.indexOf(v) === i)
      .slice(0, 3)
      .join('、');
    const subNames = group.subGroups
      .map(s => s.product_name || s.item_name)
      .filter(Boolean)
      .filter((v, i, a) => a.indexOf(v) === i)
      .join('、');

    group.mainOpNames = mainOpNames;
    group.subCount = group.subGroups.length;
    group.subSummaryNames = subNames;
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
    canExecute: false,
  },
  onLoad(options) {
    const user = wx.getStorageSync('erp_user') || {};
    this.setData({
      tab: options && TABS.concat([REVIEW_TAB]).some(t => t.key === options.tab) ? options.tab : 'mine',
      keyword: options && options.keyword ? decodeURIComponent(options.keyword) : '',
      userName: user.nickname || user.display_name || user.username || '',
    });
  },
  onShow() { if (this.mediaReturning) { this.mediaReturning = false; return; } this.load(); },
  onHide() { if (this.mediaActive) this.mediaReturning = true; },
  mediaActivity(event) { this.mediaActive = event.detail.active; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    if (this.data.tab === 'review') return this.loadReviews(sequence);
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
    const expandedMap = Object.assign({}, this.data.expandedMap, {
      [key]: !this.data.expandedMap[key],
    });
    const groups = (this.data.groups || []).map(g => (
      g.key === key ? Object.assign({}, g, { expanded: Boolean(expandedMap[key]) }) : g
    ));
    this.setData({ expandedMap, groups });
  },
  loadPacks(sequence) {
    const can = view.permissions();
    const tab = this.data.tab;
    this.setData({ packError: '' });
    if (!(can('sales_order.shipment.packing.quality') || can('sales_order.shipment.packing.execute'))) {
      this.setData({ packs: [], packCounts: { mine: 0, available: 0 }, packLastPage: 1 });
      return;
    }
    if (!['mine', 'available'].includes(tab)) { this.setData({ packs: [], packLastPage: 1 }); return; }
    return packing.operations({ scope: tab, page: this.data.packPage, per_page: 10, keyword: this.data.keyword, include_counts: 1 }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response);
      this.setData({ packs: paged.rows.map(decoratePack), packPage: paged.page, packLastPage: paged.lastPage, packCounts: response.counts || this.data.packCounts });
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
      : (yes ? `处置方案：${row.plan_label}${row.plan && row.plan.action === 'rework' ? '（审核通过后自动下达返工子工单）' : ''}` : '驳回后退回物料保留在原工序。');
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
        const payload = { approve: yes, remark: res.content || '' };
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
    this.setData({ tab, page: 1, packPage: 1, packLastPage: 1, packError: '', packs: [], rows: [], groups: [], reviews: [] });
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
  primary(event) {
    const key = event.currentTarget.dataset.key;
    const row = this.data.rows.find(item => item.key === key);
    if (this.data.tab !== 'available' || !this.data.canExecute || !row || !row.canClaim) return this.open(event);
    if (this.data.busy) return;
    const id = row.id;
    this.setData({ busy: true });
    production.action(id, { action: 'claim', lane: row.lane }).then(response => {
      wx.showToast({ title: response.message || '接单成功', icon: 'success' });
      wx.navigateTo({ url: `/pages/production/task/index?id=${id}&lane=${row.lane}` });
    }).catch(error => {
      view.modal(this, { theme: 'warn', title: '接单失败', content: view.errorText(error), showCancel: false });
      this.load();
    }).finally(() => this.setData({ busy: false }));
  },
  openSharedCutting() { wx.navigateTo({ url: '/pages/production/cutting-run/index' }); },
  back() { view.back(); },
});
