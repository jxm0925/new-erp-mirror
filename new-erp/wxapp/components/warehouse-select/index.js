const warehouse = require('../../services/warehouse');
const ui = require('../../utils/warehouse-page');

function describe(row) {
  const d = row.dimensions || {};
  const size = ['length_mm', 'width_mm', 'thickness_mm'].filter(key => d[key] !== null && d[key] !== undefined && d[key] !== '').map(key => ui.quantity(d[key])).join(' × ');
  return Object.assign({}, row, {
    title: row.title || row.item_name || row.serial_no || row.physical_no || '',
    subtitle: row.subtitle || [row.item_code, row.spec, row.model, row.material_grade, row.category_name].filter(Boolean).join(' · '),
    detail: row.detail || [row.physical_no || row.serial_no, row.warehouse_name, row.location_code, row.batch_no].filter(Boolean).join(' · '),
    measure: [size ? size + ' mm' : '', d.area_m2 != null ? ui.quantity(d.area_m2) + ' m²' : ''].filter(Boolean).join(' · '),
  });
}

Component({
  properties: {
    open: Boolean, title: { type: String, value: '手动选择' }, mode: { type: String, value: 'local' },
    context: { type: Object, value: {} }, scopeKey: String, rows: { type: Array, value: [] },
    selected: { type: Array, value: [] }, multiple: Boolean, maximum: { type: Number, value: 500 }, locked: Boolean,
  },
  data: { visibleRows: [], chosen: [], keyword: '', page: 1, lastPage: 1, total: 0, loading: false, error: '', categoryIndex: 0, categories: [{ id: 0, name: '全部分类' }] },
  observers: {
    open(open) { if (open) this.initialize(); else this.invalidate(); },
    'scopeKey, context, rows': function () { if (this.properties.open) this.initialize(); },
  },
  lifetimes: { detached() { this.invalidate(); } },
  methods: {
    invalidate() { this.sequence = (this.sequence || 0) + 1; },
    initialize() {
      this.invalidate(); this.token = wx.getStorageSync('erp_token'); this.permissions = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
      this.selection = {}; (this.properties.selected || []).forEach(row => { this.selection[String(row.id)] = Object.assign({}, row); });
      this.setData({ keyword: '', page: 1, categoryIndex: 0, categories: [{ id: 0, name: '全部分类' }], error: '', visibleRows: [] });
      this.sync(); this.load();
    },
    current() { return this.properties.open && !this.properties.locked && this.token === wx.getStorageSync('erp_token') && this.permissions === JSON.stringify(wx.getStorageSync('erp_permissions') || []); },
    sync() { this.setData({ chosen: Object.values(this.selection || {}).map(describe), visibleRows: this.data.visibleRows.map(row => Object.assign({}, row, { chosen: !!this.selection[String(row.id)] })) }); },
    async load() {
      const sequence = this.sequence = (this.sequence || 0) + 1;
      this.setData({ loading: true, error: '' });
      try {
        let result; const context = this.properties.context || {};
        if (this.properties.mode === 'local') {
          const keyword = this.data.keyword.trim().toLowerCase();
          const all = this.properties.rows.map(describe).filter(row => !keyword || [row.title, row.subtitle, row.detail, row.measure].join(' ').toLowerCase().includes(keyword));
          result = { rows: all.slice((this.data.page - 1) * 10, this.data.page * 10), total: all.length, lastPage: Math.max(1, Math.ceil(all.length / 10)) };
        } else {
          const query = { page: this.data.page, per_page: 10, keyword: this.data.keyword.trim(), warehouse_id: context.warehouse_id || undefined,
            location_id: context.location_id || undefined, category_id: (this.data.categories[this.data.categoryIndex] || {}).id || undefined };
          const response = this.properties.mode === 'serial'
            ? await warehouse.balanceSerials(context.balance_id, Object.assign(query, { for_transfer: 1 }))
            : await warehouse.get('inventory/material-physicals/transfer-candidates', query);
          if (sequence !== this.sequence || !this.current()) return;
          result = ui.rows(response);
          if (response.categories) this.setData({ categories: [{ id: 0, name: '全部分类' }].concat(response.categories) });
        }
        if (sequence !== this.sequence || !this.current()) return;
        this.setData({ visibleRows: result.rows.map(describe), total: result.total, lastPage: result.lastPage }); this.sync();
      } catch (error) { if (sequence === this.sequence && this.properties.open) this.setData({ visibleRows: [], error: ui.errorText(error) }); }
      finally { if (sequence === this.sequence) this.setData({ loading: false }); }
    },
    input(event) { this.setData({ keyword: event.detail.value }); },
    search() { if (!this.data.loading) { this.setData({ page: 1 }); this.load(); } },
    category(event) { if (!this.data.loading) { this.setData({ categoryIndex: Number(event.detail.value), page: 1 }); this.load(); } },
    turn(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && page > 0 && page <= this.data.lastPage) { this.setData({ page }); this.load(); } },
    choose(event) {
      if (!this.current() || this.data.loading) return;
      const row = this.data.visibleRows.find(row => String(row.id) === String(event.currentTarget.dataset.id)); if (!row) return;
      if (this.selection[String(row.id)]) delete this.selection[String(row.id)];
      else {
        if (!this.properties.multiple) this.selection = {};
        if (Object.keys(this.selection).length >= this.properties.maximum) return this.setData({ error: '已达到本次可选择数量' });
        this.selection[String(row.id)] = Object.assign({}, row);
      }
      this.setData({ error: '' }); this.sync();
    },
    remove(event) { if (!this.current() || this.data.loading) return; delete this.selection[String(event.currentTarget.dataset.id)]; this.sync(); },
    async confirm() {
      if (!this.current()) return this.setData({ error: '账号、权限或单据状态已变化，请重新打开选择窗口' });
      if (this.data.loading) return;
      const rows = Object.values(this.selection || {});
      if ((!rows.length && !(this.properties.multiple && this.properties.selected.length)) || rows.length > this.properties.maximum) return this.setData({ error: '请选择本次要办理的明细，且不得超过可选数量' });
      const sequence = this.sequence; this.setData({ loading: true, error: '' });
      try {
        if (this.properties.mode === 'serial' && rows.length) {
          const context = this.properties.context; const eligible = new Set();
          // 分批核对，避免大量已选编号使 GET 地址超过服务器长度上限。
          for (let offset = 0; offset < rows.length; offset += 100) {
            const query = { warehouse_id: context.warehouse_id, for_transfer: 1 };
            rows.slice(offset, offset + 100).forEach((row, index) => { query['ids[' + index + ']'] = Number(row.id); });
            const response = await warehouse.balanceSerials(context.balance_id, query);
            if (sequence !== this.sequence || !this.current()) return;
            ui.rows(response).rows.forEach(row => eligible.add(Number(row.id)));
          }
          if (rows.some(row => !eligible.has(Number(row.id)))) throw new Error('部分编号已不可用，请重新选择');
        } else if (this.properties.mode === 'local') {
          const allowed = new Set(this.properties.rows.map(row => String(row.id)));
          if (rows.some(row => !allowed.has(String(row.id)))) throw new Error('所选明细已不属于当前单据，请重新核对');
        }
        if (sequence === this.sequence && this.current()) this.triggerEvent('confirm', { rows });
      } catch (error) { if (sequence === this.sequence) this.setData({ error: ui.errorText(error) }); }
      finally { if (sequence === this.sequence) this.setData({ loading: false }); }
    },
    cancel() { if (!this.properties.locked && !this.data.loading) this.triggerEvent('cancel'); }, stop() {},
  },
});
