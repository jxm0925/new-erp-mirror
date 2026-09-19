const cutting = require('../../../services/cutting');

function pageOf(response) {
  const payload = response || {}; const meta = payload.meta || {};
  return { rows: Array.isArray(payload.data) ? payload.data : [], page: Number(meta.current_page || 1), lastPage: Number(meta.last_page || 1), total: Number(meta.total || 0) };
}
function keyOf(item) { return item.physical_no ? `physical:${item.id}` : item.remnant_holding_id ? `remnant:${item.remnant_holding_id}` : `quantity:${item.inventory_balance_id}`; }
function present(item) {
  const dimensions = typeof item.dimensions === 'string' ? JSON.parse(item.dimensions || '{}') : (item.dimensions || {});
  return Object.assign({}, item, { _key: keyOf(item), input_qty: item.input_qty || '1',
    source_label: item.physical_no || item.batch_no,
    dimensions_label: [dimensions.length_mm, dimensions.width_mm, dimensions.thickness_mm].filter(Boolean).join(' × '),
    quantity_editable: !item.physical_no && !item.remnant_holding_id });
}

Page({
  data: { selected: [], busy: false, showPicker: false, keyword: '', categoryId: '', categories: [], categoryPage: 1, categoryLastPage: 1,
    candidates: [], page: 1, lastPage: 1, total: 0, loading: false, pickerSelected: [], showSelected: false, inputType: 'physical' },
  onLoad() {
    const actor = wx.getStorageSync('erp_user') || {};
    this.draftKey = `cutting-material-create-draft:${actor.legacy_id || actor.id || 'session'}`;
    const draft = wx.getStorageSync(this.draftKey);
    if (Array.isArray(draft)) this.setData({ selected: draft });
    this.intentKey = `${this.draftKey}:pending`;
    this.createIntent = wx.getStorageSync(this.intentKey) || null;
    if (this.createIntent) this.setData({ selected: this.createIntent.selected });
  },
  noop() {},
  canEdit() {
    if (this.data.busy) return false;
    if (!this.createIntent) return true;
    wx.showToast({ title: '请先重试确认上次创建结果', icon: 'none' });
    this.setData({ selected: this.createIntent.selected });
    return false;
  },
  saveDraft() { wx.setStorageSync(this.draftKey, this.data.selected); },
  openPicker() {
    if (!this.canEdit()) return;
    this.setData({ showPicker: true, keyword: '', categoryId: '', candidates: [], categories: [{ id: '', category_name: '全部' }],
      pickerSelected: this.data.selected.slice(), showSelected: false });
    this.loadCategories(1); return this.loadCandidates(1);
  },
  closePicker() { this.requestSequence = (this.requestSequence || 0) + 1; this.setData({ showPicker: false }); },
  onKeyword(e) { this.setData({ keyword: e.detail.value }); },
  search() { return this.loadCandidates(1); },
  setInputType(e) { this.setData({ inputType: e.currentTarget.dataset.type, candidates: [], showSelected: false }); return this.loadCandidates(1); },
  setCategory(e) { this.setData({ categoryId: e.currentTarget.dataset.id || '', showSelected: false }); return this.loadCandidates(1); },
  prevPage() { if (this.data.page > 1) return this.loadCandidates(this.data.page - 1); },
  nextPage() { if (this.data.page < this.data.lastPage) return this.loadCandidates(this.data.page + 1); },
  loadCategories(page) {
    return cutting.workerInputCategories({ page, per_page: 20 }).then(response => {
      const result = pageOf(response);
      this.setData({ categories: (page === 1 ? [{ id: '', category_name: '全部' }] : this.data.categories).concat(result.rows), categoryPage: result.page, categoryLastPage: result.lastPage });
    }).catch(error => wx.showToast({ title: error.message || '分类加载失败', icon: 'none' }));
  },
  moreCategories() { if (this.data.categoryPage < this.data.categoryLastPage) return this.loadCategories(this.data.categoryPage + 1); },
  loadCandidates(page) {
    const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
    this.setData({ loading: true });
    return cutting.workerInputs({ input_type: this.data.inputType, page, per_page: 20, keyword: this.data.keyword.trim() || undefined, category_id: this.data.categoryId || undefined }).then(response => {
      if (sequence !== this.requestSequence) return;
      const result = pageOf(response); const selected = this.data.pickerSelected;
      this.setData({ candidates: result.rows.map(row => Object.assign(present(row), { _selected: selected.some(item => item._key === keyOf(row)) })),
        page: result.page, lastPage: result.lastPage, total: result.total, loading: false });
    }).catch(error => { if (sequence === this.requestSequence) { this.setData({ loading: false, candidates: [] }); wx.showToast({ title: error.message || '用料加载失败', icon: 'none' }); } });
  },
  toggle(e) {
    const key = e.currentTarget.dataset.key;
    const selected = this.data.pickerSelected.slice(); const index = selected.findIndex(row => row._key === key);
    if (index >= 0) selected.splice(index, 1);
    else { const row = this.data.candidates.find(item => item._key === key); if (row) selected.push(Object.assign({}, row)); }
    this.setData({ pickerSelected: selected, candidates: this.data.candidates.map(row => Object.assign({}, row, { _selected: selected.some(item => item._key === row._key) })) });
  },
  toggleSelected() { this.setData({ showSelected: !this.data.showSelected }); },
  confirmPicker() {
    if (this.data.pickerSelected.length > 100) return wx.showToast({ title: '一次最多选择100份用料', icon: 'none' });
    this.setData({ selected: this.data.pickerSelected.slice(), showPicker: false }); this.saveDraft();
  },
  onQuantity(e) { if (!this.canEdit()) return; const selected = this.data.selected.slice(); const index = Number(e.currentTarget.dataset.index); if (!selected[index] || !selected[index].quantity_editable) return; selected[index] = Object.assign({}, selected[index], { input_qty: e.detail.value }); this.setData({ selected }); this.saveDraft(); },
  remove(e) { if (!this.canEdit()) return; const selected = this.data.selected.slice(); selected.splice(Number(e.currentTarget.dataset.index), 1); this.setData({ selected }); this.saveDraft(); },
  create() {
    if (this.data.busy) return;
    if (!this.data.selected.length || this.data.selected.some(row => row.quantity_editable && (!/^\d+$/.test(String(row.input_qty)) || Number(row.input_qty) <= 0)))
      return wx.showToast({ title: '请选择钢板或方管，并填写实际领用根数', icon: 'none' });
    const inputs = this.data.selected.map(row => {
      if (row.physical_no) return { physical_material_id: Number(row.id) };
      if (row.remnant_holding_id) return { remnant_holding_id: Number(row.remnant_holding_id) };
      return { inventory_balance_id: Number(row.inventory_balance_id), input_qty: String(row.input_qty) };
    });
    // Retain creation identity until navigation succeeds too: the server may have
    // created the order even when a response or the following redirect is lost.
    if (!this.createIntent) {
      this.createIntent = { selected: JSON.parse(JSON.stringify(this.data.selected)), payload: { expected_version: 0, inputs,
        client_command_id: `cut-create-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` } };
      wx.setStorageSync(this.intentKey, this.createIntent);
    }
    this.setData({ busy: true, selected: this.createIntent.selected });
    return cutting.createOrder(this.createIntent.payload).then(response => {
      const result = response.data || {};
      if (!result.cutting_order_id || !result.cutting_task_id) throw new Error('下料记录返回不完整，请核对我的下料记录');
      const url = `/pages/production/cutting-order-detail/index?taskId=${result.cutting_task_id}&orderId=${result.cutting_order_id}`;
      return new Promise((resolve, reject) => wx.redirectTo({ url,
        success: () => { wx.removeStorageSync(this.draftKey); wx.removeStorageSync(this.intentKey); this.createIntent = null; resolve(); },
        fail: () => reject(new Error('下料记录已创建，页面打开失败，请重试进入原记录。')) }));
    }).catch(error => {
      if (error.statusCode >= 400 && error.statusCode < 500 && ![401, 403, 408, 429].includes(error.statusCode) && error.errorCode !== 'command_processing') {
        this.createIntent = null; wx.removeStorageSync(this.intentKey);
      }
      wx.showToast({ title: error.message || '下料记录创建失败', icon: 'none' });
    }).finally(() => this.setData({ busy: false }));
  },
});

module.exports = { pageOf, keyOf };
