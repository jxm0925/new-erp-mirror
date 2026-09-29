const warehouse = require('../../services/warehouse');
const ui = require('../../utils/warehouse-page');
const view = require('../../utils/warehouse-document');
const clone = value => JSON.parse(JSON.stringify(value));
const labels = { restock: '重新入库', pending: '待检', scrap: '报废', rejected: '拒收' };
Component({
  properties: { open: Boolean, returnId: Number, line: { type: Object, value: {} }, value: { type: Object, value: {} }, locked: Boolean,
    conflict: Boolean, excluded: { type: Array, value: [] } },
  data: { rows: [], unavailableRows: [], keyword: '', page: 1, lastPage: 1, total: 0, loading: false, checking: false,
    error: '', conflictActive: false, batchNo: '', shipmentNos: '', selectedCount: 0, expectedCount: 0, counts: { restock: 0, pending: 0, scrap: 0, rejected: 0 },
    dispositions: view.returnDispositions.map(key => ({ key, label: labels[key] })) },
  observers: { 'open, returnId, line.id': function (open) { if (open) this.initialize(); else this.invalidate(); } },
  lifetimes: { detached() { this.invalidate(); } },
  methods: {
    invalidate() { this.sequence = (this.sequence || 0) + 1; this.checkSequence = (this.checkSequence || 0) + 1; },
    initialize() {
      this.invalidate(); this.selection = {};
      const original = this.properties.value || {};
      view.returnDispositions.forEach(disposition => (original.serialRows && original.serialRows[disposition] || []).forEach(row => { this.selection[row.id] = { row: clone(row), disposition }; }));
      this.setData({ rows: [], unavailableRows: [], keyword: '', page: 1, lastPage: 1, total: 0, error: '', loading: false, checking: false,
        conflictActive: !!this.properties.conflict, expectedCount: Number(original.received_base_qty || 0), batchNo: '', shipmentNos: '' });
      this.sync(); this.load(); if (Object.keys(this.selection).length) this.verifySelection(false);
    },
    query(extra) { return Object.assign({ return_id: this.properties.returnId, return_item_id: this.properties.line.id, page: this.data.page, per_page: 10, keyword: this.data.keyword.trim(), batch_no: this.data.batchNo || undefined }, extra || {}); },
    load() {
      if (!this.properties.returnId || !this.properties.line.id) { this.setData({ error: '退货单或退货明细不存在' }); return Promise.resolve(); }
      const sequence = this.sequence = (this.sequence || 0) + 1;
      this.setData({ loading: true, error: '' });
      return warehouse.get('inventory/warehouse-workspace/sales-return-serials', this.query()).then(response => {
        if (sequence !== this.sequence || !this.properties.open) return;
        const result = ui.rows(response);
        this.setData({ rows: result.rows, page: result.page, lastPage: result.lastPage, total: result.total, loading: false }); this.sync();
      }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, rows: [], error: ui.errorText(error) }); });
    },
    sync() {
      const counts = { restock: 0, pending: 0, scrap: 0, rejected: 0 }; const selection = Object.values(this.selection || {});
      selection.forEach(item => { counts[item.disposition]++; });
      // Cost reservations may point at a different original batch. Only the explicitly
      // selected sale identities may lock a batch or claim a source shipment here.
      const batchNo = selection.length ? String(selection[0].row.batch_no || '') : '';
      const shipmentNos = Array.from(new Set(selection.map(item => item.row.shipment_no).filter(Boolean))).join('、');
      const unavailable = new Set(this.data.unavailableRows.map(row => Number(row.id)));
      this.setData({ counts, selectedCount: selection.length, batchNo, shipmentNos,
        rows: this.data.rows.filter(row => !unavailable.has(Number(row.id))).map(row => Object.assign({}, row, { disposition: this.selection[row.id] && this.selection[row.id].disposition || '', excluded: this.properties.excluded.some(id => Number(id) === Number(row.id)) })) });
    },
    choose(event) {
      if (this.properties.locked || this.data.loading || this.data.checking || this.data.conflictActive) return;
      const id = Number(event.currentTarget.dataset.id); const disposition = event.currentTarget.dataset.disposition;
      const row = this.data.rows.find(row => Number(row.id) === id);
      if (!row || row.excluded || !view.returnDispositions.includes(disposition)) return;
      const oldBatch = this.data.batchNo; const selected = this.selection[id];
      if (selected && selected.disposition === disposition) delete this.selection[id];
      else {
        const others = Object.values(this.selection).filter(item => Number(item.row.id) !== id);
        if (others.some(item => String(item.row.batch_no || '') !== String(row.batch_no || ''))) return this.setData({ error: '不同原批次请分次收货' });
        const maximum = Number(this.properties.line.remaining_receivable_qty);
        if (!selected && Number.isFinite(maximum) && Object.keys(this.selection).length >= maximum) return this.setData({ error: '所选序列号数量不能超过待收数量' });
        this.selection[id] = { row: clone(row), disposition };
      }
      this.setData({ error: '' }); this.sync();
      if (oldBatch !== this.data.batchNo) { this.setData({ page: 1 }); this.load(); }
    },
    keywordInput(event) { this.setData({ keyword: event.detail.value }); },
    search() { if (this.properties.locked || this.data.checking) return; this.setData({ page: 1 }); return this.load(); },
    turnPage(event) {
      const page = this.data.page + Number(event.currentTarget.dataset.delta);
      if (!this.properties.locked && !this.data.loading && !this.data.checking && page > 0 && page <= this.data.lastPage) { this.setData({ page }); return this.load(); }
    },
    async verifySelection(clearConflict) {
      const sequence = this.checkSequence = (this.checkSequence || 0) + 1;
      const entries = Object.values(this.selection); const ids = entries.map(item => Number(item.row.id));
      if (!ids.length) { if (clearConflict) this.setData({ conflictActive: false, unavailableRows: [], error: '' }); return true; }
      this.setData({ checking: true, error: '' });
      try {
        // Revalidate only selected identities. Absence from a browsed page or keyword search
        // does not prove an off-page serial is unavailable; the exact ID filter does.
        const found = {};
        for (let offset = 0; offset < ids.length; offset += 100) {
          const filter = { keyword: '', batch_no: undefined, per_page: 100 };
          // erp-request encodes flat keys; explicit bracket keys retain Laravel array syntax.
          ids.slice(offset, offset + 100).forEach((id, index) => { filter[`ids[${index}]`] = id; });
          let pageNumber = 1; let lastPage = 1;
          do {
            const result = ui.rows(await warehouse.get('inventory/warehouse-workspace/sales-return-serials', this.query(Object.assign({}, filter, { page: pageNumber }))));
            if (sequence !== this.checkSequence || !this.properties.open) return false;
            result.rows.forEach(row => { found[row.id] = row; });
            lastPage = result.lastPage; pageNumber++;
          } while (pageNumber <= lastPage);
        }
        const unavailable = [];
        entries.forEach(item => {
          const current = found[item.row.id];
          if (!current || String(current.batch_no || '') !== String(item.row.batch_no || '') || (item.row.outbound_transaction_item_id && Number(current.outbound_transaction_item_id) !== Number(item.row.outbound_transaction_item_id))) {
            unavailable.push(Object.assign({}, item.row, { unavailable: true })); delete this.selection[item.row.id];
          } else this.selection[item.row.id] = { row: clone(current), disposition: item.disposition };
        });
        if (unavailable.length) this.setData({ unavailableRows: unavailable, conflictActive: true, expectedCount: Math.max(this.data.expectedCount, entries.length), error: '可用序列号已变化，请刷新后重新核对' });
        else if (clearConflict) this.setData({ conflictActive: false, unavailableRows: [], error: '' });
        this.setData({ checking: false }); this.sync(); return !unavailable.length;
      } catch (error) { if (sequence === this.checkSequence) this.setData({ checking: false, error: ui.errorText(error) }); return false; }
    },
    async refresh() {
      if (this.properties.locked || this.data.checking || this.data.loading) return;
      if (await this.verifySelection(true)) { this.setData({ page: 1 }); await this.load(); }
    },
    async confirm() {
      if (this.properties.locked || this.data.loading || this.data.checking || this.data.conflictActive) return;
      if (!await this.verifySelection(false)) return;
      const grouped = { restock: [], pending: [], scrap: [], rejected: [] };
      Object.values(this.selection).forEach(item => grouped[item.disposition].push(item.row));
      try {
        const result = view.returnSerialSelection(grouped, this.properties.line.remaining_receivable_qty);
        this.invalidate(); this.triggerEvent('confirm', Object.assign({ line_id: Number(this.properties.line.id) }, result));
      } catch (error) { this.setData({ error: ui.errorText(error) }); }
    },
    cancel() { if (this.properties.locked) return; this.invalidate(); this.triggerEvent('cancel'); }, stop() {},
  },
});
