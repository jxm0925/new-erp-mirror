const production = require('../../services/production');

const definition = {
  properties: { taskId: Number, targetType: String, targetId: Number, mode: { type: String, value: 'supplement' } },
  data: { visible: false, keyword: '', categoryId: '', categoryRows: [], rows: [], loading: false, error: '',
    page: 1, lastPage: 1, total: 0, selectedCount: 0, showSelected: false, selectedRows: [] },
  lifetimes: { detached() { this.requestSequence = (this.requestSequence || 0) + 1; } },
  methods: {
    open(initial) {
      this.selection = new Map((initial || []).filter((row) => row && row.key).map((row) => [row.key, row]));
      this.categories = []; this.expanded = new Set(); this.serverRows = [];
      this.setData({ visible: true, keyword: '', categoryId: '', page: 1, lastPage: 1, total: 0,
        rows: [], categoryRows: [], error: '', showSelected: false, selectedCount: this.selection.size,
        selectedRows: Array.from(this.selection.values()) });
      this.fetchPage(1);
    },
    close() {
      // Ignore in-flight searches after cancellation or reopening another business mode.
      this.requestSequence = (this.requestSequence || 0) + 1;
      this.setData({ visible: false, loading: false });
    },
    stop() {},
    onKeyword(event) { this.setData({ keyword: event.detail.value }); },
    search() { this.setData({ showSelected: false }); this.fetchPage(1); },
    fetchPage(page) {
      const sequence = this.requestSequence = (this.requestSequence || 0) + 1;
      this.setData({ loading: true, error: '', rows: [], page });
      const query = { mode: this.properties.mode, keyword: this.data.keyword.trim(), page, per_page: 20 };
      if (this.data.categoryId !== '') query.category_id = this.data.categoryId;
      return production.materialOptions(this.properties.taskId, this.properties.targetType, this.properties.targetId, query)
        .then((response) => {
          if (sequence !== this.requestSequence || !this.data.visible) return;
          this.serverRows = (response.data || []).map((row) => Object.assign({}, row, {
            returnableText: row.returnable_base_qty == null ? '' : String(row.returnable_base_qty).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''),
          }));
          this.categories = response.categories || [];
          this.setData({ loading: false, page: response.current_page, lastPage: response.last_page, total: response.total });
          this.refreshCategories(); this.refreshRows();
        }).catch((error) => {
          if (sequence !== this.requestSequence || !this.data.visible) return;
          this.setData({ loading: false, error: error.message || '物料加载失败，请重试', rows: [] });
        });
    },
    refreshCategories() {
      const categories = this.categories || [];
      const ids = new Set(categories.map((row) => Number(row.id)));
      const rows = []; const visited = new Set();
      const walk = (parentId, depth) => categories.filter((row) => Number(row.parent_id || 0) === parentId).forEach((row) => {
        if (visited.has(Number(row.id))) return;
        visited.add(Number(row.id));
        const hasChildren = categories.some((child) => Number(child.parent_id) === Number(row.id));
        const expanded = this.expanded.has(Number(row.id));
        rows.push({ id: row.id, name: row.category_name, indent: Math.min(depth, 4) * 12, hasChildren, expanded });
        if (expanded) walk(Number(row.id), depth + 1);
      });
      walk(0, 0);
      categories.filter((row) => row.parent_id && !ids.has(Number(row.parent_id))).forEach((row) => {
        rows.push({ id: row.id, name: row.category_name, indent: 0, hasChildren: false });
      });
      this.setData({ categoryRows: rows });
    },
    chooseCategory(event) {
      const id = event.currentTarget.dataset.id;
      this.setData({ categoryId: id, showSelected: false });
      if (id !== '' && Number(id) > 0) this.expanded.add(Number(id));
      this.refreshCategories(); this.fetchPage(1);
    },
    toggleCategory(event) {
      const id = Number(event.currentTarget.dataset.id);
      if (this.expanded.has(id)) this.expanded.delete(id); else this.expanded.add(id);
      this.refreshCategories();
    },
    refreshRows() {
      const rows = this.serverRows || [];
      this.setData({
        rows: rows.map((row) => Object.assign({}, row, { checked: this.selection.has(row.key) })),
        selectedCount: this.selection.size,
        selectedRows: Array.from(this.selection.values()),
      });
    },
    toggleRow(event) {
      const key = event.currentTarget.dataset.key;
      const row = this.data.rows.find((item) => item.key === key);
      if (!row) return;
      if (this.selection.has(key)) this.selection.delete(key); else this.selection.set(key, row);
      this.refreshRows();
    },
    toggleSelected() { this.setData({ showSelected: !this.data.showSelected }); },
    removeSelected(event) {
      const key = event.currentTarget.dataset.key;
      this.selection.delete(key);
      this.refreshRows();
      if (this.selection.size === 0) this.setData({ showSelected: false });
    },
    previous() { this.goPage(this.data.page - 1); },
    next() { this.goPage(this.data.page + 1); },
    goPage(page) {
      if (this.data.loading || page < 1 || page > this.data.lastPage) return;
      this.fetchPage(page);
    },
    retry() { this.fetchPage(this.data.page); },
    confirm() {
      this.triggerEvent('confirm', { rows: Array.from(this.selection.values()), mode: this.properties.mode });
      this.close();
    },
  },
};

function create(host) {
  const instance = Object.assign({}, definition.methods, {
    data: JSON.parse(JSON.stringify(definition.data)),
    properties: {},
    setData(value) { Object.assign(this.data, value); host.setData({ selector: this.data }); },
    triggerEvent(name, detail) { if (name === 'confirm') host.onMaterialsSelected({ detail }); },
  });
  return instance;
}
const pageMethods = {};
for (const name of ['close','stop','onKeyword','search','chooseCategory','toggleCategory','toggleRow','toggleSelected','removeSelected','previous','next','retry','confirm']) {
  pageMethods['selector_' + name] = function (event) { return this.selectorController[name](event); };
}
module.exports = { create, pageMethods, definition };
