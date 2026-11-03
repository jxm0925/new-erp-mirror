const warehouse = require('../../services/warehouse');
const ui = require('../../utils/warehouse-page');

function units(value) {
  const text = ui.quantity(String(value === null || value === undefined ? '' : value).trim());
  if (!/^\d{1,10}(?:\.\d{1,4})?$/.test(text)) return null;
  const parts = text.split('.');
  return Number(parts[0]) * 10000 + Number((parts[1] || '').padEnd(4, '0'));
}
function quantity(value) { const text = String(value).padStart(5, '0'); return ui.quantity(text.slice(0, -4) + '.' + text.slice(-4)); }
function display(value) { return value === null || value === undefined || value === '' ? '—' : ui.quantity(value); }
function serials(pick) {
  const result = {};
  (pick.serial_ids || []).forEach((id, index) => { result[String(id)] = { id: Number(id), serial_no: (pick.serial_nos || [])[index] || '' }; });
  return result;
}

Component({
  properties: {
    open: Boolean, workOrderId: Number, operationId: Number,
    line: { type: Object, value: {} }, locked: Boolean,
    otherPicks: { type: Array, value: [] },
    initialScan: { type: Object, value: null }, errorMessage: { type: String, value: '' },
  },
  data: {
    material: {}, mode: 'balance', visibleRows: [], displayedRows: [], chosen: [], showChosen: false,
    keyword: '', locationFilter: {}, page: 1, lastPage: 1, total: 0, loading: false, error: '',
    totalQty: '0', remainingQty: '', excessQty: '', totalMatch: false, summaryInvalid: false,
    serialBalance: {}, serialChosen: [], serialCount: 0,
  },
  observers: {
    open(open) { if (open) this.initialize(); else { this.invalidate(); this.setData({ loading: false }); } },
    'workOrderId, operationId, line, otherPicks': function () {
      if (this.properties.open && this.scope !== this.contextKey()) this.initialize();
    },
    locked(locked) {
      if (locked) { this.invalidate(); this.setData({ loading: false }); }
      else if (this.properties.open && this.scope && !this.data.visibleRows.length) this.load();
    },
  },
  lifetimes: { detached() { this.detached = true; this.invalidate(); } },
  methods: {
    invalidate() { this.sequence = (this.sequence || 0) + 1; },
    contextKey() {
      const line = this.properties.line || {};
      return JSON.stringify([this.properties.workOrderId, this.properties.operationId, line.material_id || line.id,
        line.component_item_id, line.unit_name, line.qty, line.serial_tracking_mode, this.properties.otherPicks]);
    },
    current(sequence) {
      return !this.detached && this.properties.open && !this.properties.locked
        && (sequence === undefined || sequence === this.sequence) && this.scope === this.contextKey()
        && this.token === wx.getStorageSync('erp_token')
        && this.permissions === JSON.stringify(wx.getStorageSync('erp_permissions') || []);
    },
    editable() {
      if (this.current()) return true;
      if (this.properties.open && !this.properties.locked) this.setData({ error: '账号、权限或配料需求已变化，请重新打开选择窗口' });
      return false;
    },
    initialize() {
      this.invalidate(); this.scope = this.contextKey(); this.token = wx.getStorageSync('erp_token');
      this.permissions = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
      const line = this.properties.line || {}; this.selection = {}; this.serialDraft = {}; this.otherQuantities = {}; this.otherSerialIds = new Set();
      (this.properties.otherPicks || []).forEach(pick => {
        const id = String(pick.balance_id); const qty = units(pick.qty);
        this.otherQuantities[id] = qty === null ? NaN : (this.otherQuantities[id] === undefined ? 0 : this.otherQuantities[id]) + qty;
        (pick.serial_ids || []).forEach(serialId => this.otherSerialIds.add(Number(serialId)));
      });
      (line.picks || []).forEach(pick => {
        const id = Number(pick.balance_id); if (!id || units(pick.qty) === 0) return;
        this.selection[String(id)] = { row: this.balanceRow(Object.assign({}, pick, { id, unit_name: pick.unit_name || line.unit_name,
          serial_tracking_mode: pick.serial_tracking_mode || line.serial_tracking_mode })), qty: pick.qty === null || pick.qty === undefined ? '' : String(pick.qty), serials: serials(pick) };
      });
      this.setData({ material: { name: line.component_item_name || line.item_name || '', code: line.component_item_code || line.item_code || '',
        spec: line.spec || line.item_spec || '', unit: line.unit_name || '', demandQty: display(line.qty) },
      mode: 'balance', visibleRows: [], displayedRows: [], keyword: '', locationFilter: {}, page: 1, lastPage: 1,
      total: 0, showChosen: false, error: '', loading: false, serialBalance: {}, serialChosen: [], serialCount: 0 });
      this.sync();
      const pending = this.load(); const sequence = this.sequence;
      pending.then(() => {
        if (this.current(sequence) && this.properties.initialScan) this.scan({ detail: { result: this.properties.initialScan.result || this.properties.initialScan } });
      });
    },
    incompatible(row) {
      const line = this.properties.line || {}; const itemId = Number(row.item_id || row.component_item_id || 0);
      return (itemId && itemId !== Number(line.component_item_id)) || (row.unit_name && line.unit_name && row.unit_name !== line.unit_name);
    },
    balanceRow(row) {
      const raw = row.stock_quantity_available === undefined ? row.quantity_available : row.stock_quantity_available;
      const available = units(raw); const other = this.otherQuantities[String(row.id)] === undefined ? 0 : this.otherQuantities[String(row.id)];
      return Object.assign({}, row, { stock_quantity_available: raw,
        quantity_available: available === null || !Number.isSafeInteger(other) ? null : quantity(Math.max(0, available - other)) });
    },
    describe(row) {
      const entry = (this.selection || {})[String(row.id)];
      const available = units(row.quantity_available); const picked = entry ? units(entry.qty) : 0;
      return Object.assign({}, row, { availableText: display(row.quantity_available), qtyText: entry ? entry.qty : '', serialQtyText: entry ? display(entry.qty) : '0',
        remainingStockText: available === null || picked === null ? '—' : quantity(Math.max(0, available - picked)),
        serialCount: entry ? Object.keys(entry.serials).length : 0,
        serialText: entry ? Object.values(entry.serials).map(serial => serial.serial_no || ('编号 ' + serial.id)).join('、') : '',
        requiredSerial: (row.serial_tracking_mode || this.properties.line.serial_tracking_mode) === 'required',
        supportsSerial: ['required', 'optional'].includes(row.serial_tracking_mode || this.properties.line.serial_tracking_mode),
        incompatible: !!this.incompatible(row), chosen: !!entry, rowError: entry ? this.entryError(entry) : '' });
    },
    entryError(entry) {
      if (this.incompatible(entry.row)) return '物料或单位与本次需求不一致';
      const qty = units(entry.qty); const available = units(entry.row.quantity_available);
      if (qty === null || qty <= 0) return '请输入大于 0、最多 4 位小数的本次数量';
      if (available === null) return '该批次可用数量未返回，请刷新后核对';
      if (qty > available) return '本次数量超过该批次可用数量';
      const ids = Object.values(entry.serials).map(row => Number(row.id));
      if (ids.some(id => !Number.isSafeInteger(id) || id <= 0)) return '所选编号无效，请重新选择';
      if (ids.some(id => this.otherSerialIds.has(id))) return '所选编号已用于本次其他用料，请重新选择';
      const mode = entry.row.serial_tracking_mode || this.properties.line.serial_tracking_mode;
      if (mode === 'required' && ids.length * 10000 !== qty) return '本次数量必须与所选编号数量一致';
      if (ids.length * 10000 > qty) return '所选编号数量不能超过本次数量';
      if (mode === 'none' && ids.length) return '该物料不使用序列号，请移除编号后重选';
      return '';
    },
    sync() {
      const entries = Object.values(this.selection || {}); let total = 0; let invalid = false;
      entries.forEach(entry => { const value = units(entry.qty); if (value === null) invalid = true; else total += value; });
      if (!Number.isSafeInteger(total)) invalid = true;
      const target = units(this.properties.line.qty); const chosen = entries.map(entry => this.describe(entry.row));
      const serialChosen = Object.values(this.serialDraft || {});
      const serialAvailable = units(this.data.serialBalance.quantity_available);
      const serialEntry = (this.selection || {})[String(this.data.serialBalance.id)];
      const originalQty = serialEntry ? units(serialEntry.qty) : 0;
      const serialRequired = (this.data.serialBalance.serial_tracking_mode || this.properties.line.serial_tracking_mode) === 'required';
      const serialQty = serialRequired ? serialChosen.length * 10000 : originalQty === null ? null : Math.max(originalQty, serialChosen.length * 10000);
      const visibleRows = this.data.mode === 'serial'
        ? this.data.visibleRows.map(row => Object.assign({}, row, { chosen: !!this.serialDraft[String(row.id)] }))
        : this.data.visibleRows.map(row => this.describe(row));
      const selectedRows = this.data.mode === 'serial' ? serialChosen.map(row => Object.assign({}, row, { chosen: true })) : chosen;
      this.setData({ chosen, serialChosen, serialCount: serialChosen.length, visibleRows,
        serialRemainingText: serialAvailable === null || serialQty === null ? '—' : quantity(Math.max(0, serialAvailable - serialQty)),
        displayedRows: this.data.showChosen ? selectedRows : visibleRows, summaryInvalid: invalid,
        totalQty: invalid ? '—' : quantity(total), remainingQty: !invalid && target !== null && total < target ? quantity(target - total) : '',
        excessQty: !invalid && target !== null && total > target ? quantity(total - target) : '',
        totalMatch: !invalid && target !== null && total === target });
    },
    query(extra) {
      const query = Object.assign({ material_id: Number(this.properties.line.material_id || this.properties.line.id), mode: this.data.mode,
        balance_id: this.data.mode === 'serial' ? Number(this.data.serialBalance.id) : undefined,
        keyword: this.data.keyword.trim(), location_id: this.data.locationFilter.location_id || undefined, page: this.data.page, per_page: 10 }, extra || {});
      if (query.mode === 'serial') {
        const excluded = new Set();
        (this.properties.otherPicks || []).filter(pick => Number(pick.balance_id) === Number(query.balance_id))
          .forEach(pick => (pick.serial_ids || []).forEach(id => excluded.add(Number(id))));
        Array.from(excluded).forEach((id, index) => { query[`exclude_serial_ids[${index}]`] = id; });
      }
      return query;
    },
    endpoint() { return `production/work-orders/${Number(this.properties.workOrderId)}/operations/${Number(this.properties.operationId)}/delivery-candidates`; },
    async load() {
      if (!this.editable()) return;
      const sequence = this.sequence = (this.sequence || 0) + 1; const mode = this.data.mode;
      this.setData({ loading: true, error: '' });
      try {
        const result = ui.rows(await warehouse.get(this.endpoint(), this.query()));
        if (!this.current(sequence)) return;
        const rows = mode === 'serial' ? result.rows.filter(row => Number(row.inventory_balance_id) === Number(this.data.serialBalance.id) && !this.otherSerialIds.has(Number(row.id))) : result.rows.map(row => this.balanceRow(row));
        rows.forEach(row => {
          if (mode === 'balance' && this.selection[String(row.id)]) this.selection[String(row.id)].row = Object.assign({}, row);
          if (mode === 'serial' && this.serialDraft[String(row.id)]) this.serialDraft[String(row.id)] = Object.assign({}, row);
        });
        this.setData({ visibleRows: rows, page: result.page, lastPage: result.lastPage, total: result.total }); this.sync();
      } catch (error) { if (this.current(sequence)) this.setData({ visibleRows: [], displayedRows: [], error: ui.errorText(error) }); }
      finally { if (this.current(sequence)) this.setData({ loading: false }); }
    },
    input(event) { if (this.editable()) this.setData({ keyword: event.detail.value }); },
    search() { if (this.editable() && !this.data.loading) { this.setData({ page: 1, showChosen: false }); this.load(); } },
    refresh() { if (this.editable() && !this.data.loading) this.load(); },
    turn(event) {
      const page = this.data.page + Number(event.currentTarget.dataset.delta);
      if (this.editable() && !this.data.loading && page > 0 && page <= this.data.lastPage) { this.setData({ page }); this.load(); }
    },
    toggleChosen() { if (this.editable() && !this.data.loading) { this.setData({ showChosen: !this.data.showChosen }); this.sync(); } },
    clearLocation() { if (this.editable() && !this.data.loading) { this.setData({ locationFilter: {}, page: 1, showChosen: false }); this.load(); } },
    qtyInput(event) {
      if (!this.editable() || this.data.loading) return;
      const id = String(event.currentTarget.dataset.id); const row = this.data.displayedRows.find(row => String(row.id) === id);
      if (!row || row.requiredSerial || row.incompatible) return;
      const old = this.selection[id]; const text = String(event.detail.value);
      if (!/^\d{0,10}(?:\.\d{0,4})?$/.test(text)) { this.setData({ error: '数量最多填写 4 位小数' }); return old ? old.qty : ''; }
      if (units(text) === 0) delete this.selection[id];
      else this.selection[id] = { row: Object.assign({}, row), qty: text, serials: old ? old.serials : {} };
      this.setData({ error: '' }); this.sync();
    },
    remove(event) {
      if (!this.editable() || this.data.loading) return;
      delete this.selection[String(event.currentTarget.dataset.id)]; this.setData({ error: '' }); this.sync();
    },
    openSerial(event) {
      if (!this.editable() || this.data.loading) return;
      const row = this.data.displayedRows.find(row => String(row.id) === String(event.currentTarget.dataset.id));
      if (!row || row.incompatible || !row.supportsSerial) return;
      this.balanceView = { keyword: this.data.keyword, page: this.data.page, showChosen: this.data.showChosen };
      const entry = this.selection[String(row.id)]; this.serialDraft = Object.assign({}, entry ? entry.serials : {});
      this.setData({ mode: 'serial', serialBalance: Object.assign({}, row), keyword: '', page: 1, lastPage: 1, total: 0, showChosen: false, visibleRows: [], error: '' });
      this.sync(); this.load();
    },
    chooseSerial(event) {
      if (!this.editable() || this.data.loading) return;
      const id = String(event.currentTarget.dataset.id); const row = this.data.displayedRows.find(row => String(row.id) === id);
      if (!row) return;
      if (!this.serialDraft[id] && this.otherSerialIds.has(Number(row.id))) return this.setData({ error: '该编号已用于本次其他用料' });
      if (this.serialDraft[id]) delete this.serialDraft[id];
      else this.serialDraft[id] = Object.assign({}, row);
      this.setData({ error: '' }); this.sync();
    },
    returnBalances() {
      this.invalidate(); this.serialDraft = {};
      this.setData(Object.assign({ mode: 'balance', serialBalance: {}, visibleRows: [], loading: false, error: '' }, this.balanceView || { keyword: '', page: 1, showChosen: false }));
      this.sync(); this.load();
    },
    cancelSerial() { if (this.editable()) this.returnBalances(); },
    applySerial() {
      if (!this.editable() || this.data.loading) return;
      const row = this.data.serialBalance; const id = String(row.id); const old = this.selection[id]; const count = Object.keys(this.serialDraft).length;
      const required = row.serial_tracking_mode === 'required' || (!row.serial_tracking_mode && this.properties.line.serial_tracking_mode === 'required');
      if (!count && (required || !old)) { delete this.selection[id]; this.returnBalances(); return; }
      const oldQty = old ? units(old.qty) : 0;
      if (oldQty === null && !required) return this.setData({ error: '请返回批次列表，先填写有效的本次数量' });
      const entry = { row: Object.assign({}, row), qty: quantity(required ? count * 10000 : Math.max(oldQty, count * 10000)), serials: Object.assign({}, this.serialDraft) };
      const error = this.entryError(entry); if (error) return this.setData({ error });
      this.selection[id] = entry; this.returnBalances();
    },
    async scan(event) {
      if (!this.editable() || this.data.loading) return;
      const result = event.detail.result || {};
      if (result.type === 'item') {
        if (Number(result.item_id || result.id) !== Number(this.properties.line.component_item_id)) return this.setData({ error: '请扫描当前配料物料' });
        if (result.unit_name && this.properties.line.unit_name && result.unit_name !== this.properties.line.unit_name) return this.setData({ error: '扫码物料的单位与当前需求不一致' });
        this.setData({ keyword: '', page: 1, showChosen: false, error: '' }); return this.load();
      }
      if (result.type === 'location') {
        if (!Number(result.location_id || result.id)) return this.setData({ error: '该库位无效，请重新扫描' });
        if (this.data.mode === 'serial' && Number(this.data.serialBalance.location_id) !== Number(result.location_id || result.id)) return this.setData({ error: '请返回批次列表后切换库位' });
        this.setData({ locationFilter: { location_id: Number(result.location_id || result.id), location_code: result.location_code || result.code,
          location_name: result.location_name || result.name }, keyword: '', page: 1, showChosen: false }); return this.load();
      }
      if (result.type !== 'serial' || result.resource !== 'inventory_serial' || !Number(result.inventory_serial_id)) return this.setData({ error: '请扫描当前物料、库位或库存序列号' });
      if (Number(result.item_id) !== Number(this.properties.line.component_item_id)) return this.setData({ error: '该序列号不属于当前配料物料' });
      if (this.otherSerialIds.has(Number(result.inventory_serial_id))) return this.setData({ error: '该序列号已用于本次其他用料' });
      const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '' });
      try {
        const balances = ui.rows(await warehouse.get(this.endpoint(), this.query({ mode: 'balance', balance_id: undefined, keyword: '', page: 1, serial_id: Number(result.inventory_serial_id) })));
        if (!this.current(sequence)) return;
        const balance = balances.rows.find(row => Number(row.id) === Number(result.inventory_balance_id));
        if (!balance || this.incompatible(balance)) throw new Error('该序列号不在本工单可用库存范围内');
        const row = this.balanceRow(balance);
        if (!['required', 'optional'].includes(row.serial_tracking_mode || this.properties.line.serial_tracking_mode)) throw new Error('当前物料不使用序列号，请按批次填写数量');
        if (this.data.mode === 'serial' && Number(this.data.serialBalance.id) !== Number(row.id)) throw new Error('请先返回批次列表，再扫描其他批次的编号');
        const serialRows = ui.rows(await warehouse.get(this.endpoint(), this.query({ mode: 'serial', balance_id: Number(row.id), keyword: '', page: 1, serial_id: Number(result.inventory_serial_id) })));
        if (!this.current(sequence)) return;
        const serial = serialRows.rows.find(value => Number(value.id) === Number(result.inventory_serial_id) && Number(value.inventory_balance_id) === Number(row.id));
        if (!serial) throw new Error('该序列号已不可用于本次配料，请重新核对');
        const id = String(row.id); const old = this.selection[id];
        const selected = this.data.mode === 'serial' ? this.serialDraft : (old ? old.serials : {});
        if (selected[String(serial.id)]) { this.setData({ error: '该序列号已选择' }); return; }
        const nextSerials = Object.assign({}, selected, { [String(serial.id)]: Object.assign({}, serial) });
        const available = units(row.quantity_available);
        if (available === null) throw new Error('该批次可用数量未返回，请刷新后核对');
        if (Object.keys(nextSerials).length * 10000 > available) throw new Error('所选编号数量超过该批次可用数量');
        if (this.data.mode === 'serial') this.serialDraft = nextSerials;
        else {
          const oldQty = old ? units(old.qty) : 0;
          if (oldQty === null) throw new Error('请先填写该批次有效的本次数量');
          const required = (row.serial_tracking_mode || this.properties.line.serial_tracking_mode) === 'required';
          this.selection[id] = { row: Object.assign({}, row), qty: quantity(required ? Object.keys(nextSerials).length * 10000 : Math.max(oldQty, Object.keys(nextSerials).length * 10000)), serials: nextSerials };
        }
        this.setData({ keyword: '', error: '' }); this.sync();
      } catch (error) { if (this.current(sequence)) this.setData({ error: ui.errorText(error) }); }
      finally { if (this.current(sequence)) this.setData({ loading: false }); }
    },
    confirm() {
      if (!this.editable() || this.data.loading || this.data.mode !== 'balance') return;
      const entries = Object.values(this.selection || {}); const seen = new Set();
      for (const entry of entries) {
        const error = this.entryError(entry); if (error) return this.setData({ error });
        for (const serial of Object.values(entry.serials)) {
          if (seen.has(Number(serial.id))) return this.setData({ error: '同一序列号不能重复选择' });
          seen.add(Number(serial.id));
        }
      }
      this.sync(); if (!this.data.totalMatch) return this.setData({ error: '已选合计必须等于本次需配数量' });
      this.setData({ error: '' });
      this.triggerEvent('confirm', { picks: entries.map(entry => ({ balance_id: Number(entry.row.id), qty: quantity(units(entry.qty)), serial_ids: Object.values(entry.serials).map(row => Number(row.id)) })) });
    },
    cancel() { if (!this.properties.locked) { this.invalidate(); this.triggerEvent('cancel'); } }, stop() {},
  },
});
