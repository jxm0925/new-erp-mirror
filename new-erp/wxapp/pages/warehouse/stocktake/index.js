const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const document = require('../../../utils/warehouse-document');
function progress(counted, total) { return Number(total) > 0 ? Math.min(100, Math.round(Number(counted || 0) / Number(total) * 100)) : 0; }
Page({
  data: { id: 0, status: 'counting', keyword: '', page: 1, lastPage: 1, total: 0, rows: [], stats: {}, loading: true, saving: false,
    error: '', formError: '', header: null, lines: [], summary: {}, progress: 0, lineStatus: '', locator: {}, locatorOpen: false,
    drafts: {}, canCount: false, canComplete: false, canAmount: false, entry: null, entryRows: [], entryPage: 1, entryLast: 1, entryTotal: 0,
    entryKeyword: '', entryLoading: false, entryError: '', entryChanges: {}, markAll: null, newEntries: [], deleteIds: [], unnumbered: '',
    unnumberedChanged: false, supplementNo: '', physical: {}, entryFilterIndex: 0, entryFilters: [{ label: '全部', value: '' }, { label: '未确认', value: 'undecided' }, { label: '不在', value: 'missing' }, { label: '补录', value: 'new' }, { label: '业务占用', value: 'locked' }],
    pendingCounts: false, pendingComplete: false, confirmation: null },
  onLoad(options) { const can = page.permissions(); this.setData({ id: Number(options.id || 0), canCount: can('inventory.stocktake.create'), canComplete: can('inventory.stocktake.complete'), canAmount: can('inventory.amount.view') }); },
  onShow() { if (!page.resumingScan(this)) this.load(); }, onUnload() { this.sequence = (this.sequence || 0) + 1; this.entrySequence = (this.entrySequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() { return this.data.id ? this.loadDetail() : this.loadList(); },
  loadList() {
    wx.setNavigationBarTitle({ title: '我的盘点' });
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    return warehouse.stocktakes({ status: this.data.status, keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return; const result = page.rows(response);
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.loadList(); }
      this.setData({ rows: result.rows.map(row => Object.assign({}, row, { dateText: String(row.stocktake_date || row.created_at || '').slice(0, 10), progress: progress(row.counted_lines_count, row.line_count) })),
        total: result.total, page: result.page, lastPage: result.lastPage, stats: response.stats || {}, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: page.errorText(error) }); });
  },
  loadDetail() {
    wx.setNavigationBarTitle({ title: '盘点录入' });
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    return warehouse.document('stocktake', this.data.id, { page: this.data.page, per_page: 10, location_id: this.data.locator.location_id,
      keyword: this.data.keyword.trim(), line_status: this.data.lineStatus || undefined }).then(response => {
      if (sequence !== this.sequence) return; const body = response.data || response; const result = page.rows(body.lines); const summary = body.header.summary || {};
      // 相机返回及刷新不能把旧草稿升级为他人的新版本，否则下一次保存会覆盖并发修改。
      const draftVersion = this.data.header && (Object.keys(this.data.drafts).length || this.data.entry) ? this.data.header.business_version : body.header.business_version;
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.loadDetail(); }
      const lines = result.rows.map(row => {
        const entryStats = row.entry_stats || {}; const draft = this.data.drafts[row.id];
        return Object.assign({}, row, { bookText: page.quantity(row.book_qty), countedText: page.quantity(draft && Object.prototype.hasOwnProperty.call(draft, 'counted_qty') ? draft.counted_qty : row.counted_qty),
          remarkText: draft ? draft.remark : row.remark || '', confirmed: Number(entryStats.present || 0) + Number(entryStats.missing || 0),
          entryCount: Number(entryStats.book || 0), differenceText: page.quantity(row.difference_qty) });
      });
      this.setData({ header: Object.assign({}, body.header, { business_version: draftVersion, dateText: String(body.header.stocktake_date || body.header.created_at || '').slice(0, 10) }), lines, summary, progress: progress(summary.counted_lines, summary.total_lines), page: result.page, lastPage: result.lastPage, total: result.total, loading: false,
        canCount: (body.actions || []).includes('stocktake.counts'), canComplete: (body.actions || []).includes('stocktake.complete'),
        pendingCounts: !!warehouse.pending('stocktake.counts', this.data.id), pendingComplete: !!warehouse.pending('stocktake.complete', this.data.id) });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  input(event) { this.setData({ [event.currentTarget.dataset.field || 'keyword']: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  switchStatus(event) { this.setData({ status: event.currentTarget.dataset.status, page: 1 }); this.loadList(); },
  open(event) { this.setData({ id: Number(event.currentTarget.dataset.id), page: 1, keyword: '', locator: {}, drafts: {}, formError: '' }); this.loadDetail(); },
  closeDetail() { if (Object.keys(this.data.drafts).length) { this.setData({ confirmation: { mode: 'back', title: '尚未保存本次录入', message: '返回将放弃本次未保存的实盘数。' } }); return; } this.backToList(); },
  backToList() { this.setData({ id: 0, page: 1, header: null, keyword: '', drafts: {}, locator: {}, lineStatus: '', formError: '' }); this.loadList(); },
  turn(event) { const target = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && !this.data.saving && target > 0 && target <= this.data.lastPage) { this.setData({ page: target }); this.load(); } },
  lineFilter(event) { this.setData({ lineStatus: event.currentTarget.dataset.status || '', page: 1 }); this.loadDetail(); },
  openLocator() { this.setData({ locatorOpen: true }); }, closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) {
    const result = event.detail;
    if (Number(result.warehouse_id) !== Number(this.data.header.warehouse_id) || (this.data.header.location_id && Number(result.location_id) !== Number(this.data.header.location_id))) return this.setData({ formError: '该库位不在本次盘点范围内' });
    this.setData({ locator: result, locatorOpen: false, page: 1 }); this.loadDetail();
  },
  scanLocation(event) {
    const result = event.detail.result;
    if (Number(result.warehouse_id) !== Number(this.data.header.warehouse_id) || (this.data.header.location_id && Number(result.location_id) !== Number(this.data.header.location_id))) return this.setData({ formError: '该库位不在本次盘点范围内' });
    this.setData({ locator: result, page: 1, formError: '' }); this.loadDetail();
  },
  clearLocation() { this.setData({ locator: {}, page: 1 }); this.loadDetail(); },
  lineInput(event) {
    const id = Number(event.currentTarget.dataset.id); const line = this.data.lines.find(row => Number(row.id) === id); if (!line) return;
    const draft = Object.assign({ id, counted_qty: line.counted_qty, remark: line.remark || '' }, this.data.drafts[id] || {});
    draft[event.currentTarget.dataset.field] = event.detail.value;
    this.setData({ ['drafts.' + id]: draft });
  },
  async saveCounts(extraLines, closeEntry) {
    if (this.data.saving) return false;
    const lines = Object.values(this.data.drafts).map(row => Object.assign({}, row));
    (extraLines || []).forEach(row => { const index = lines.findIndex(value => Number(value.id) === Number(row.id)); if (index < 0) lines.push(row); else lines[index] = Object.assign({}, lines[index], row); });
    if (!lines.length && !this.data.pendingCounts) { this.setData({ formError: '请先录入实盘结果' }); return false; }
    for (const line of lines) if (line.counted_qty !== undefined && line.counted_qty !== null && line.counted_qty !== '' && (!Number.isFinite(Number(line.counted_qty)) || Number(line.counted_qty) < 0)) { this.setData({ formError: '实盘数必须为大于或等于零的数字' }); return false; }
    this.setData({ saving: true, formError: '', entryError: '' });
    try {
      await warehouse.submit('stocktake.counts', this.data.id, { expected_version: this.data.header.business_version, lines });
      this.setData({ drafts: {}, pendingCounts: false });
      if (closeEntry) this.setData({ entry: null });
      wx.showToast({ title: '实盘结果已保存', icon: 'success' }); await this.loadDetail(); return true;
    } catch (error) {
      const message = page.errorText(error); this.setData({ formError: message, entryError: message, pendingCounts: !!warehouse.pending('stocktake.counts', this.data.id) });
      if (error.errors && error.errors.expected_version) this.setData({ confirmation: { mode: 'conflict', title: '盘点单已发生变化', message: '请重新打开并核对最新实盘结果后再录入。' } });
      return false;
    } finally { this.setData({ saving: false }); }
  },
  save() { return this.saveCounts([], false); },
  requestComplete() {
    if (Object.keys(this.data.drafts).length) return this.setData({ formError: '请先保存本次录入，再完成盘点' });
    const uncounted = Number(this.data.summary.uncounted_lines || 0);
    this.setData({ confirmation: uncounted > 0 ? { mode: 'incomplete', title: '完成盘点？', message: '当前盘点仍有 ' + uncounted + ' 项未录入，请先完成核对。' }
      : { mode: 'complete', title: '完成盘点？', message: '完成后将按盘盈盘亏过账库存，并解除本次盘点冻结。' } });
  },
  async complete() {
    if (this.data.saving) return;
    this.setData({ saving: true, formError: '' });
    try { await warehouse.submit('stocktake.complete', this.data.id, {}); this.setData({ confirmation: null }); wx.showToast({ title: '盘点已完成', icon: 'success' }); await this.loadDetail(); }
    catch (error) { this.setData({ confirmation: null, formError: page.errorText(error), pendingComplete: !!warehouse.pending('stocktake.complete', this.data.id) }); }
    finally { this.setData({ saving: false }); }
  },
  confirmDialog() {
    const confirmation = this.data.confirmation; if (!confirmation || this.data.saving) return;
    if (confirmation.mode === 'complete') return this.complete();
    this.setData({ confirmation: null });
    if (confirmation.mode === 'back') return this.backToList();
    if (confirmation.mode === 'entry-discard') this.setData({ entry: null });
    if (confirmation.mode === 'conflict') { this.setData({ drafts: {}, entry: null }); return this.loadDetail(); }
    if (confirmation.mode === 'incomplete') { this.setData({ lineStatus: 'uncounted', page: 1 }); return this.loadDetail(); }
  },
  closeConfirmation() { if (!this.data.saving) this.setData({ confirmation: null }); },
  openEntries(event) {
    const line = this.data.lines.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (!line) return;
    this.setData({ entry: line, entryRows: [], entryPage: 1, entryKeyword: '', entryFilterIndex: 0, entryChanges: {}, markAll: null,
      newEntries: [], deleteIds: [], unnumbered: line.unnumbered_counted_qty === null || line.unnumbered_counted_qty === undefined ? '' : page.quantity(line.unnumbered_counted_qty),
      unnumberedChanged: false, supplementNo: '', physical: {}, entryError: '' }); this.loadEntries();
  },
  loadEntries() {
    if (!this.data.entry) return Promise.resolve();
    const sequence = this.entrySequence = (this.entrySequence || 0) + 1; const entryId = this.data.entry.id; this.setData({ entryLoading: true, entryError: '' });
    return warehouse.stocktakeEntries(this.data.id, entryId, { page: this.data.entryPage, per_page: 10, keyword: this.data.entryKeyword.trim(), filter: this.data.entryFilters[this.data.entryFilterIndex].value || undefined }).then(response => {
      if (sequence !== this.entrySequence || !this.data.entry || Number(this.data.entry.id) !== Number(entryId)) return;
      const result = page.rows(response); this.entryRawRows = result.rows;
      this.setData({ entryPage: result.page, entryLast: result.lastPage, entryTotal: result.total, entryLoading: false }); this.decorateEntries();
    }).catch(error => { if (sequence === this.entrySequence) this.setData({ entryLoading: false, entryError: page.errorText(error) }); });
  },
  decorateEntries() {
    this.setData({ entryRows: (this.entryRawRows || []).filter(row => !this.data.deleteIds.includes(Number(row.id))).map(row => {
      const present = Object.prototype.hasOwnProperty.call(this.data.entryChanges, row.id) ? this.data.entryChanges[row.id] : this.data.markAll !== null && !row.is_locked && !row.is_new ? this.data.markAll : row.counted_present;
      return Object.assign({}, row, { present, statusText: row.is_locked ? '业务占用' : row.is_new ? '补录' : present === true ? '在库' : present === false ? '不在' : '未确认', dimensionsText: document.dimensions(row.dimensions), costText: page.money(row.total_cost) });
    }) });
  },
  entryTurn(event) { const target = this.data.entryPage + Number(event.currentTarget.dataset.delta); if (!this.data.entryLoading && target > 0 && target <= this.data.entryLast) { this.setData({ entryPage: target }); this.loadEntries(); } },
  entrySearch() { this.setData({ entryPage: 1 }); this.loadEntries(); },
  entryFilter(event) { this.setData({ entryFilterIndex: Number(event.detail.value), entryPage: 1 }); this.loadEntries(); },
  present(event) {
    const row = this.data.entryRows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (!row || row.is_locked || row.is_new) return;
    this.setData({ ['entryChanges.' + row.id]: event.currentTarget.dataset.present === true || event.currentTarget.dataset.present === 'true' }); this.decorateEntries();
  },
  markAllEntries(event) { this.setData({ markAll: event.currentTarget.dataset.present === true || event.currentTarget.dataset.present === 'true', entryChanges: {} }); this.decorateEntries(); },
  removeEntry(event) { this.setData({ deleteIds: this.data.deleteIds.concat(Number(event.currentTarget.dataset.id)) }); this.decorateEntries(); },
  async scanEntry(event) {
    const result = event.detail.result; const entry = this.data.entry;
    if (!entry || Number(result.item_id) !== Number(entry.item_id)) return this.setData({ entryError: '扫描实物不属于当前盘点物料' });
    const code = result.serial_no || result.physical_no || result.code;
    this.setData({ entryKeyword: code, entryPage: 1, entryFilterIndex: 0 }); await this.loadEntries();
    if (!this.data.entry || Number(this.data.entry.id) !== Number(entry.id)) return;
    const row = this.data.entryRows.find(row => result.type === 'physical' ? Number(row.material_physical_id) === Number(result.id) : row.serial_no === code);
    if (!row) return this.setData({ entryError: '该编号不在此盘点行账面内，请核对库位与批次' });
    if (row.is_locked) return this.setData({ entryError: '该编号已被业务占用，按账面处理' });
    this.setData({ ['entryChanges.' + row.id]: true, entryError: '' }); this.decorateEntries();
  },
  unnumberedInput(event) { this.setData({ unnumbered: event.detail.value, unnumberedChanged: true }); },
  addSerial() {
    const serialNo = this.data.supplementNo.trim(); if (!serialNo) return this.setData({ entryError: '请输入账外编号' });
    if (this.data.newEntries.some(row => row.serial_no === serialNo)) return this.setData({ entryError: '该编号已在待补录清单中' });
    this.setData({ newEntries: this.data.newEntries.concat({ serial_no: serialNo }), supplementNo: '', entryError: '' });
  },
  physicalInput(event) { this.setData({ ['physical.' + event.currentTarget.dataset.field]: event.detail.value }); },
  addPhysical() {
    const physical = this.data.physical;
    if (!this.data.canAmount || !['length_mm', 'width_mm', 'thickness_mm'].every(field => /^\d{1,10}(\.\d{1,2})?$/.test(physical[field] || '') && Number(physical[field]) > 0)) return this.setData({ entryError: '请填写大于零的长、宽、厚，最多两位小数' });
    if (!/^\d{1,14}(\.\d{1,4})?$/.test(physical.total_cost || '')) return this.setData({ entryError: '请填写库存成本，最多四位小数' });
    this.setData({ newEntries: this.data.newEntries.concat({ dimensions: { length_mm: physical.length_mm, width_mm: physical.width_mm, thickness_mm: physical.thickness_mm }, total_cost: physical.total_cost, label: [physical.length_mm, physical.width_mm, physical.thickness_mm].join('×') + ' mm' }), physical: {}, entryError: '' });
  },
  removePending(event) { this.setData({ newEntries: this.data.newEntries.filter((row, index) => index !== Number(event.currentTarget.dataset.index)) }); },
  closeEntries() {
    if (this.data.saving) return;
    if (Object.keys(this.data.entryChanges).length || this.data.markAll !== null || this.data.newEntries.length || this.data.deleteIds.length || this.data.unnumberedChanged) return this.setData({ confirmation: { mode: 'entry-discard', title: '逐件结果尚未保存', message: '关闭将放弃这些修改。' } });
    this.setData({ entry: null }); this.entrySequence = (this.entrySequence || 0) + 1;
  },
  saveEntries() {
    const line = { id: this.data.entry.id, mark_all_present: this.data.markAll, entries: Object.entries(this.data.entryChanges).map(([id, present]) => ({ id: Number(id), counted_present: present })),
      delete_entry_ids: this.data.deleteIds, new_entries: this.data.newEntries.map(row => { const value = Object.assign({}, row); delete value.label; return value; }) };
    if (this.data.entry.count_mode === 'serial' && this.data.unnumberedChanged) {
      if (this.data.unnumbered !== '' && (!Number.isInteger(Number(this.data.unnumbered)) || Number(this.data.unnumbered) < 0)) return this.setData({ entryError: '无编号实盘数必须为非负整数' });
      line.unnumbered_counted_qty = this.data.unnumbered === '' ? null : Number(this.data.unnumbered);
    }
    return this.saveCounts([line], true);
  },
  stop() {},
});
