const warehouse = require('../../services/warehouse');
const ui = require('../../utils/warehouse-page');
const logic = require('../../utils/warehouse-picking');
const paths = { people: 'options', warehouse: 'options', target: 'targets', stock: 'sources', physical: 'physicals', serial: 'serials', 'sales-return-serial': 'sales-return-serials', 'onsite-physical': 'physicals', 'onsite-serial': 'serials', 'procurement-item': 'items', 'procurement-order': 'orders' };
function optionView(row, mode) {
  if (mode === 'onsite-physical') return logic.optionView(row, 'physical');
  if (mode === 'onsite-serial') return logic.optionView(row, 'serial');
  if (mode === 'procurement-item') return Object.assign({}, row, { key: String(row.id), title: `${row.item_code} ${row.item_name}`, subtitle: [row.spec || row.model, row.unit_name].filter(Boolean).join(' / ') });
  if (mode === 'procurement-order') return Object.assign({}, row, { key: String(row.id), title: row.sales_order_no, subtitle: row.customer_name });
  if (mode !== 'sales-return-serial') return logic.optionView(row, mode);
  return Object.assign({}, row, { key: String(row.id), title: row.serial_no,
    subtitle: [row.batch_no ? `批次 ${row.batch_no}` : '无批次', row.shipment_no ? `原发货单 ${row.shipment_no}` : ''].filter(Boolean).join(' / ') });
}
Component({
  properties: { open: Boolean, title: String, mode: String, query: { type: Object, value: {} }, selected: { type: Array, value: [] }, multiple: Boolean, maxCount: { type: Number, value: 0 }, quantity: Boolean },
  data: { rows: [], selectedRows: [], selectedCount: 0, keyword: '', page: 1, lastPage: 1, total: 0, loading: false, error: '', showSelected: false,
    categories: [], categoryId: 0, categoryPage: 1, categoryLastPage: 1, categoryLoading: false, categoryError: '', hasCategories: false, selectedQuantity: '', isMultiple: false },
  observers: { open(value) { if (value) this.initialize(); else this.invalidate(); } },
  lifetimes: { detached() { this.invalidate(); } },
  methods: {
    initialize() {
      this.invalidate(); this.selection = {};
      (this.properties.selected || []).forEach(row => { const item = optionView(logic.copy(row), this.properties.mode); this.selection[item.key] = item; });
      const hasCategories = ['people', 'stock', 'procurement-item'].includes(this.properties.mode);
      this.setData({ rows: [], selectedRows: [], keyword: '', page: 1, lastPage: 1, total: 0, error: '', showSelected: false,
        categories: [], categoryId: 0, categoryPage: 1, categoryLastPage: 1, categoryError: '', hasCategories,
        isMultiple: !['people', 'warehouse', 'target', 'procurement-order'].includes(this.properties.mode) && !!this.properties.multiple });
      this.sync(); this.load(); if (hasCategories) this.loadCategories();
    },
    invalidate() { this.sequence = (this.sequence || 0) + 1; this.categorySequence = (this.categorySequence || 0) + 1; },
    requestQuery() {
      const source = this.properties.query || {}; const query = {};
      // Closed query mapping prevents a consumer from changing the endpoint or widening its source.
      const keys = this.properties.mode === 'sales-return-serial' ? ['return_id', 'return_item_id']
        : ['work_order_id', 'target_material_requirement_id', 'warehouse_id', 'picking_task_id', 'picking_task_line_id', 'source_delivery_id'];
      keys.forEach(key => { if (source[key]) query[key] = source[key]; });
      return query;
    },
    load() {
      const mode = this.properties.mode; const path = paths[mode];
      if (!path) { this.setData({ error: '不支持的选择类型', loading: false }); return Promise.resolve(); }
      const sequence = this.sequence = (this.sequence || 0) + 1;
      const query = Object.assign(this.requestQuery(), { page: this.data.page, per_page: 10, keyword: this.data.keyword.trim() });
      if (mode === 'sales-return-serial' && (!Number.isInteger(Number(query.return_id)) || Number(query.return_id) <= 0 || !Number.isInteger(Number(query.return_item_id)) || Number(query.return_item_id) <= 0)) {
        this.setData({ loading: false, rows: [], error: '请指定销售退货单和退货明细' }); return Promise.resolve();
      }
      if (mode === 'people' || mode === 'warehouse') query.kind = mode === 'people' ? 'people' : 'warehouses';
      if (this.data.categoryId) query[mode === 'stock' ? 'location_id' : mode === 'procurement-item' ? 'category_id' : 'department_id'] = this.data.categoryId;
      this.setData({ loading: true, error: '', rows: [] });
      const endpoint = mode === 'sales-return-serial' ? 'inventory/warehouse-workspace/sales-return-serials'
        : mode.startsWith('onsite-') ? `production/onsite-collection-sources/${path}`
          : mode.startsWith('procurement-') ? `production/material-procurement-options/${path}` : `production/material-picking-workspace/${path}`;
      return warehouse.get(endpoint, query).then(response => {
        if (sequence !== this.sequence || !this.properties.open) return;
        const result = ui.rows(response); const rows = result.rows.map(row => optionView(row, mode));
        // Refresh available facts without replacing a user's explicit selected quantity.
        rows.forEach(row => { if (this.selection[row.key]) this.selection[row.key] = Object.assign({}, row, { selected_qty: this.selection[row.key].selected_qty }); });
        this.setData({ rows, page: result.page, lastPage: result.lastPage, total: result.total, loading: false }); this.sync();
      }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: ui.errorText(error) }); });
    },
    loadCategories() {
      const sequence = this.categorySequence = (this.categorySequence || 0) + 1;
      const query = Object.assign(this.requestQuery(), { kind: this.properties.mode === 'stock' ? 'locations' : 'departments', page: this.data.categoryPage, per_page: 10 });
      this.setData({ categoryLoading: true, categoryError: '' });
      const procurement = this.properties.mode === 'procurement-item';
      return warehouse.get(procurement ? 'production/material-procurement-options/categories' : 'production/material-picking-workspace/options', query).then(response => {
        if (sequence !== this.categorySequence || !this.properties.open) return;
        const result = ui.rows(response);
        this.setData({ categories: result.rows.map(row => Object.assign({}, row, { label: row.category_name || row.location_code || row.location_name || row.name })), categoryPage: result.page, categoryLastPage: result.lastPage, categoryLoading: false });
      }).catch(error => { if (sequence === this.categorySequence) this.setData({ categoryLoading: false, categoryError: ui.errorText(error) }); });
    },
    sync() {
      const selectedRows = Object.values(this.selection || {});
      this.setData({ selectedRows, selectedCount: selectedRows.length, selectedQuantity: this.properties.quantity ? logic.sumQty(selectedRows.map(row => row.selected_qty)) : '',
        rows: this.data.rows.map(row => Object.assign({}, row, { checked: !!this.selection[row.key], selected_qty: this.selection[row.key] ? this.selection[row.key].selected_qty : '' })) });
    },
    toggle(event) {
      const key = String(event.currentTarget.dataset.key); const row = this.data.rows.find(row => row.key === key);
      if (!row || this.data.loading) return;
      if (this.selection[key]) delete this.selection[key];
      else {
        if (this.properties.mode === 'stock' && !(Number(row.picking_available_qty) > 0)) return this.setData({ error: '该来源暂无可用库存' });
        if (this.data.isMultiple && this.properties.maxCount > 0 && this.data.selectedCount >= this.properties.maxCount) return this.setData({ error: `最多选择${this.properties.maxCount}项` });
        if (!this.data.isMultiple) this.selection = {};
        this.selection[key] = Object.assign({}, row, this.properties.quantity ? { selected_qty: row.selected_qty || '1' } : {});
      }
      this.setData({ error: '' }); this.sync();
    },
    quantityInput(event) {
      const key = String(event.currentTarget.dataset.key); const selected = this.selection[key];
      if (!selected) return; selected.selected_qty = String(event.detail.value); this.sync();
    },
    stepQuantity(event) {
      const key = String(event.currentTarget.dataset.key); const selected = this.selection[key]; if (!selected) return;
      selected.selected_qty = String(Math.max(0, Number(selected.selected_qty || 0) + Number(event.currentTarget.dataset.delta))); this.sync();
    },
    keywordInput(event) { this.setData({ keyword: event.detail.value }); },
    search() { this.setData({ page: 1 }); return this.load(); },
    chooseCategory(event) { this.setData({ categoryId: Number(event.currentTarget.dataset.id), page: 1 }); return this.load(); },
    turnPage(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && page > 0 && page <= this.data.lastPage) { this.setData({ page }); return this.load(); } },
    categoryPage(event) { const page = this.data.categoryPage + Number(event.currentTarget.dataset.delta); if (!this.data.categoryLoading && page > 0 && page <= this.data.categoryLastPage) { this.setData({ categoryPage: page }); return this.loadCategories(); } },
    toggleSelected() { this.setData({ showSelected: !this.data.showSelected }); },
    remove(event) { delete this.selection[String(event.currentTarget.dataset.key)]; this.sync(); },
    confirm() {
      const rows = Object.values(this.selection || {});
      if (this.data.loading) return;
      if (!this.data.isMultiple && rows.length !== 1) return this.setData({ error: '请选择一项' });
      if (this.properties.maxCount > 0 && rows.length > this.properties.maxCount) return this.setData({ error: `最多选择${this.properties.maxCount}项` });
      if (this.properties.quantity && rows.some(row => !logic.validQty(row.selected_qty) || Number(row.selected_qty) <= 0 || Number(row.selected_qty) > Number(row.picking_available_qty) + 0.00000001)) return this.setData({ error: '请填写大于0且不超过可用数量的本次数量' });
      this.invalidate(); this.triggerEvent('confirm', { rows: logic.copy(rows) });
    },
    cancel() { this.invalidate(); this.triggerEvent('cancel'); }, stop() {},
  },
});
