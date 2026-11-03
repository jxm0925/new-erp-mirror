// 原料领用／余料登记：实际来源关联集中开料批次，完工时按已登记余料结算库存和成本。
const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const num = (value, digits = 3) => {
  const p = Math.pow(10, digits);
  return String(Math.round(Number(value || 0) * p) / p);
};
const keyOf = () => `wxscut-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 8)}`;
const measureUnit = value => value === 'm2' ? 'm²' : value === 'm' ? '米' : value || '';
const sourceUnit = row => row.unit_kind === 'sheet' ? '张' : row.unit_kind === 'root' ? '根' : measureUnit(row.unit_kind || row.measure_unit);
function sizeText(row) {
  const d = row.dimensions || {};
  if (row.mode === 'length' || row.measure_unit === 'm') return row.unit_kind === 'root' ? `每根 ${num(row.measure_qty)} 米` : '按实际长度计';
  if (String(row.shape).toUpperCase() === 'AREA_ONLY') return `异形余板 ${num(d.area_m2 || row.measure_qty || row.input_measure)} m²${d.thickness_mm ? ` · 厚 ${Number(d.thickness_mm)} mm` : ''}`;
  return [d.length_mm, d.width_mm, d.thickness_mm].filter(v => v !== null && v !== undefined).map(Number).join(' × ') + ' mm';
}
const partSize = line => [line.length_mm, line.width_mm, line.thickness_mm].filter(v => v !== null && v !== undefined).map(Number).join('×') + (line.allow_rotation ? '（允许旋转）' : '');
function batchView(batch) {
  const measure = batch.measure_unit || (batch.inputs || [])[0]?.measure_unit || 'm2';
  return Object.assign({}, batch, {
    unitText: measureUnit(measure), editable: batch.status === 'issued' || Boolean(batch.can_resubmit),
    inputText: num(batch.input_measure), netText: num(batch.net_measure), poolText: num(batch.pool_measure), allocatedText: num(batch.allocated_measure), leftText: num(batch.left_measure),
    issuedText: String(batch.issued_at || '').replace('T', ' ').slice(5, 16),
    allocations: (batch.allocations || []).map(a => Object.assign({}, a, { measure: num(a.measure) })),
  });
}

Page({
  openRemnantReceipt(event) { const id = Number(event.currentTarget.dataset.id); if (id) wx.navigateTo({ url: '/pages/warehouse/inbound/index?kind=cutting_remnant&id=' + id }); },
  data: {
    tab: 'demand', loading: true, busy: false, error: '', items: [], batches: [], openCount: 0, page: 1, lastPage: 1,
    panel: '', current: null, keyword: '', rows: [], sourcePage: 1, sourceLastPage: 1, sourceLoading: false, sourceError: '',
    picked: {}, pickedCount: 0, pickedMeasureText: '0',
    batch: null, remnants: [], remark: '', confirmed: false, locatorOpen: false, locatorIndex: -1, locatorValue: {},
  },
  onLoad(options) {
    this.runId = Number(options.run_id || 0) || null;
    this.autoItem = Number(options.item_id || 0) || null;
    this.autoSource = { item_id: this.autoItem, item_name: decodeURIComponent(options.item_name || '目标原料'), measure_unit: options.measure_unit, mode: options.mode };
    if (options.tab === 'batches') this.setData({ tab: 'batches' });
  },
  onShow() {
    if (view.resumingScan(this)) return;
    this.load().then(() => {
      if (!this.autoItem) return;
      const index = this.data.items.findIndex(i => Number(i.item_id) === this.autoItem);
      const item = index >= 0 ? this.data.items[index] : this.autoSource;
      this.autoItem = null;
      this.openSourcesFor(item);
    });
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  stop() {},

  load() {
    this.setData({ loading: true, error: '' });
    return Promise.all([production.sharedCuttingDemand({}), production.sharedCuttings(Object.assign({ page: this.data.page, per_page: 10 }, this.runId ? { cutting_run_id: this.runId } : {}))]).then(([demand, list]) => {
      const opened = this.data.items.filter(i => i.open).map(i => i.item_id);
      const items = ((demand.data || demand).items || []).map(item => Object.assign({}, item, {
        needText: num(item.need_measure), poolText: num(item.pool_measure), shortText: num(item.short_measure), short: Number(item.short_measure) > 0.0005, unitText: measureUnit(item.measure_unit || 'm2'),
        open: opened.includes(item.item_id),
        lines: (item.lines || []).map(line => Object.assign({}, line, { sizeText: partSize(line), startText: String(line.planned_start_date || '').slice(5, 10) })),
      }));
      const batches = (list.data || []).map(batchView);
      this.setData({ items, batches, openCount: batches.filter(b => ['issued', 'awaiting_confirm'].includes(b.status)).length, lastPage: list.last_page || 1, loading: false });
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

  openSources(event) { this.openSourcesFor(this.data.items[Number(event.currentTarget.dataset.index)]); },
  openSourcesFor(current) {
    if (!this.runId) {
      view.modal(this, {
        theme: 'warn',
        title: '请先启动集中开料批次',
        infoMain: '每次集中开料仅针对单一 BOM',
        infoSub: '请先在「集中开料」中选择 BOM 并开始计时，再从对应批次选择实际原料或余料。',
        confirmText: '前往开料',
        success: res => { if (res.confirm) wx.redirectTo({ url: '/pages/production/cutting-run/index' }); } });
      return;
    }
    this.picks = {};
    this.setData({ panel: 'sources', current: Object.assign({}, current, { unitText: measureUnit(current.measure_unit || 'm2') }), keyword: '', rows: [], sourcePage: 1, picked: {}, pickedCount: 0, pickedMeasureText: '0' });
    this.loadSources(1);
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  searchSources() { this.loadSources(1); },
  moreSources() { if (this.data.sourcePage < this.data.sourceLastPage && !this.data.sourceLoading) this.loadSources(this.data.sourcePage + 1); },
  loadSources(page) {
    this.setData({ sourceLoading: true, sourceError: '' });
    return production.sharedCuttingSources({ item_id: this.data.current.item_id, cutting_run_id: this.runId, keyword: this.data.keyword, page, per_page: 10 }).then(response => {
      const rows = (response.data || []).map(row => Object.assign({}, row, { sizeText: sizeText(row), measureText: num(row.measure_qty), unitText: measureUnit(row.measure_unit), countUnitText: sourceUnit(row), availableText: num(row.available_qty), countable: !row.physical_material_id }));
      this.setData({ rows: page === 1 ? rows : this.data.rows.concat(rows), sourcePage: page, sourceLastPage: response.last_page || 1, sourceLoading: false });
    }).catch(error => this.setData({ sourceLoading: false, sourceError: view.errorText(error) }));
  },
  toggleSource(event) {
    const row = this.data.rows.find(item => item.source_key === event.currentTarget.dataset.key);
    if (!row) return;
    if (this.picks[row.source_key]) delete this.picks[row.source_key]; else this.picks[row.source_key] = Object.assign({}, row, { qty: row.unit_kind === 'm' || row.unit_kind === 'm2' ? String(row.available_qty) : String(Math.min(1, Number(row.available_qty))) });
    this.updatePicked();
  },
  pickedQtyInput(event) {
    const row = this.picks[event.currentTarget.dataset.key];
    if (!row) return;
    row.qty = event.detail.value;
    this.updatePicked();
  },
  updatePicked() {
    const list = Object.values(this.picks);
    const picked = {};
    list.forEach(r => { picked[r.source_key] = r; });
    this.setData({ picked, pickedCount: list.length, pickedMeasureText: num(list.reduce((s, r) => s + Number(r.measure_qty || 0) * Number(r.qty || 0), 0)) });
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
    for (const row of list) {
      const qty = Number(row.qty);
      const max = row.physical_material_id ? 1 : Number(row.available_qty);
      const fractionalRoot = row.unit_kind === 'root' && !row.physical_material_id;
      if (!Number.isFinite(qty) || qty <= 0 || qty > max + 1e-8 || (row.physical_material_id || row.unit_kind === 'sheet') && !Number.isInteger(qty)
        || (fractionalRoot && !/^\d+(?:\.\d{1,4})?$/.test(String(qty)))) {
        wx.showToast({ title: `${row.physical_no || row.batch_no || row.source_key}：请核对实际领用数量`, icon: 'none' }); return;
      }
    }
    const sourceCount = list.reduce((sum, row) => sum + (!row.physical_material_id && ['root', 'sheet'].includes(row.unit_kind) ? Math.ceil(Number(row.qty)) : 1), 0);
    if (sourceCount > 200) { wx.showToast({ title: '单次最多领用 200 条实际来源，请分批领用', icon: 'none' }); return; }
    const sources = [];
    list.forEach(row => {
      const source = { source_key: row.source_key, inventory_balance_id: row.inventory_balance_id, physical_material_id: row.physical_material_id, source_version: row.source_version, qty: String(row.qty) };
      if (!row.physical_material_id && ['root', 'sheet'].includes(row.unit_kind)) {
        for (let i = 0; i < Number(row.qty); i++) sources.push(Object.assign({}, source, { source_key: `${row.source_key}:${i + 1}`, qty: String(Math.min(1, Number((Number(row.qty) - i).toFixed(4)))) }));
      } else sources.push(source);
    });
    const payload = { item_id: this.data.current.item_id, cutting_run_id: this.runId, sources };
    payload.idempotency_key = this.commandKey('issue', payload);
    view.modal(this, {
      title: '确认领用原料',
      subtitle: '切割完成后请在「开料批次」中登记余料',
      kvRows: [
        { k: '领用原料', v: this.data.current.item_name },
        { k: '来源条数', v: `${list.length} 条`, red: true },
        { k: '合计投入', v: `${this.data.pickedMeasureText} ${this.data.current.unitText}`, red: true },
      ],
      confirmText: '确认领出',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.sharedCuttingIssue(payload).then(response => {
          this.commands.issue = null;
          wx.showToast({ title: '原料已领出，完成后请登记余料', icon: 'none', duration: 2500 });
          this.setData({ panel: '', tab: 'batches' });
          const batch = response.data;
          return this.load().then(() => { if (batch && batch.id) this.openBatchById(batch.id); });
        }).catch(error => view.modal(this, { theme: 'warn', title: '领料失败', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },

  openBatch(event) { this.openBatchById(Number(event.currentTarget.dataset.id)); },
  openBatchById(id) {
    return production.sharedCutting(id).then(response => {
      const batch = batchView(response.data || response);
      const saved = (batch.remnants || []).length ? batch.remnants : batch.remnant_receipt?.requested_lines || [];
      const remnants = (batch.inputs || []).map(input => Object.assign({ source_key: input.source_key, physical_no: input.physical_no || input.batch_no || input.source_key, sizeText: sizeText(input),
        input_measure: Number(input.input_measure), measure_qty: '', warehouse_id: input.warehouse_id, warehouse_name: input.warehouse_name, location_id: input.location_id, location_name: input.location_name },
        saved.find(row => row.source_key === input.source_key) || {}, { input_measure: Number(input.input_measure), physical_no: input.physical_no || input.batch_no || input.source_key, unitText: measureUnit(input.measure_unit) }));
      this.setData({ panel: 'batch', batch, remnants, remark: batch.remark || '', confirmed: false });
    }).catch(error => view.modal(this, { theme: 'warn', title: '读取失败', content: view.errorText(error), showCancel: false }));
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
    if (this.data.busy || !batch || !batch.editable || !this.data.confirmed) return;
    const rows = this.data.remnants;
    for (const row of rows) {
      const qty = String(row.measure_qty).trim();
      if (qty === '' || !/^\d+(\.\d{1,8})?$/.test(qty)) { wx.showToast({ title: `${row.physical_no} 余料请填写有效数值（无余料填 0）`, icon: 'none' }); return; }
      if (Number(qty) > row.input_measure + 1e-8) { wx.showToast({ title: `${row.physical_no} 余料不可超过实际投入`, icon: 'none' }); return; }
      if (Number(qty) > 0 && !row.location_id) { wx.showToast({ title: `${row.physical_no} 存在余料，请选择回库库位`, icon: 'none' }); return; }
    }
    const payload = { expected_version: batch.business_version, confirmed: true, remark: String(this.data.remark || '').trim() || null,
      remnants: rows.map(r => Object.assign({ source_key: r.source_key, measure_qty: String(r.measure_qty).trim() },
        Number(r.measure_qty) > 0 ? { warehouse_id: r.warehouse_id, location_id: r.location_id } : {})) };
    payload.idempotency_key = this.commandKey('settle', payload);
    this.setData({ busy: true });
    production.sharedCuttingSettle(batch.id, payload).then(response => {
      this.commands.settle = null;
      const result = response.data || response;
      wx.showToast({ title: result.status === 'awaiting_confirm' ? '余料已保存，完成下料登记后结算' : '余料已登记结算', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      return this.load();
    }).catch(error => view.modal(this, { theme: 'warn', title: '登记失败', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  closePanel() { this.setData({ panel: '' }); },
});
