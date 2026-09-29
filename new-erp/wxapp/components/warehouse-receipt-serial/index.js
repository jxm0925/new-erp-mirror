const warehouse = require('../../services/warehouse');
const page = require('../../utils/warehouse-page');
const { receiptSelection } = require('../../utils/warehouse-delivery');
Component({
  properties: { open: Boolean, deliveryId: Number, lineId: Number, selected: { type: Array, value: [] }, unit: String },
  data: { rows: [], keyword: '', page: 1, lastPage: 1, total: 0, loading: false, error: '', accepted: 0, rejected: 0 },
  observers: { open(value) {
    this.sequence = (this.sequence || 0) + 1;
    if (!value) return;
    this.selection = {};
    (this.properties.selected || []).forEach(row => { this.selection[row.id] = Object.assign({}, row); });
    this.setData({ keyword: '', page: 1, rows: [], error: '' }); this.sync(); this.load();
  } },
  lifetimes: { detached() { this.sequence = (this.sequence || 0) + 1; } },
  methods: {
    stop() {},
    load() {
      const sequence = this.sequence = (this.sequence || 0) + 1;
      this.setData({ loading: true, error: '' });
      return warehouse.get('production/material-picking-workspace/receipt-serials', { delivery_id: this.properties.deliveryId, delivery_line_id: this.properties.lineId, keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10 }).then(response => {
        if (sequence !== this.sequence || !this.properties.open) return;
        const result = page.rows(response);
        this.setData({ rows: result.rows, page: result.page, total: result.total, lastPage: result.lastPage, loading: false }); this.sync();
      }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: page.errorText(error) }); });
    },
    sync() {
      const selected = Object.values(this.selection || {});
      this.setData({ rows: this.data.rows.map(row => Object.assign({}, row, this.selection[row.id] || { disposition: '', reason: '' })), accepted: selected.filter(row => row.disposition === 'accepted').length, rejected: selected.filter(row => row.disposition === 'rejected').length });
    },
    keywordInput(event) { this.setData({ keyword: event.detail.value }); },
    search() { this.setData({ page: 1 }); this.load(); },
    turnPage(event) { const next = this.data.page + Number(event.currentTarget.dataset.delta); if (!this.data.loading && next > 0 && next <= this.data.lastPage) { this.setData({ page: next }); this.load(); } },
    choose(event) {
      const row = this.data.rows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id));
      if (!row || this.data.loading) return;
      const disposition = event.currentTarget.dataset.disposition;
      // One map entry owns each serial; switching disposition cannot leave it in both sets.
      if (row.disposition === disposition) delete this.selection[row.id];
      else this.selection[row.id] = Object.assign({}, row, { disposition, reason: disposition === 'rejected' ? row.reason || '' : '' });
      this.setData({ error: '' }); this.sync();
    },
    reasonInput(event) {
      const id = event.currentTarget.dataset.id;
      if (this.selection[id]) { this.selection[id].reason = event.detail.value; this.sync(); }
    },
    cancel() { this.triggerEvent('cancel'); },
    confirm() {
      if (this.data.loading) return;
      try { const rows = Object.values(this.selection || {}); const quantities = receiptSelection(rows); this.triggerEvent('confirm', { rows, quantities }); }
      catch (error) { this.setData({ error: error.message }); }
    },
  },
});
