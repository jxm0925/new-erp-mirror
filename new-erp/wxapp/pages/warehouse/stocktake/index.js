const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
const document = require('../../../utils/warehouse-document');
function progress(counted, total) { return Number(total) > 0 ? Math.min(100, Math.round(Number(counted || 0) / Number(total) * 100)) : 0; }
// 未保存来源没有行主键，稳定来源键避免跨页草稿共用空 ID。
function rowKey(row) { return String(row.id || row.source_key); }
function lineIdentity(row) { return row.id ? { id: row.id } : { source_key: row.source_key }; }
Page({
  data: { id: 0, status: 'counting', keyword: '', page: 1, lastPage: 1, total: 0, rows: [], stats: {}, loading: true, saving: false,
    error: '', formError: '', header: null, lines: [], summary: {}, progress: 0, lineStatus: '', locator: {}, locatorOpen: false,
    drafts: {}, canCreate: false, canCount: false, canComplete: false, canAmount: false, entry: null, entryRows: [], entryPage: 1, entryLast: 1, entryTotal: 0,
    entryKeyword: '', entryLoading: false, entryError: '', entryChanges: {}, entryEditableCount: 0, newEntries: [], deleteIds: [], unnumbered: '',
    unnumberedChanged: false, supplementNo: '', physical: {}, entryFilterIndex: 0, entryFilters: [{ label: '全部', value: '' }, { label: '未确认', value: 'undecided' }, { label: '不在', value: 'missing' }, { label: '补录', value: 'new' }, { label: '业务占用', value: 'locked' }],
    pendingCounts: false, pendingComplete: false, pendingAdd: false, canAdd: false, confirmation: null,
    foundLineId: 0, found: null, foundError: '', foundItemOpen: false, foundLocationOpen: false, foundContext: {},
    createForm: null, createLocationOpen: false, createError: '', pendingCreate: false, emptyLocator: {},
    createPreviewRows: [], createPreviewLoading: false, createPreviewError: '', createPreviewPage: 1, createPreviewLast: 1, createPreviewTotal: 0 },
  onLoad(options) { const can = page.permissions(); this.setData({ id: Number(options.id || 0), canCreate: can('inventory.stocktake.create'), canCount: can('inventory.stocktake.create'), canComplete: can('inventory.stocktake.complete'), canAmount: can('inventory.amount.view') }); },
  onShow() { if (!page.resumingScan(this)) this.load(); }, onUnload() { this.sequence = (this.sequence || 0) + 1; this.entrySequence = (this.entrySequence || 0) + 1; this.createPreviewSequence = (this.createPreviewSequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() { return this.data.id ? this.loadDetail() : this.loadList(); },
  loadList() {
    wx.setNavigationBarTitle({ title: '我的盘点' });
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '', canCreate: page.permissions()('inventory.stocktake.create'), pendingCreate: !!warehouse.pending('stocktake.create', 0) });
    return warehouse.stocktakes({ status: this.data.status, keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10 }).then(response => {
      if (sequence !== this.sequence) return; const result = page.rows(response);
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.loadList(); }
      this.setData({ rows: result.rows.map(row => {
        const countedLines = Number(row.summary ? row.summary.counted_lines || 0 : row.counted_lines_count || 0);
        const totalLines = Number(row.summary ? row.summary.total_lines || 0 : row.line_count || 0);
        return Object.assign({}, row, { countedLines, totalLines, dateText: String(row.stocktake_date || row.created_at || '').slice(0, 10), progress: progress(countedLines, totalLines) });
      }),
        total: result.total, page: result.page, lastPage: result.lastPage, stats: response.stats || {}, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: page.errorText(error) }); });
  },
  loadDetail() {
    wx.setNavigationBarTitle({ title: '盘点录入' });
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
    return warehouse.document('stocktake', this.data.id, { page: this.data.page, per_page: 10, line_id: this.data.foundLineId || undefined, location_id: this.data.locator.location_id,
      keyword: this.data.keyword.trim(), line_status: this.data.lineStatus || undefined }).then(response => {
      if (sequence !== this.sequence) return; const body = response.data || response; const result = page.rows(body.lines); const summary = body.header.summary || {};
      // 相机返回及刷新不能把旧草稿升级为他人的新版本，否则下一次保存会覆盖并发修改。
      const draftVersion = this.data.header && (Object.keys(this.data.drafts).length || this.data.entry) ? this.data.header.business_version : body.header.business_version;
      if (result.page > result.lastPage) { this.setData({ page: result.lastPage }); return this.loadDetail(); }
      const canAmount = page.permissions()('inventory.amount.view');
      const lines = result.rows.map(row => {
        const entryStats = row.entry_stats || {}; const draft = this.data.drafts[rowKey(row)];
        return Object.assign({}, row, { rowKey: rowKey(row), bookText: page.quantity(row.book_qty) || '—', countedText: page.quantity(draft && Object.prototype.hasOwnProperty.call(draft, 'counted_qty') ? draft.counted_qty : row.counted_qty),
          unitCostText: canAmount ? page.quantity(draft && Object.prototype.hasOwnProperty.call(draft, 'unit_cost') ? draft.unit_cost : Number(row.unit_cost) > 0 ? row.unit_cost : '') : '',
          remarkText: draft && Object.prototype.hasOwnProperty.call(draft, 'remark') ? draft.remark : row.remark || '', confirmed: Number(entryStats.present || 0) + Number(entryStats.missing || 0),
          entryCount: Number(entryStats.book || 0), differenceText: page.quantity(row.difference_qty) });
      });
      this.setData({ header: Object.assign({}, body.header, { business_version: draftVersion, dateText: String(body.header.stocktake_date || body.header.created_at || '').slice(0, 10) }), lines, summary, progress: progress(summary.counted_lines, summary.total_lines), page: result.page, lastPage: result.lastPage, total: result.total, loading: false,
        canAmount, canCount: (body.actions || []).includes('stocktake.counts'), canAdd: (body.actions || []).includes('stocktake.add_line'), canComplete: (body.actions || []).includes('stocktake.complete'),
        pendingCounts: !!warehouse.pending('stocktake.counts', this.data.id), pendingComplete: !!warehouse.pending('stocktake.complete', this.data.id),
        pendingAdd: !!warehouse.pending('stocktake.add_line', this.data.id) });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  input(event) { this.setData({ [event.currentTarget.dataset.field || 'keyword']: event.detail.value }); },
  moneyBlur(event) {
    const field = event.currentTarget.dataset.field;
    const amount = page.moneyText(event.detail.value);
    if (amount) this.setData({ [field === 'found.unit_cost' ? 'found.unitCostText' : field]: amount });
  },
  unitCostInput(event) { this.setData({ 'found.unit_cost': event.detail.value, 'found.unitCostText': event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  newStocktake() {
    if (this.data.saving || !this.data.canCreate || this.data.pendingCreate) return;
    const now = new Date(); const date = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
    this.resetCreatePreview();
    this.setData({ createForm: { scope: 'location', locator: {}, stocktake_date: date, remark: '' }, createError: '' });
  },
  closeCreate() { if (!this.data.saving) { this.resetCreatePreview(); this.setData({ createForm: null, createLocationOpen: false }); } },
  createScope(event) {
    if (!this.data.createForm || this.data.saving || this.data.pendingCreate) return;
    const scope = event.currentTarget.dataset.scope;
    if (['location', 'warehouse'].includes(scope)) this.setData({ 'createForm.scope': scope, createError: '' });
  },
  openCreateLocation() { if (!this.data.saving) this.setData({ createLocationOpen: true }); },
  closeCreateLocation() { this.setData({ createLocationOpen: false }); },
  chooseCreateLocation(event) {
    if (!this.data.createForm || this.data.saving) return;
    this.setData({ 'createForm.locator': event.detail, createLocationOpen: false, createError: '' });
    this.resetCreatePreview();
    this.loadCreatePreview(1);
  },
  resetCreatePreview() {
    this.createPreviewSequence = (this.createPreviewSequence || 0) + 1;
    this.setData({ createPreviewRows: [], createPreviewLoading: false, createPreviewError: '', createPreviewPage: 1, createPreviewLast: 1, createPreviewTotal: 0 });
  },
  loadCreatePreview(targetPage = 1) {
    const form = this.data.createForm;
    if (!form || !form.locator.warehouse_id || !form.locator.location_id) { this.resetCreatePreview(); return Promise.resolve(); }
    const sequence = this.createPreviewSequence = (this.createPreviewSequence || 0) + 1;
    this.setData({ createPreviewRows: [], createPreviewLoading: true, createPreviewError: '', createPreviewPage: targetPage });
    return warehouse.stocktakeLocationPreview({ warehouse_id: form.locator.warehouse_id, location_id: form.locator.location_id, page: targetPage, per_page: 10 }).then(response => {
      if (sequence !== this.createPreviewSequence || !this.data.createForm) return;
      const result = page.rows(response);
      this.setData({ createPreviewRows: result.rows.map(row => Object.assign({}, row, { previewKey: row.source_key || String((row.item || {}).id) + ':' + String(row.batch_no || ''), bookText: page.quantity(row.book_qty) || '—' })),
        createPreviewPage: result.page, createPreviewLast: result.lastPage, createPreviewTotal: result.total, createPreviewLoading: false });
    }).catch(error => { if (sequence === this.createPreviewSequence) this.setData({ createPreviewLoading: false, createPreviewError: page.errorText(error) }); });
  },
  retryCreatePreview() { this.loadCreatePreview(this.data.createPreviewPage); },
  turnCreatePreview(event) {
    const target = this.data.createPreviewPage + Number(event.currentTarget.dataset.delta);
    if (!this.data.createPreviewLoading && !this.data.saving && target > 0 && target <= this.data.createPreviewLast) this.loadCreatePreview(target);
  },
  createDate(event) { this.setData({ 'createForm.stocktake_date': event.detail.value }); },
  async createStocktake(confirmed) {
    if (this.data.saving || !this.data.canCreate) return;
    const form = this.data.createForm; const payload = {};
    const previewLocator = !this.data.pendingCreate && form && form.locator.location_id ? Object.assign({}, form.locator) : {};
    if (!this.data.pendingCreate) {
      if (!form || !form.locator.warehouse_id || (form.scope === 'location' && !form.locator.location_id)) return this.setData({ createError: form && form.scope === 'warehouse' ? '请选择要整仓盘点的仓库' : '请选择要盘点的仓库和库位' });
      if (form.scope === 'warehouse' && confirmed !== true) return this.setData({ confirmation: { mode: 'create-warehouse', title: '确认整仓盘点',
        message: form.locator.warehouse_name + ' 的全部库位将纳入盘点并暂停出入库（含生产领料、发货），完成或取消后恢复。确认开始？' } });
      Object.assign(payload, { warehouse_id: form.locator.warehouse_id, scope: form.scope, location_id: form.scope === 'location' ? form.locator.location_id : null, stocktake_date: form.stocktake_date, remark: form.remark.trim() });
    }
    this.setData({ saving: true, createError: '', formError: '' });
    try {
      const result = await warehouse.submit('stocktake.create', 0, payload);
      this.resetCreatePreview();
      this.setData({ id: Number(result.id), page: 1, keyword: '', locator: previewLocator, drafts: {}, header: null, lineStatus: '', foundLineId: 0, createForm: null, pendingCreate: false });
      await this.loadDetail(); wx.showToast({ title: '盘点单已创建', icon: 'success' });
    } catch (error) {
      const message = page.errorText(error); this.setData({ createError: message, formError: message, pendingCreate: !!warehouse.pending('stocktake.create', 0) });
    } finally { this.setData({ saving: false }); }
  },
  switchStatus(event) { this.setData({ status: event.currentTarget.dataset.status, page: 1 }); this.loadList(); },
  open(event) { this.setData({ id: Number(event.currentTarget.dataset.id), page: 1, keyword: '', locator: {}, drafts: {}, formError: '' }); this.loadDetail(); },
  closeDetail() { if (this.data.saving) return; if (Object.keys(this.data.drafts).length) { this.setData({ confirmation: { mode: 'back', title: '尚未保存本次录入', message: '返回将放弃本次未保存的数量、单价及备注。' } }); return; } this.backToList(); },
  backToList() { this.setData({ id: 0, page: 1, header: null, keyword: '', drafts: {}, locator: {}, lineStatus: '', foundLineId: 0, found: null, formError: '' }); this.loadList(); },
  turn(event) { const target = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && !this.data.saving && target > 0 && target <= this.data.lastPage) { this.setData({ page: target }); this.load(); } },
  lineFilter(event) { this.setData({ lineStatus: event.currentTarget.dataset.status || '', foundLineId: 0, page: 1 }); this.loadDetail(); },
  openFound() {
    if (this.data.saving || !this.data.canAdd || this.data.pendingAdd) return;
    if (Object.keys(this.data.drafts).length) return this.setData({ formError: '请先保存已录实盘，再补录账外物料' });
    const header = this.data.header;
    const locator = header.location_id ? { location_id: header.location_id, location_code: header.location.location_code, location_name: header.location.location_name } : this.data.locator;
    this.setData({ found: { locator, batch_no: '', unit_cost: '', version: header.business_version }, foundContext: { stocktake_id: header.id }, foundError: '', formError: '' });
  },
  closeFound() { if (!this.data.saving) this.setData({ found: null, foundItemOpen: false, foundLocationOpen: false }); },
  openFoundItems() { this.setData({ foundItemOpen: true }); },
  closeFoundItems() { this.setData({ foundItemOpen: false }); },
  chooseFoundItem(event) {
    const item = event.detail.rows[0];
    if (item) this.setData({ 'found.item': item, 'found.unit_cost': '', 'found.unitCostText': '', foundItemOpen: false, foundError: '' });
  },
  openFoundLocation() { this.setData({ foundLocationOpen: true }); },
  closeFoundLocation() { this.setData({ foundLocationOpen: false }); },
  chooseFoundLocation(event) {
    if (Number(event.detail.warehouse_id) === Number(this.data.header.warehouse_id)) this.setData({ 'found.locator': event.detail, foundLocationOpen: false, foundError: '' });
  },
  async saveFound() {
    if (this.data.saving) return;
    const id = this.data.id; const form = this.data.found;
    const payload = {};
    if (!this.data.pendingAdd) {
      if (!form || !form.item || !form.locator.location_id || !form.batch_no.trim()) return this.setData({ foundError: '请选择物料和库位，并填写实际批次。' });
      Object.assign(payload, { expected_version: form.version, item_id: form.item.id, location_id: form.locator.location_id, batch_no: form.batch_no.trim() });
      if (this.data.canAmount && form.item.material_management_mode !== 'physical' && form.unit_cost) payload.unit_cost = form.unit_cost;
    }
    this.setData({ saving: true, foundError: '', formError: '' });
    try {
      const result = await warehouse.submit('stocktake.add_line', id, payload);
      if (this.data.id !== id) return;
      this.setData({ found: null, pendingAdd: false, page: 1, foundLineId: result.line_id, lineStatus: '', keyword: '', locator: {} });
      await this.loadDetail();
      wx.showToast({ title: '已补录，请录入实盘', icon: 'none' });
    } catch (error) {
      if (this.data.id !== id) return;
      const message = page.errorText(error);
      const pendingAdd = !!warehouse.pending('stocktake.add_line', id);
      this.setData({ foundError: message, formError: message, pendingAdd });
      if (pendingAdd) this.setData({ found: null });
      if (!pendingAdd && error.errors && error.errors.expected_version) {
        this.setData({ found: null }); await this.loadDetail();
      }
    } finally { this.setData({ saving: false }); }
  },
  openLocator() { this.setData({ locatorOpen: true }); }, closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) {
    const result = event.detail;
    if (Number(result.warehouse_id) !== Number(this.data.header.warehouse_id) || (this.data.header.location_id && Number(result.location_id) !== Number(this.data.header.location_id))) return this.setData({ formError: '该库位不在本次盘点范围内' });
    this.setData({ locator: result, locatorOpen: false, foundLineId: 0, page: 1 }); this.loadDetail();
  },
  scanLocation(event) {
    const result = event.detail.result;
    if (Number(result.warehouse_id) !== Number(this.data.header.warehouse_id) || (this.data.header.location_id && Number(result.location_id) !== Number(this.data.header.location_id))) return this.setData({ formError: '该库位不在本次盘点范围内' });
    this.setData({ locator: result, foundLineId: 0, page: 1, formError: '' }); this.loadDetail();
  },
  clearLocation() { this.setData({ locator: {}, page: 1 }); this.loadDetail(); },
  lineInput(event) {
    const key = String(event.currentTarget.dataset.key); const line = this.data.lines.find(row => rowKey(row) === key); if (!line) return;
    const field = event.currentTarget.dataset.field;
    if (line.source_error || !this.data.canCount || this.data.saving || this.data.pendingCounts || this.data.pendingComplete || this.data.pendingAdd || !['counted_qty', 'unit_cost', 'remark'].includes(field)) return;
    if (field === 'counted_qty' && line.count_mode !== 'quantity' || field === 'unit_cost' && (!this.data.canAmount || !line.unit_cost_editable)) return;
    const draft = Object.assign(lineIdentity(line), this.data.drafts[key] || {});
    draft[field] = event.detail.value;
    const textField = { counted_qty: 'countedText', unit_cost: 'unitCostText', remark: 'remarkText' }[field];
    this.setData({ drafts: Object.assign({}, this.data.drafts, { [key]: draft }), ['lines[' + this.data.lines.indexOf(line) + '].' + textField]: event.detail.value });
  },
  async saveCounts(extraLines, closeEntry) {
    if (this.data.saving || this.data.pendingAdd) return false;
    const lines = Object.values(this.data.drafts).map(row => Object.assign({}, row));
    (extraLines || []).forEach(row => { const index = lines.findIndex(value => rowKey(value) === rowKey(row)); if (index < 0) lines.push(row); else lines[index] = Object.assign({}, lines[index], row); });
    if (!lines.length && !this.data.pendingCounts) { this.setData({ formError: '请先录入实盘结果' }); return false; }
    for (const line of lines) if (line.counted_qty !== undefined && line.counted_qty !== null && line.counted_qty !== '' && (!Number.isFinite(Number(line.counted_qty)) || Number(line.counted_qty) < 0)) { this.setData({ formError: '实盘数必须为大于或等于零的数字' }); return false; }
    for (const line of lines) {
      if (!Object.prototype.hasOwnProperty.call(line, 'unit_cost')) continue;
      if (!this.data.canAmount) { delete line.unit_cost; continue; }
      const cost = String(line.unit_cost === null ? '' : line.unit_cost).trim();
      if (cost && (!/^\d{1,10}(\.\d{1,8})?$/.test(cost) || !(Number(cost) > 0))) { this.setData({ formError: '盘盈单价必须大于零，整数最多十位，小数最多八位' }); return false; }
      line.unit_cost = cost || null;
    }
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
    if (this.data.pendingAdd) return this.setData({ formError: '请先核对上次补录结果' });
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
    if (confirmation.mode === 'create-warehouse') return this.createStocktake(true);
    if (confirmation.mode === 'back') return this.backToList();
    if (confirmation.mode === 'entry-discard') this.setData({ entry: null });
    if (confirmation.mode === 'conflict') { this.setData({ drafts: {}, entry: null }); return this.loadDetail(); }
    if (confirmation.mode === 'incomplete') { this.setData({ lineStatus: 'uncounted', foundLineId: 0, page: 1 }); return this.loadDetail(); }
  },
  closeConfirmation() { if (!this.data.saving) this.setData({ confirmation: null }); },
  openEntries(event) {
    const line = this.data.lines.find(row => rowKey(row) === String(event.currentTarget.dataset.key)); if (!line || line.source_error) return;
    this.entryRawRows = [];
    this.setData({ entry: line, entryRows: [], entryPage: 1, entryKeyword: '', entryFilterIndex: 0, entryChanges: {}, entryEditableCount: 0,
      newEntries: [], deleteIds: [], unnumbered: line.unnumbered_counted_qty === null || line.unnumbered_counted_qty === undefined ? '' : page.quantity(line.unnumbered_counted_qty),
      unnumberedChanged: false, supplementNo: '', physical: {}, entryError: '' }); this.loadEntries();
  },
  loadEntries() {
    if (!this.data.entry) return Promise.resolve();
    const sequence = this.entrySequence = (this.entrySequence || 0) + 1; const entry = this.data.entry;
    this.entryRawRows = [];
    this.setData({ entryLoading: true, entryRows: [], entryEditableCount: 0, entryError: '' });
    const query = { page: this.data.entryPage, per_page: 10, keyword: this.data.entryKeyword.trim(), filter: this.data.entryFilters[this.data.entryFilterIndex].value || undefined };
    const request = entry.id ? warehouse.stocktakeEntries(this.data.id, entry.id, query) : warehouse.stocktakeSourceEntries(this.data.id, Object.assign({}, query, { source_key: entry.source_key }));
    return request.then(response => {
      if (sequence !== this.entrySequence || !this.data.entry || rowKey(this.data.entry) !== rowKey(entry)) return;
      const result = page.rows(response); this.entryRawRows = result.rows;
      this.setData({ entryPage: result.page, entryLast: result.lastPage, entryTotal: result.total, entryLoading: false }); this.decorateEntries();
    }).catch(error => { if (sequence === this.entrySequence) this.setData({ entryLoading: false, entryError: page.errorText(error) }); });
  },
  decorateEntries() {
    const entryRows = (this.entryRawRows || []).filter(row => !this.data.deleteIds.includes(Number(row.id))).map(row => {
      const present = Object.prototype.hasOwnProperty.call(this.data.entryChanges, row.id) ? this.data.entryChanges[row.id] : row.counted_present;
      return Object.assign({}, row, { present, statusText: row.is_locked ? '业务占用' : row.is_new ? '补录' : present === true ? '在库' : present === false ? '不在' : '未确认', dimensionsText: document.dimensions(row.dimensions), costText: page.money(row.total_cost) });
    });
    this.setData({ entryRows, entryEditableCount: entryRows.filter(row => !row.is_locked && !row.is_new).length });
  },
  entryTurn(event) { const target = this.data.entryPage + Number(event.currentTarget.dataset.delta); if (!this.data.entryLoading && target > 0 && target <= this.data.entryLast) { this.setData({ entryPage: target }); this.loadEntries(); } },
  entrySearch() { this.setData({ entryPage: 1 }); this.loadEntries(); },
  entryFilter(event) { this.setData({ entryFilterIndex: Number(event.detail.value), entryPage: 1 }); this.loadEntries(); },
  present(event) {
    const row = this.data.entryRows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (!row || row.is_locked || row.is_new) return;
    this.changeEntries([row], event.currentTarget.dataset.present === true || event.currentTarget.dataset.present === 'true');
  },
  markPage(event) {
    if (this.data.entryLoading || this.data.saving) return;
    this.changeEntries(this.data.entryRows.filter(row => !row.is_locked && !row.is_new), event.currentTarget.dataset.present === true || event.currentTarget.dataset.present === 'true');
  },
  changeEntries(rows, present) {
    const changes = Object.assign({}, this.data.entryChanges);
    rows.forEach(row => { if (present === row.counted_present) delete changes[row.id]; else changes[row.id] = present; });
    if (Object.keys(changes).length > 500) return this.setData({ entryError: '一次可保存 500 件，请先保存已录结果，再继续盘点' });
    this.setData({ entryChanges: changes, entryError: '' });
    this.decorateEntries();
  },
  removeEntry(event) { this.setData({ deleteIds: this.data.deleteIds.concat(Number(event.currentTarget.dataset.id)) }); this.decorateEntries(); },
  async scanEntry(event) {
    const result = event.detail.result; const entry = this.data.entry;
    if (!entry || Number(result.item_id) !== Number(entry.item_id)) return this.setData({ entryError: '扫描实物不属于当前盘点物料' });
    const code = result.serial_no || result.physical_no || result.code;
    this.setData({ entryKeyword: code, entryPage: 1, entryFilterIndex: 0 }); await this.loadEntries();
    if (!this.data.entry || rowKey(this.data.entry) !== rowKey(entry)) return;
    const row = this.data.entryRows.find(row => result.type === 'physical' ? Number(row.material_physical_id) === Number(result.id) : row.serial_no === code);
    if (!row) return this.setData({ entryError: '该编号不在此盘点行账面内，请核对库位与批次' });
    if (row.is_locked) return this.setData({ entryError: '该编号已被业务占用，按账面处理' });
    if (!row.is_new) this.changeEntries([row], true);
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
    const isLength = this.data.entry.item.cutting_mode === 'length';
    const dimensions = isLength ? { length_mm: physical.length_mm } : { length_mm: physical.length_mm, width_mm: physical.width_mm, thickness_mm: physical.thickness_mm };
    if (!this.data.canAmount || !Object.values(dimensions).every(value => /^\d{1,10}(\.\d{1,2})?$/.test(value || '') && Number(value) > 0)) return this.setData({ entryError: isLength ? '请填写大于零的长度，最多两位小数' : '请填写大于零的长、宽、厚，最多两位小数' });
    const cost = page.moneyText(physical.total_cost);
    if (!/^\d+(\.\d*)?$/.test(physical.total_cost || '') || !/^\d{1,14}\.\d{2}$/.test(cost)) return this.setData({ entryError: '请填写有效的非负库存成本' });
    this.setData({ newEntries: this.data.newEntries.concat({ dimensions, total_cost: cost, label: Object.values(dimensions).join('×') + ' mm · ¥' + cost }), physical: {}, entryError: '' });
  },
  removePending(event) { this.setData({ newEntries: this.data.newEntries.filter((row, index) => index !== Number(event.currentTarget.dataset.index)) }); },
  closeEntries() {
    if (this.data.saving) return;
    if (Object.keys(this.data.entryChanges).length || this.data.newEntries.length || this.data.deleteIds.length || this.data.unnumberedChanged) return this.setData({ confirmation: { mode: 'entry-discard', title: '逐件结果尚未保存', message: '关闭将放弃这些修改。' } });
    this.setData({ entry: null }); this.entrySequence = (this.entrySequence || 0) + 1;
  },
  saveEntries() {
    if (this.data.entry.source_error) return;
    const line = Object.assign(lineIdentity(this.data.entry), { entries: Object.entries(this.data.entryChanges).map(([id, present]) => ({ id: Number(id), counted_present: present })),
      delete_entry_ids: this.data.deleteIds, new_entries: this.data.newEntries.map(row => { const value = Object.assign({}, row); delete value.label; return value; }) });
    if (this.data.entry.count_mode === 'serial' && this.data.unnumberedChanged) {
      if (this.data.unnumbered !== '' && (!Number.isInteger(Number(this.data.unnumbered)) || Number(this.data.unnumbered) < 0)) return this.setData({ entryError: '无编号实盘数必须为非负整数' });
      line.unnumbered_counted_qty = this.data.unnumbered === '' ? null : Number(this.data.unnumbered);
    }
    return this.saveCounts([line], true);
  },
  stop() {},
});
