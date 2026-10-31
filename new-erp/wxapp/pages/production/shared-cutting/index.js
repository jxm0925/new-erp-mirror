// 板材领板／登记余料：领板必须挂在「集中开料」里一批还在开的开料记录上（一次只开一个 BOM），切完登记每张板的余料；
// 切好的件在各工单下料点「已备料」，按 BOM 定额从这里登记的净耗里扣。
const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const num = (value, digits = 3) => {
  const p = Math.pow(10, digits);
  return String(Math.round(Number(value || 0) * p) / p);
};
const keyOf = () => `wxscut-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 8)}`;
function sizeText(row) {
  const d = row.dimensions || {};
  if (String(row.shape).toUpperCase() === 'AREA_ONLY') return `余板 ${num(d.area_m2 || row.measure_qty || row.input_measure)} m²${d.thickness_mm ? ` · 厚 ${Number(d.thickness_mm)}` : ''}`;
  return [d.length_mm, d.width_mm, d.thickness_mm].filter(v => v !== null && v !== undefined).map(Number).join(' × ') + ' mm';
}
const partSize = line => [line.length_mm, line.width_mm, line.thickness_mm].filter(v => v !== null && v !== undefined).map(Number).join('×') + (line.allow_rotation ? '（可转）' : '');
function batchView(batch) {
  return Object.assign({}, batch, {
    inputText: num(batch.input_measure), poolText: num(batch.pool_measure), allocatedText: num(batch.allocated_measure), leftText: num(batch.left_measure),
    issuedText: String(batch.issued_at || '').replace('T', ' ').slice(5, 16),
    allocations: (batch.allocations || []).map(a => Object.assign({}, a, { measure: num(a.measure) })),
    remnants: (batch.remnants || []).map(r => Object.assign({}, r, { measure_qty: Number(r.measure_qty || 0) ? num(r.measure_qty, 4) : 0 })),
  });
}

Page({
  data: {
    tab: 'demand', loading: true, busy: false, error: '', items: [], batches: [], openCount: 0, page: 1, lastPage: 1,
    panel: '', current: null, keyword: '', rows: [], sourcePage: 1, sourceLastPage: 1, sourceLoading: false, sourceError: '',
    picked: {}, pickedCount: 0, pickedAreaText: '0',
    batch: null, remnants: [], remark: '', confirmed: false, locatorOpen: false, locatorIndex: -1, locatorValue: {},
  },
  onLoad(options) {
    this.runId = Number(options.run_id || 0) || null;
    this.autoItem = Number(options.item_id || 0) || null;
    if (options.tab === 'batches') this.setData({ tab: 'batches' });
  },
  onShow() {
    this.load().then(() => {
      if (!this.autoItem) return;
      const index = this.data.items.findIndex(i => Number(i.item_id) === this.autoItem);
      const item = index >= 0 ? this.data.items[index] : { item_id: this.autoItem, item_name: '这种板', shortText: '0' };
      this.autoItem = null;
      this.openSourcesFor(item);
    });
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  stop() {},

  load() {
    this.setData({ loading: true, error: '' });
    return Promise.all([production.sharedCuttingDemand({}), production.sharedCuttings(Object.assign({ page: this.data.page, per_page: 20 }, this.runId ? { cutting_run_id: this.runId } : {}))]).then(([demand, list]) => {
      const opened = this.data.items.filter(i => i.open).map(i => i.item_id);
      const items = ((demand.data || demand).items || []).map(item => Object.assign({}, item, {
        needText: num(item.need_measure), poolText: num(item.pool_measure), shortText: num(item.short_measure), short: Number(item.short_measure) > 0.0005,
        open: opened.includes(item.item_id),
        lines: (item.lines || []).map(line => Object.assign({}, line, { sizeText: partSize(line), startText: String(line.planned_start_date || '').slice(5, 10) })),
      }));
      const batches = (list.data || []).map(batchView);
      this.setData({ items, batches, openCount: batches.filter(b => b.status === 'issued').length, lastPage: list.last_page || 1, loading: false });
    }).catch(error => this.setData({ loading: false, error: view.errorText(error) }));
  },
  pickTab(event) { this.setData({ tab: event.currentTarget.dataset.tab }); },
  toggleItem(event) {
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ [`items[${index}].open`]: !this.data.items[index].open });
  },
  turnPage(event) {
    const page = this.data.page + Number(event.currentTarget.dataset.delta || 0);
    if (page < 1 || page > this.data.lastPage) return;
    this.setData({ page });
    this.load();
  },

  // 领板
  openSources(event) { this.openSourcesFor(this.data.items[Number(event.currentTarget.dataset.index)]); },
  openSourcesFor(current) {
    if (!this.runId) {
      wx.showModal({ title: '先选 BOM 开一批', content: '一次只开一个 BOM 的料：请先在「集中开料」里选 BOM、开始计时，再从那一批里领板。', confirmText: '去开料',
        success: res => { if (res.confirm) wx.redirectTo({ url: '/pages/production/cutting-run/index' }); } });
      return;
    }
    this.picks = {};
    this.setData({ panel: 'sources', current, keyword: '', rows: [], sourcePage: 1, picked: {}, pickedCount: 0, pickedAreaText: '0' });
    this.loadSources(1);
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  searchSources() { this.loadSources(1); },
  moreSources() { if (this.data.sourcePage < this.data.sourceLastPage && !this.data.sourceLoading) this.loadSources(this.data.sourcePage + 1); },
  loadSources(page) {
    this.setData({ sourceLoading: true, sourceError: '' });
    return production.sharedCuttingSources({ item_id: this.data.current.item_id, keyword: this.data.keyword, page, per_page: 20 }).then(response => {
      const rows = (response.data || []).map(row => Object.assign({}, row, { sizeText: sizeText(row), areaText: num(row.measure_qty) }));
      this.setData({ rows: page === 1 ? rows : this.data.rows.concat(rows), sourcePage: page, sourceLastPage: response.last_page || 1, sourceLoading: false });
    }).catch(error => this.setData({ sourceLoading: false, sourceError: view.errorText(error) }));
  },
  toggleSource(event) {
    const row = this.data.rows[Number(event.currentTarget.dataset.index)];
    if (this.picks[row.source_key]) delete this.picks[row.source_key]; else this.picks[row.source_key] = row;
    const list = Object.values(this.picks);
    const picked = {};
    list.forEach(r => { picked[r.source_key] = true; });
    this.setData({ picked, pickedCount: list.length, pickedAreaText: num(list.reduce((s, r) => s + Number(r.measure_qty || 0), 0)) });
  },
  commandKey(name, payload) {
    const signature = JSON.stringify(payload);
    this.commands = this.commands || {};
    if (!this.commands[name] || this.commands[name].signature !== signature) this.commands[name] = { signature, key: keyOf() };
    return this.commands[name].key;
  },
  issue() {
    const list = Object.values(this.picks || {});
    if (this.data.busy || !list.length) return;
    const payload = { item_id: this.data.current.item_id, cutting_run_id: this.runId,
      sources: list.map(r => ({ inventory_balance_id: r.inventory_balance_id, physical_material_id: r.physical_material_id, source_version: r.source_version })) };
    payload.idempotency_key = this.commandKey('issue', payload);
    wx.showModal({ title: '确认领板', content: `从库存领出 ${list.length} 张 ${this.data.current.item_name}（${this.data.pickedAreaText} m²）去切？切完回「开料批次」登记余料。`, confirmText: '确认领出',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.sharedCuttingIssue(payload).then(response => {
          this.commands.issue = null;
          wx.showToast({ title: '已领板，切完登记余料', icon: 'none', duration: 2500 });
          this.setData({ panel: '', tab: 'batches' });
          const batch = response.data;
          return this.load().then(() => { if (batch && batch.id) this.openBatchById(batch.id); });
        }).catch(error => wx.showModal({ title: '领板没成功', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },

  // 登记余料 / 看去向
  openBatch(event) { this.openBatchById(Number(event.currentTarget.dataset.id)); },
  openBatchById(id) {
    return production.sharedCutting(id).then(response => {
      const batch = batchView(response.data || response);
      const remnants = (batch.inputs || []).map(input => ({ source_key: input.source_key, physical_no: input.physical_no, sizeText: sizeText(input),
        input_measure: Number(input.input_measure), measure_qty: '', warehouse_id: 0, warehouse_name: '', location_id: 0, location_name: '' }));
      this.setData({ panel: 'batch', batch, remnants, remark: batch.remark || '', confirmed: false });
    }).catch(error => wx.showModal({ title: '读取失败', content: view.errorText(error), showCancel: false }));
  },
  remnantInput(event) {
    this.setData({ [`remnants[${Number(event.currentTarget.dataset.index)}].measure_qty`]: event.detail.value, confirmed: false });
  },
  remarkInput(event) { this.setData({ remark: event.detail.value }); },
  toggleConfirmed() { this.setData({ confirmed: !this.data.confirmed }); },
  openLocator(event) {
    const index = Number(event.currentTarget.dataset.index);
    const row = this.data.remnants[index];
    this.setData({ locatorOpen: true, locatorIndex: index, locatorValue: row.warehouse_id ? { warehouse_id: row.warehouse_id, warehouse_name: row.warehouse_name, location_id: row.location_id, location_name: row.location_name } : {} });
  },
  chooseLocator(event) {
    const value = event.detail || {};
    const index = this.data.locatorIndex;
    this.setData({ locatorOpen: false, confirmed: false,
      [`remnants[${index}].warehouse_id`]: value.warehouse_id, [`remnants[${index}].warehouse_name`]: value.warehouse_name,
      [`remnants[${index}].location_id`]: value.location_id, [`remnants[${index}].location_name`]: [value.location_code, value.location_name].filter(Boolean).join(' ') });
  },
  closeLocator() { this.setData({ locatorOpen: false }); },
  settle() {
    const batch = this.data.batch;
    if (this.data.busy || !batch || !this.data.confirmed) return;
    const rows = this.data.remnants;
    for (const row of rows) {
      const qty = String(row.measure_qty).trim();
      if (qty === '' || !/^\d+(\.\d{1,8})?$/.test(qty)) { wx.showToast({ title: `${row.physical_no} 的余料请填数字，没有填 0`, icon: 'none' }); return; }
      if (Number(qty) > row.input_measure + 1e-8) { wx.showToast({ title: `${row.physical_no} 的余料超过了整张面积`, icon: 'none' }); return; }
      if (Number(qty) > 0 && !row.location_id) { wx.showToast({ title: `${row.physical_no} 有余料，请选放回的库位`, icon: 'none' }); return; }
    }
    const payload = { expected_version: batch.business_version, confirmed: true, remark: String(this.data.remark || '').trim() || null,
      remnants: rows.map(r => Object.assign({ source_key: r.source_key, measure_qty: String(r.measure_qty).trim() },
        Number(r.measure_qty) > 0 ? { warehouse_id: r.warehouse_id, location_id: r.location_id } : {})) };
    payload.idempotency_key = this.commandKey('settle', payload);
    this.setData({ busy: true });
    production.sharedCuttingSettle(batch.id, payload).then(() => {
      this.commands.settle = null;
      wx.showToast({ title: '已登记，各工单下料可点已备料', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      return this.load();
    }).catch(error => wx.showModal({ title: '登记没成功', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  closePanel() { this.setData({ panel: '' }); },
});
