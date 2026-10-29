// 工序内开料：本道用板材／管材时，接单后在这里领原料、算加工方案，切完登记产出和余料。
const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const num = value => {
  const n = Number(value || 0);
  return String(Math.round(n * 100000) / 100000);
};
const measureUnit = unit => (unit === 'm2' ? 'm²' : (unit || ''));
const sourceUnit = row => (row.unit_kind === 'sheet' ? '张' : (row.unit_kind === 'root' ? '根' : 'm'));
const clone = value => JSON.parse(JSON.stringify(value));
const keyOf = () => `wxcut-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 8)}`;
function sizeText(row) {
  const d = row.dimensions || {};
  if (row.mode === 'length') return `${num(row.measure_qty)} m`;
  if (String(row.shape).toUpperCase() === 'AREA_ONLY') return `面积 ${num(d.area_m2 || row.measure_qty)} m²${d.thickness_mm ? ` · 厚 ${d.thickness_mm} mm` : ''}`;
  return [d.length_mm, d.width_mm, d.thickness_mm].filter(v => v !== null && v !== undefined).join(' × ') + ' mm';
}
function requirementText(row) {
  return row.mode === 'sheet'
    ? [row.length_mm, row.width_mm, row.thickness_mm].filter(v => v !== null && v !== undefined).join(' × ') + ' mm'
    : `${num(row.per_unit_measure)} m / 套`;
}
const partUnit = part => (part && part.quantity_basis === 'total_length' ? 'm' : '件');
const sourceName = row => (row ? row.physical_no || row.batch_no || row.source_key : '');

Page({
  data: {
    woId: 0, opId: 0, opName: '', loading: true, busy: false, error: '', ready: false,
    requirements: [], remaining: 0, canIssue: false, version: 0, batches: [],
    unitQty: 1, sources: [], groups: [], planChecked: false, planConfirmed: false,
    panel: '', items: [], itemIndex: 0, keyword: '', rows: [], page: 1, lastPage: 1, total: 0, sourceLoading: false, sourceError: '', picked: {}, pickedCount: 0,
    batch: null, actualUnits: 1, actual: [], remnants: [], remark: '', confirmed: false, locatorOpen: false, locatorIndex: -1, locatorValue: {},
  },
  onLoad(options) {
    const opName = decodeURIComponent(options.name || '');
    this.setData({ woId: Number(options.wo || 0), opId: Number(options.op || 0), opName });
    wx.setNavigationBarTitle({ title: opName ? `开料 · ${opName}` : '开料' });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },

  load() {
    this.setData({ loading: true, error: '' });
    return production.cutting(this.data.woId, { operation_id: this.data.opId, per_page: 20 }).then(response => {
      const ctx = response.data || response;
      const requirements = (ctx.requirements || []).filter(row => Number(row.operation_id) === this.data.opId);
      const remaining = Number((ctx.remaining_unit_qty_by_operation || {})[this.data.opId] || 0);
      this.parts = {};
      requirements.forEach(row => { this.parts[row.part_key] = row; });
      const items = [];
      requirements.forEach(row => { if (!items.some(item => item.item_id === row.item_id)) items.push({ item_id: row.item_id, label: [row.item_code, row.item_name, row.specification].filter(Boolean).join(' / ') }); });
      const batches = ((ctx.batches || {}).data || []).map(batch => Object.assign({}, batch, {
        statusText: batch.status === 'settled' ? '已登记' : '待登记产出和余料',
        sourceText: (batch.input_lines || []).map(sourceName).join('、'),
      }));
      const unitQty = remaining > 0 ? Math.min(Math.max(1, this.data.unitQty), remaining) : 1;
      this.setData({ loading: false, ready: true, requirements, remaining, items, batches, version: Number(ctx.business_version || 0),
        canIssue: Boolean(ctx.can_issue) && remaining > 0, unitQty });
      this.decorateRequirements();
    }).catch(error => this.setData({ loading: false, error: view.errorText(error) }));
  },
  decorateRequirements() {
    const qty = this.data.unitQty;
    this.setData({ requirements: this.data.requirements.map(row => Object.assign({}, row, {
      sizeText: requirementText(row),
      thisText: row.mode === 'sheet' || row.quantity_basis !== 'total_length' ? `${Number(row.per_unit_piece_qty || 0) * qty} 件` : `${num(Number(row.per_unit_measure) * qty)} m`,
    })) });
  },

  // 本次开几套
  changeUnits(event) {
    const delta = Number(event.currentTarget.dataset.delta || 0);
    this.setUnits(this.data.unitQty + delta);
  },
  unitsInput(event) { this.setUnits(parseInt(event.detail.value, 10) || 1); },
  setUnits(value) {
    const next = Math.max(1, Math.min(this.data.remaining || 1, value));
    if (next === this.data.unitQty) { this.setData({ unitQty: next }); return; }
    this.setData({ unitQty: next, groups: [], planChecked: false, planConfirmed: false });
    this.decorateRequirements();
  },

  // 选原料
  openSources() {
    if (this.data.busy) return;
    const picked = {};
    this.data.sources.forEach(row => {
      const key = row.original_source_key || row.source_key;
      if (picked[key]) picked[key].qty = String(Number(picked[key].qty) + Number(row.qty));
      else picked[key] = Object.assign({}, row, { source_key: key, qty: String(row.qty) });
    });
    this.setData({ panel: 'sources', picked, pickedCount: Object.keys(picked).length, keyword: '', page: 1, rows: [] });
    this.loadSources();
  },
  pickItem(event) { this.setData({ itemIndex: Number(event.detail.value), page: 1 }); this.loadSources(); },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  searchSources() { this.setData({ page: 1 }); this.loadSources(); },
  turnPage(event) {
    const target = this.data.page + Number(event.currentTarget.dataset.delta);
    if (target < 1 || target > this.data.lastPage || this.data.sourceLoading) return;
    this.setData({ page: target }); this.loadSources();
  },
  loadSources() {
    const item = this.data.items[this.data.itemIndex];
    if (!item) return;
    const sequence = this.sourceSequence = (this.sourceSequence || 0) + 1;
    this.setData({ sourceLoading: true, sourceError: '' });
    production.cuttingSources(this.data.woId, { item_id: item.item_id, keyword: this.data.keyword, page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sourceSequence) return;
      const result = view.rows(response);
      this.setData({ sourceLoading: false, page: result.page, lastPage: result.lastPage, total: result.total,
        rows: result.rows.map(row => Object.assign({}, row, { nameText: sourceName(row), sizeText: sizeText(row), unitText: sourceUnit(row),
          placeText: `${row.warehouse_name || ''} / ${row.location_name || ''}`, availableText: num(row.available_qty), countable: !row.physical_material_id })) });
    }).catch(error => { if (sequence === this.sourceSequence) this.setData({ sourceLoading: false, sourceError: view.errorText(error) }); });
  },
  toggleSource(event) {
    const row = this.data.rows.find(item => item.source_key === event.currentTarget.dataset.key);
    if (!row) return;
    const picked = Object.assign({}, this.data.picked);
    if (picked[row.source_key]) delete picked[row.source_key];
    else picked[row.source_key] = Object.assign({}, row, { qty: row.unit_kind === 'm' ? String(Number(row.available_qty)) : '1' });
    this.setData({ picked, pickedCount: Object.keys(picked).length });
  },
  pickedQtyInput(event) { this.setData({ [`picked.${event.currentTarget.dataset.key}.qty`]: event.detail.value }); },
  confirmSources() {
    const sources = [];
    for (const row of Object.values(this.data.picked)) {
      const count = Number(row.qty);
      const max = row.physical_material_id ? 1 : Number(row.available_qty);
      if (!Number.isFinite(count) || count <= 0 || (row.unit_kind !== 'm' && !Number.isInteger(count)) || count > max + 0.000001) {
        wx.showToast({ title: `${sourceName(row)} 投入数量不对（最多 ${num(max)} ${sourceUnit(row)}）`, icon: 'none' });
        return;
      }
      // 管材逐根登记：每根是一条独立来源，方便分别记余料。
      if (row.unit_kind === 'root') {
        for (let i = 1; i <= count; i++) sources.push(Object.assign({}, row, { original_source_key: row.source_key, source_key: `${row.source_key}:${i}`, qty: '1' }));
      } else sources.push(Object.assign({}, row, { original_source_key: row.source_key, qty: String(count) }));
    }
    this.setData({ panel: '', sources: sources.map(row => Object.assign({}, row, { qtyText: `${row.qty} ${sourceUnit(row)}` })), groups: [], planChecked: false, planConfirmed: false });
  },

  // 算方案 / 校验改过的方案
  planPayload(assignments) {
    const payload = {
      operation_id: this.data.opId, unit_qty: this.data.unitQty,
      sources: this.data.sources.map(row => ({ source_key: row.source_key, item_id: row.item_id, inventory_balance_id: row.inventory_balance_id,
        physical_material_id: row.physical_material_id || null, source_version: row.source_version, qty: String(row.qty) })),
    };
    if (assignments) payload.assignments = assignments;
    return payload;
  },
  groupAssignments() {
    const rows = [];
    this.data.groups.forEach(group => group.rows.forEach(row => rows.push({ source_key: group.source_key, part_key: row.part_key, qty: String(row.qty === '' ? 0 : row.qty) })));
    return rows;
  },
  calculate(event) {
    const retain = event && event.currentTarget && event.currentTarget.dataset.retain === '1';
    if (this.data.busy || !this.data.sources.length) return;
    this.setData({ busy: true, planChecked: false, planConfirmed: false });
    production.cuttingPreview(this.data.woId, this.planPayload(retain ? this.groupAssignments() : null)).then(response => {
      const plan = response.data || response;
      const groups = (plan.summaries || []).map(summary => {
        const source = (plan.sources || []).find(row => row.source_key === summary.source_key) || {};
        const rows = (plan.assignments || []).filter(row => row.source_key === summary.source_key).map(row => ({ part_key: row.part_key, qty: String(Number(row.qty)) }));
        this.data.requirements.filter(part => Number(part.item_id) === Number(source.item_id)).forEach(part => {
          if (!rows.some(row => row.part_key === part.part_key)) rows.push({ part_key: part.part_key, qty: '0' });
        });
        return { source_key: summary.source_key, name: sourceName(source), sizeText: sizeText(source),
          summaryText: `投入 ${num(summary.input_measure)} ${measureUnit(summary.measure_unit)} · 预计余料 ${num(summary.suggested_remnant)} ${measureUnit(summary.measure_unit)}`,
          rows: rows.map(row => Object.assign(row, this.partInfo(row.part_key))) };
      });
      this.setData({ groups, planChecked: true });
    }).catch(error => wx.showModal({ title: '方案没通过', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  partInfo(key) {
    const part = (this.parts || {})[key];
    return part ? { partName: part.part_name, partSize: requirementText(part), partUnit: partUnit(part) } : { partName: key, partSize: '', partUnit: '件' };
  },
  groupQtyInput(event) {
    const { g, r } = event.currentTarget.dataset;
    this.setData({ [`groups[${g}].rows[${r}].qty`]: event.detail.value, planChecked: false, planConfirmed: false });
  },
  togglePlanConfirmed() { if (this.data.planChecked) this.setData({ planConfirmed: !this.data.planConfirmed }); },
  // 同一内容重试沿用原请求编号，网络断了重提不会领两次料。
  commandKey(name, payload) {
    const signature = JSON.stringify(payload);
    this.commands = this.commands || {};
    if (!this.commands[name] || this.commands[name].signature !== signature) this.commands[name] = { signature, key: keyOf() };
    return this.commands[name].key;
  },
  issue() {
    if (this.data.busy || !this.data.planChecked || !this.data.planConfirmed) return;
    const payload = Object.assign(this.planPayload(this.groupAssignments()), { expected_version: this.data.version });
    payload.idempotency_key = this.commandKey('issue', payload);
    wx.showModal({ title: '确认领料', content: `按这个方案从库存领出 ${this.data.sources.length} 条原料开 ${this.data.unitQty} 套？领出后切完再登记产出和余料。`, confirmText: '确认领料',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingIssue(this.data.woId, payload).then(response => {
          this.commands.issue = null;
          wx.showToast({ title: '原料已领出，切完登记产出', icon: 'none', duration: 2500 });
          this.setData({ sources: [], groups: [], planChecked: false, planConfirmed: false });
          const batch = response.data;
          return this.load().then(() => { if (batch && batch.id) this.openBatchById(batch.id); });
        }).catch(error => wx.showModal({ title: '领料没成功', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },

  // 登记产出与余料
  openBatch(event) { this.openBatchById(Number(event.currentTarget.dataset.id)); },
  openBatchById(id) {
    const batch = this.data.batches.find(row => Number(row.id) === Number(id));
    if (!batch) return;
    const settled = batch.status === 'settled';
    this.batchSource = {};
    (batch.input_lines || []).forEach(line => { this.batchSource[line.source_key] = line; });
    const actual = clone(settled ? batch.actual_outputs || [] : batch.planned_outputs || []).map(row => Object.assign({ rowKey: `${row.source_key}|${row.part_key}`, source_key: row.source_key, part_key: row.part_key, qty: String(Number(row.qty)), per_piece_measure: row.per_piece_measure },
      this.partInfo(row.part_key), { sourceName: sourceName(this.batchSource[row.source_key]) }));
    const remnants = settled
      ? clone(batch.remnants || []).map(row => Object.assign(row, { sourceName: sourceName(this.batchSource[row.source_key]), unitText: measureUnit(row.measure_unit), measure_qty: num(row.measure_qty) }))
      : (batch.summaries || []).map(summary => {
        const source = this.batchSource[summary.source_key] || {};
        return { source_key: summary.source_key, sourceName: sourceName(source), unitText: measureUnit(summary.measure_unit), measure_qty: num(summary.suggested_remnant),
          warehouse_id: source.warehouse_id, warehouse_name: source.warehouse_name, location_id: source.location_id, location_name: source.location_name };
      });
    this.setData({ panel: 'batch', batch, actualUnits: Number(batch.completed_unit_qty || batch.unit_qty), actual, remnants, remark: '', confirmed: false });
  },
  changeActualUnits(event) {
    const max = Number(this.data.batch.unit_qty || 1);
    const value = event.type === 'blur' ? (parseInt(event.detail.value, 10) || 1) : this.data.actualUnits + Number(event.currentTarget.dataset.delta || 0);
    this.setData({ actualUnits: Math.max(1, Math.min(max, value)), confirmed: false });
  },
  actualQtyInput(event) {
    this.setData({ [`actual[${Number(event.currentTarget.dataset.index)}].qty`]: event.detail.value, confirmed: false });
    this.suggestRemnants();
  },
  // 产出改了，余料建议跟着算：投入 − 实际产出。
  suggestRemnants() {
    const remnants = this.data.remnants.map(row => {
      const source = this.batchSource[row.source_key] || {};
      const output = this.data.actual.filter(a => a.source_key === row.source_key)
        .reduce((sum, a) => sum + Number(a.qty || 0) * Number(a.per_piece_measure || 0), 0);
      return Object.assign({}, row, { measure_qty: num(Math.max(0, Number(source.input_measure || 0) - output)) });
    });
    this.setData({ remnants });
  },
  remnantInput(event) { this.setData({ [`remnants[${Number(event.currentTarget.dataset.index)}].measure_qty`]: event.detail.value, confirmed: false }); },
  remarkInput(event) { this.setData({ remark: event.detail.value }); },
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
  toggleConfirmed() { this.setData({ confirmed: !this.data.confirmed }); },
  settle() {
    const batch = this.data.batch;
    if (this.data.busy || !batch || batch.status !== 'issued') return;
    if (!this.data.confirmed) { wx.showToast({ title: '先勾选已核对产出和余料', icon: 'none' }); return; }
    const bad = this.data.remnants.find(row => !/^\d+(?:\.\d+)?$/.test(String(row.measure_qty)) || (Number(row.measure_qty) > 0 && (!row.warehouse_id || !row.location_id)));
    if (bad) { wx.showToast({ title: `${bad.sourceName}：填余料数量并选回库库位`, icon: 'none' }); return; }
    const payload = {
      expected_version: batch.business_version, unit_qty: this.data.actualUnits, confirmed: true, remark: this.data.remark.trim() || undefined,
      assignments: this.data.actual.map(row => ({ source_key: row.source_key, part_key: row.part_key, qty: String(row.qty === '' ? 0 : row.qty) })),
      remnants: this.data.remnants.map(row => ({ source_key: row.source_key, measure_qty: String(row.measure_qty), warehouse_id: row.warehouse_id, location_id: row.location_id })),
    };
    payload.idempotency_key = this.commandKey(`settle${batch.id}`, payload);
    this.setData({ busy: true });
    production.cuttingSettle(batch.id, payload).then(() => {
      this.commands[`settle${batch.id}`] = null;
      wx.showToast({ title: '产出和余料已登记', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      return this.load();
    }).catch(error => wx.showModal({ title: '登记没成功', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  closePanel() { if (!this.data.busy) this.setData({ panel: '' }); },
  stop() {},
});
