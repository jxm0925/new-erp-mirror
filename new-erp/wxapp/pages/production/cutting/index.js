// 工序内随单开料：本工序使用板材／管材时，接单后在此领用原料、计算排料方案，切割完成后登记实际产出与余料。
const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const num = value => {
  const n = Number(value || 0);
  return String(Math.round(n * 100000000) / 100000000);
};
const measureUnit = unit => (unit === 'm2' ? 'm²' : (unit || ''));
const sourceUnit = row => row.mode === 'length' ? '米' : 'm²';
const sourceMeasure = (row, qty) => num(Number(qty || 0) * Number(row.measure_qty || 0));
const stockText = (row, qty) => ['root', 'sheet'].includes(row.unit_kind) ? `${num(qty)} ${row.unit_kind === 'root' ? '根' : '张'}` : '';
const meters = value => `${num(value)} 米`;
const clone = value => JSON.parse(JSON.stringify(value));
const keyOf = () => `wxcut-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 8)}`;
function sizeText(row) {
  const d = row.dimensions || {};
  if (row.mode === 'length') return `${row.material_form === 'REMNANT' ? '余料 · ' : ''}${row.unit_kind === 'root' ? `每根 ${meters(row.measure_qty)}` : '按米计'}`;
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
function sourceInfo(source) {
  const row = source || {};
  const number = /^balance:\d+:(\d+)$/.exec(row.source_key || '');
  const ordinal = number && ['root', 'sheet'].includes(row.unit_kind) ? `第 ${number[1]} ${row.unit_kind === 'root' ? '根' : '张'}` : '';
  return {
    sourceTitle: row.item_name || '原料',
    sourceSpec: [row.specification || '规格未记录', row.material_grade].filter(Boolean).join(' · '),
    sourceCode: row.item_code || '',
    sourceRef: [row.physical_no ? `编号 ${row.physical_no}` : '', row.batch_no ? `批次 ${row.batch_no}` : '', ordinal].filter(Boolean).join(' · ') || row.source_key || '',
    inputText: row.input_measure !== undefined ? `${num(row.input_measure)} ${sourceUnit(row)}` : `${sourceMeasure(row, row.qty)} ${sourceUnit(row)}`,
    sourcePlace: [row.warehouse_name, row.location_name].filter(Boolean).join(' / '),
  };
}

Page({
  openRemnantReceipt(event) { const id = Number(event.currentTarget.dataset.id); if (id) wx.navigateTo({ url: '/pages/warehouse/inbound/index?kind=cutting_remnant&id=' + id }); },
  data: {
    woId: 0, opId: 0, opName: '', loading: true, busy: false, error: '', ready: false,
    requirements: [], remaining: 0, canIssue: false, version: 0, batches: [],
    unitQty: 1, sources: [], groups: [], planChecked: false, planConfirmed: false,
    panel: '', items: [], itemIndex: 0, keyword: '', rows: [], shownSources: [], showPicked: false, pageSelected: false,
    page: 1, lastPage: 1, total: 0, sourceLoading: false, autoLoading: false, sourceError: '', picked: {}, pickedCount: 0,
    batch: null, actualUnits: 1, actual: [], remnants: [], remark: '', confirmed: false, locatorOpen: false, locatorIndex: -1, locatorValue: {},
  },
  onLoad(options) {
    const opName = decodeURIComponent(options.name || '');
    this.setData({ woId: Number(options.wo || 0), opId: Number(options.op || 0), opName });
    wx.setNavigationBarTitle({ title: opName ? `随单开料 · ${opName}` : '随单开料' });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  onUnload() { this.sourceSequence = (this.sourceSequence || 0) + 1; this.recommendSequence = (this.recommendSequence || 0) + 1; },

  load() {
    this.setData({ loading: true, error: '' });
    return production.cutting(this.data.woId, { operation_id: this.data.opId, per_page: 10 }).then(response => {
      const ctx = response.data || response;
      const requirements = (ctx.requirements || []).filter(row => Number(row.operation_id) === this.data.opId);
      const remaining = Number((ctx.remaining_unit_qty_by_operation || {})[this.data.opId] || 0);
      this.parts = {};
      requirements.forEach(row => { this.parts[row.part_key] = row; });
      const items = [];
      requirements.forEach(row => { if (!items.some(item => item.item_id === row.item_id)) items.push({ item_id: row.item_id, label: [row.item_code, row.item_name, row.specification].filter(Boolean).join(' / ') }); });
      if (items.length > 1) items.unshift({ item_id: 0, label: '全部所需原料' });
      const batches = ((ctx.batches || {}).data || []).map(batch => Object.assign({}, batch, {
        statusText: batch.status === 'settled' ? '已结算' : batch.status === 'awaiting_confirm' ? '待登记结算' : '待登记产出与余料',
        editable: batch.status === 'issued' || Boolean(batch.can_resubmit),
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

  changeUnits(event) {
    const delta = Number(event.currentTarget.dataset.delta || 0);
    this.setUnits(this.data.unitQty + delta);
  },
  unitsInput(event) { this.setUnits(parseInt(event.detail.value, 10) || 1); },
  setUnits(value) {
    const next = Math.max(1, Math.min(this.data.remaining || 1, value));
    if (next === this.data.unitQty) { this.setData({ unitQty: next }); return; }
    this.recommended = null;
    this.autoAssignments = null;
    this.setData({ unitQty: next, groups: [], planChecked: false, planConfirmed: false });
    this.decorateRequirements();
  },

  openSources() {
    if (this.data.busy) return;
    const picked = {};
    this.data.sources.forEach(row => {
      const key = row.original_source_key || row.source_key;
      if (picked[key]) picked[key].qty = String(Number((Number(picked[key].qty) + Number(row.qty)).toFixed(4)));
      else picked[key] = Object.assign({}, row, { source_key: key, qty: String(row.qty) });
    });
    Object.values(picked).forEach(row => { row.measureInput = sourceMeasure(row, row.qty); });
    this.setData({ panel: 'sources', itemIndex: 0, showPicked: false, keyword: '', page: 1, rows: [] });
    this.updatePicked(picked);
    this.loadSources();
    if (!Object.keys(picked).length) this.recommendSources();
  },
  sourceRow(row) {
    const needed = this.data.requirements.filter(part => Number(part.item_id) === Number(row.item_id))
      .reduce((sum, part) => sum + Number(part.per_unit_measure || 0) * this.data.unitQty, 0);
    return Object.assign({}, row, { nameText: sourceName(row), sizeText: sizeText(row), unitText: sourceUnit(row),
      placeText: `${row.warehouse_name || ''} / ${row.location_name || ''}`, availableText: sourceMeasure(row, row.available_qty),
      needText: `${num(needed)} ${sourceUnit(row)}`, countable: !row.physical_material_id });
  },
  pickSignature(picked) {
    return JSON.stringify([this.data.unitQty, Object.keys(picked).sort().map(key => [key, Number(picked[key].qty), picked[key].source_version])]);
  },
  recommendSources() {
    if (this.data.autoLoading || this.data.busy) return;
    const sequence = this.recommendSequence = (this.recommendSequence || 0) + 1;
    const unitQty = this.data.unitQty;
    this.setData({ autoLoading: true });
    production.cuttingRecommend(this.data.woId, { operation_id: this.data.opId, unit_qty: unitQty }).then(response => {
      if (sequence !== this.recommendSequence || this.data.panel !== 'sources' || unitQty !== this.data.unitQty) return;
      const plan = response.data || response;
      const picked = {};
      (plan.sources || []).forEach(row => {
        const key = row.original_source_key || row.source_key;
        if (picked[key]) picked[key].qty = String(Number((Number(picked[key].qty) + Number(row.qty)).toFixed(4)));
        else picked[key] = this.sourceRow(Object.assign({}, row, { source_key: key }));
      });
      Object.values(picked).forEach(row => { row.measureInput = sourceMeasure(row, row.qty); });
      this.recommended = { signature: this.pickSignature(picked), assignments: plan.assignments };
      this.setData({ showPicked: true });
      this.updatePicked(picked);
    }).catch(error => {
      if (sequence === this.recommendSequence && this.data.panel === 'sources') view.modal(this, { theme: 'warn', title: '自动选料未完成', content: view.errorText(error), showCancel: false });
    }).finally(() => { if (sequence === this.recommendSequence) this.setData({ autoLoading: false }); });
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
    this.setData({ sourceLoading: true, sourceError: '', rows: [] });
    this.updatePicked(this.data.picked);
    production.cuttingSources(this.data.woId, { operation_id: this.data.opId, item_id: item.item_id || undefined, keyword: this.data.keyword, page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sourceSequence || this.data.panel !== 'sources') return;
      const result = view.rows(response);
      this.setData({ sourceLoading: false, page: result.page, lastPage: result.lastPage, total: result.total,
        rows: result.rows.map(row => this.sourceRow(row)) });
      this.updatePicked(this.data.picked);
    }).catch(error => { if (sequence === this.sourceSequence && this.data.panel === 'sources') this.setData({ sourceLoading: false, sourceError: view.errorText(error) }); });
  },
  updatePicked(picked) {
    const selected = Object.values(picked).map(row => Object.assign({}, row, {
      measureInput: row.measureInput === undefined ? sourceMeasure(row, row.qty) : row.measureInput, stockText: stockText(row, row.qty) }));
    const normalized = {};
    selected.forEach(row => { normalized[row.source_key] = row; });
    const available = this.data.rows.filter(row => Number(row.available_qty) > 0);
    this.setData({ picked: normalized, pickedCount: selected.length, shownSources: this.data.showPicked ? selected : this.data.rows,
      pageSelected: available.length > 0 && available.every(row => Boolean(picked[row.source_key])) });
  },
  showSelectedSources() {
    this.setData({ showPicked: !this.data.showPicked });
    this.updatePicked(this.data.picked);
  },
  sourcePick(row) {
    const qty = String(Math.min(1, Number(row.available_qty)));
    return Object.assign({}, row, { qty, measureInput: sourceMeasure(row, qty) });
  },
  togglePageSources() {
    if (this.data.sourceLoading || this.data.autoLoading || this.data.showPicked) return;
    const picked = Object.assign({}, this.data.picked);
    this.data.rows.filter(row => Number(row.available_qty) > 0).forEach(row => {
      if (this.data.pageSelected) delete picked[row.source_key];
      else if (!picked[row.source_key]) picked[row.source_key] = this.sourcePick(row);
    });
    this.updatePicked(picked);
  },
  toggleSource(event) {
    if (this.data.autoLoading) return;
    const row = this.data.shownSources.find(item => item.source_key === event.currentTarget.dataset.key);
    if (!row) return;
    const picked = Object.assign({}, this.data.picked);
    if (picked[row.source_key]) delete picked[row.source_key];
    else if (Number(row.available_qty) > 0) picked[row.source_key] = this.sourcePick(row);
    this.updatePicked(picked);
  },
  pickedQtyInput(event) {
    if (this.data.autoLoading) return;
    const key = event.currentTarget.dataset.key;
    const row = this.data.picked[key];
    if (!row) return;
    const measureInput = event.detail.value;
    const measure = Number(measureInput);
    const factor = Number(row.measure_qty);
    const qty = Number((measure / factor).toFixed(4));
    const exact = Number.isFinite(measure) && factor > 0 && Math.abs(qty * factor - measure) < 0.00000001;
    const picked = Object.assign({}, this.data.picked, { [key]: Object.assign({}, row, { measureInput, qty: exact ? String(qty) : '' }) });
    this.updatePicked(picked);
  },
  confirmSources() {
    if (this.data.autoLoading) return;
    const sources = [];
    for (const row of Object.values(this.data.picked)) {
      const count = Number(row.qty);
      const max = row.physical_material_id ? 1 : Number(row.available_qty);
      const fractionalRoot = row.unit_kind === 'root' && !row.physical_material_id;
      if (!Number.isFinite(count) || count <= 0 || (!['m', 'm2'].includes(row.unit_kind) && !fractionalRoot && !Number.isInteger(count)) || count > max + 0.000001
        || (fractionalRoot && !/^\d+(?:\.\d{1,4})?$/.test(String(count)))) {
        view.modal(this, { theme: 'warn', title: '请核对领用量', content: `${row.item_name}（${sourceName(row)}）最多可用 ${sourceMeasure(row, max)} ${sourceUnit(row)}；领用量须能按来源长度或面积准确换算，实物板材及按张管理的板材须整张领用。`, showCancel: false });
        return;
      }
      const split = !row.physical_material_id && ['root', 'sheet'].includes(row.unit_kind);
      if (sources.length + (split ? Math.ceil(count) : 1) > 1000) {
        wx.showToast({ title: '单次最多选用 1000 条来源，请分批开料', icon: 'none' }); return;
      }
      if (split) {
        for (let i = 0; i < count; i++) sources.push(Object.assign({}, row, { original_source_key: row.source_key, source_key: `${row.source_key}:${i + 1}`, qty: String(Math.min(1, Number((count - i).toFixed(4)))) }));
      } else sources.push(Object.assign({}, row, { original_source_key: row.source_key, qty: String(count) }));
    }
    this.autoAssignments = this.recommended && this.recommended.signature === this.pickSignature(this.data.picked) ? this.recommended.assignments : null;
    this.setData({ panel: '', sources: sources.map(row => Object.assign({}, row, sourceInfo(row), { qtyText: `${sourceMeasure(row, row.qty)} ${sourceUnit(row)}${stockText(row, row.qty) ? `（${stockText(row, row.qty)}）` : ''}` })), groups: [], planChecked: false, planConfirmed: false });
  },

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
    production.cuttingPreview(this.data.woId, this.planPayload(retain ? this.groupAssignments() : this.autoAssignments)).then(response => {
      const plan = response.data || response;
      const groups = (plan.summaries || []).map(summary => {
        const source = (plan.sources || []).find(row => row.source_key === summary.source_key) || {};
        const rows = (plan.assignments || []).filter(row => row.source_key === summary.source_key).map(row => ({ part_key: row.part_key, qty: String(Number(row.qty)) }));
        this.data.requirements.filter(part => Number(part.item_id) === Number(source.item_id)).forEach(part => {
          if (!rows.some(row => row.part_key === part.part_key)) rows.push({ part_key: part.part_key, qty: '0' });
        });
        return { ...sourceInfo(source), source_key: summary.source_key, name: sourceName(source), sizeText: sizeText(source),
          summaryText: `投入 ${num(summary.input_measure)} ${measureUnit(summary.measure_unit)} · 预计余料 ${num(summary.suggested_remnant)} ${measureUnit(summary.measure_unit)}`,
          rows: rows.map(row => Object.assign(row, this.partInfo(row.part_key))) };
      });
      this.setData({ groups, planChecked: true });
    }).catch(error => view.modal(this, { theme: 'warn', title: '排料校验未通过', content: view.errorText(error), showCancel: false }))
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
    view.modal(this, {
      title: '确认领料开料',
      subtitle: '切割完成后请及时登记实际产出与余料',
      kvRows: [
        { k: '本次开料套数', v: `${this.data.unitQty} 套`, red: true },
        { k: '领用原料条数', v: `${this.data.sources.length} 条` },
      ],
      confirmText: '确认领料',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingIssue(this.data.woId, payload).then(response => {
          this.commands.issue = null;
          wx.showToast({ title: '原料已领出，完成后请登记产出', icon: 'none', duration: 2500 });
          this.setData({ sources: [], groups: [], planChecked: false, planConfirmed: false });
          const batch = response.data;
          return this.load().then(() => { if (batch && batch.id) this.openBatchById(batch.id); });
        }).catch(error => view.modal(this, { theme: 'warn', title: '领料失败', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },

  openBatch(event) { this.openBatchById(Number(event.currentTarget.dataset.id)); },
  openBatchById(id) {
    const batch = this.data.batches.find(row => Number(row.id) === Number(id));
    if (!batch) return;
    const settled = ['settled', 'awaiting_confirm'].includes(batch.status);
    this.batchSource = {};
    (batch.input_lines || []).forEach(line => { this.batchSource[line.source_key] = line; });
    const actual = clone(settled ? batch.actual_outputs || [] : batch.planned_outputs || []).map(row => Object.assign({ rowKey: `${row.source_key}|${row.part_key}`, source_key: row.source_key, part_key: row.part_key, qty: String(Number(row.qty)), per_piece_measure: row.per_piece_measure },
      this.partInfo(row.part_key), sourceInfo(this.batchSource[row.source_key]), { sourceName: sourceName(this.batchSource[row.source_key]) }));
    const remnants = settled
      ? clone((batch.remnants || []).length ? batch.remnants : batch.remnant_receipt?.requested_lines || []).map(row => Object.assign(row, sourceInfo(this.batchSource[row.source_key]), { sourceName: sourceName(this.batchSource[row.source_key]), unitText: measureUnit(row.measure_unit), measure_qty: String(row.measure_qty || '0') }))
      : (batch.summaries || []).map(summary => {
        const source = this.batchSource[summary.source_key] || {};
        return { ...sourceInfo(source), source_key: summary.source_key, sourceName: sourceName(source), unitText: measureUnit(summary.measure_unit), measure_qty: num(summary.suggested_remnant),
          warehouse_id: source.warehouse_id, warehouse_name: source.warehouse_name, location_id: source.location_id, location_name: source.location_name };
      });
    remnants.forEach(row => {
      const output = actual.filter(line => line.source_key === row.source_key).reduce((sum, line) => sum + Number(line.qty || 0) * Number(line.per_piece_measure || 0), 0);
      row.outputText = `${num(output)} ${sourceUnit(this.batchSource[row.source_key] || {})}`;
    });
    this.setData({ panel: 'batch', batch, actualUnits: Number(batch.completed_unit_qty || batch.unit_qty), actual, remnants, remark: batch.remark || '', confirmed: false });
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
  suggestRemnants() {
    const remnants = this.data.remnants.map(row => {
      const source = this.batchSource[row.source_key] || {};
      const output = this.data.actual.filter(a => a.source_key === row.source_key)
        .reduce((sum, a) => sum + Number(a.qty || 0) * Number(a.per_piece_measure || 0), 0);
      return Object.assign({}, row, { measure_qty: num(Math.max(0, Number(source.input_measure || 0) - output)), outputText: `${num(output)} ${sourceUnit(source)}` });
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
    if (this.data.busy || !batch || !batch.editable) return;
    if (!this.data.confirmed) { wx.showToast({ title: '请先勾选确认已核对产出与余料', icon: 'none' }); return; }
    const bad = this.data.remnants.find(row => !/^\d+(?:\.\d+)?$/.test(String(row.measure_qty)) || (Number(row.measure_qty) > 0 && (!row.warehouse_id || !row.location_id)));
    if (bad) { wx.showToast({ title: `${bad.sourceName}：请填写余料数量并选择回库库位`, icon: 'none' }); return; }
    const payload = {
      expected_version: batch.business_version, unit_qty: this.data.actualUnits, confirmed: true, remark: this.data.remark.trim() || undefined,
      assignments: this.data.actual.map(row => ({ source_key: row.source_key, part_key: row.part_key, qty: String(row.qty === '' ? 0 : row.qty) })),
      remnants: this.data.remnants.map(row => ({ source_key: row.source_key, measure_qty: String(row.measure_qty), warehouse_id: row.warehouse_id, location_id: row.location_id })),
    };
    payload.idempotency_key = this.commandKey(`settle${batch.id}`, payload);
    this.setData({ busy: true });
    production.cuttingSettle(batch.id, payload).then(() => {
      this.commands[`settle${batch.id}`] = null;
      wx.showToast({ title: '实际产出与余料已登记结算', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      return this.load();
    }).catch(error => view.modal(this, { theme: 'warn', title: '登记失败', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  closePanel() {
    if (this.data.busy) return;
    this.sourceSequence = (this.sourceSequence || 0) + 1;
    this.recommendSequence = (this.recommendSequence || 0) + 1;
    this.setData({ panel: '', autoLoading: false });
  },
  stop() {},
});
