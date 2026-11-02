const warehouse = require('../../../services/warehouse');
const page = require('../../../utils/warehouse-page');
Page({
  data: { loading: true, saving: false, error: '', formError: '', physicalMode: false, status: 'in_transit', keyword: '', rows: [], page: 1, lastPage: 1, total: 0,
    canCreate: false, canPhysical: false, canPost: false, canReadQuantity: false, canReadPhysical: false, formOpen: false, source: {}, target: {}, physical: null, lines: [], quantity: '', selectedBalance: null, serials: [], reason: '', targetBatch: '',
    locatorOpen: false, locatorSide: 'source', locatorValue: {}, balanceOpen: false, balanceKeyword: '', balanceRows: [], balancePage: 1, balanceLast: 1, balanceTotal: 0, pendingJobs: [] },
  onLoad() { const can = page.permissions(); const canReadQuantity = can('inventory.transfer.view') || can('inventory.inbound.confirm'); const canReadPhysical = canReadQuantity || can('inventory.physical.manage'); this.setData({ canCreate: can('inventory.transfer.create'), canPhysical: can('inventory.physical.manage'), canPost: can('inventory.transfer.post'), canReadQuantity, canReadPhysical, physicalMode: !canReadQuantity && !can('inventory.transfer.create') && canReadPhysical }); },
  onShow() { if (!page.resumingScan(this)) this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1; this.setData({ loading: true, error: '', pendingJobs: ['inventory_transfer.create', 'inventory_transfer.post', 'material_physical_transfer.dispatch'].flatMap(warehouse.pendingJobs) });
    const query = { status: this.data.status, keyword: this.data.keyword.trim(), page: this.data.page, per_page: 10 };
    if (!this.data.physicalMode && !this.data.canReadQuantity) { this.setData({ loading: false, rows: [], total: 0, lastPage: 1 }); return Promise.resolve(); }
    return (this.data.physicalMode ? warehouse.physicalTransfers(query) : warehouse.transfers(query)).then(response => {
      if (sequence !== this.sequence) return; const result = page.rows(response);
      this.setData({ rows: result.rows.map(row => Object.assign({}, row, { dateText: page.dateTime(row.dispatched_at || row.created_at),
        sourceName: (row.source_warehouse || {}).warehouse_name, targetName: (row.target_warehouse || {}).warehouse_name })), total: result.total, lastPage: result.lastPage, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: page.errorText(error) }); });
  },
  input(event) { this.setData({ [event.currentTarget.dataset.field]: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  switchMode(event) { this.setData({ physicalMode: event.currentTarget.dataset.mode === 'physical', status: 'in_transit', page: 1 }); this.load(); },
  switchStatus(event) { this.setData({ status: event.currentTarget.dataset.status, page: 1 }); this.load(); },
  turn(event) { const target = this.data.page + Number(event.currentTarget.dataset.delta); if (target > 0 && target <= this.data.lastPage && !this.data.loading) { this.setData({ page: target }); this.load(); } },
  open(event) { const row = this.data.rows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (row) page.openDocument({ kind: this.data.physicalMode ? 'material_physical_transfer' : 'inventory_transfer', id: row.id }); },
  newTransfer() { if (this.data.saving) return; this.setData({ formOpen: true, source: {}, target: {}, physical: null, lines: [], selectedBalance: null, serials: [], quantity: '', reason: '', targetBatch: '', formError: '' }); },
  closeForm() { if (!this.data.saving) this.setData({ formOpen: false }); },
  openLocator(event) { const side = event.currentTarget.dataset.side; this.setData({ locatorOpen: true, locatorSide: side, locatorValue: this.data[side] }); },
  closeLocator() { this.setData({ locatorOpen: false }); },
  chooseLocator(event) {
    const side = this.data.locatorSide; const value = event.detail;
    if (side === 'source' && (Number(value.warehouse_id) !== Number(this.data.source.warehouse_id) || Number(value.location_id) !== Number(this.data.source.location_id))) { this.balanceSequence = (this.balanceSequence || 0) + 1; this.setData({ lines: [], selectedBalance: null, serials: [], physical: null, balanceOpen: false }); }
    this.setData({ [side]: value, locatorOpen: false });
  },
  async scanSource(event) {
    const result = event.detail.result;
    if (this.data.physicalMode && result.type !== 'physical') return this.setData({ formError: '请扫描可用的板材或管材实物编号' });
    if (result.type === 'location') { this.balanceSequence = (this.balanceSequence || 0) + 1; this.setData({ source: result, lines: [], selectedBalance: null, serials: [], physical: null, balanceOpen: false }); return; }
    if (result.type === 'physical') {
      if (!this.data.physicalMode || result.status !== 'AVAILABLE' || !result.warehouse_id) return this.setData({ formError: '请选择实物调拨，并扫描可用的仓内实物' });
      this.setData({ physical: result, source: { warehouse_id: result.warehouse_id, warehouse_name: result.warehouse_name, location_id: result.location_id, location_code: result.location_code }, targetBatch: result.batch_no || '', formError: '' }); return;
    }
    if (this.data.physicalMode) return this.setData({ formError: '请扫描板材或管材实物编号' });
    if (!this.data.source.warehouse_id) return this.setData({ formError: '请先选择调出仓库与库位' });
    this.setData({ balanceKeyword: result.item_code || result.code || '', balancePage: 1, balanceOpen: true }); this.loadBalances();
  },
  scanTarget(event) { const result = event.detail.result; this.setData({ target: { warehouse_id: result.warehouse_id, warehouse_name: result.warehouse_name, location_id: result.location_id, location_code: result.location_code, location_name: result.location_name } }); },
  openBalances() { if (!this.data.source.warehouse_id) return this.setData({ formError: '请先选择调出仓库与库位' }); this.setData({ balanceOpen: true, balancePage: 1 }); this.loadBalances(); },
  closeBalances() { this.setData({ balanceOpen: false }); },
  loadBalances() { const sequence = this.balanceSequence = (this.balanceSequence || 0) + 1; const source = JSON.stringify(this.data.source); return warehouse.transferBalances({ warehouse_id: this.data.source.warehouse_id, location_id: this.data.source.location_id || undefined, keyword: this.data.balanceKeyword, page: this.data.balancePage, per_page: 10 }).then(response => { if (sequence !== this.balanceSequence || source !== JSON.stringify(this.data.source) || !this.data.balanceOpen) return; const result = page.rows(response); this.setData({ balanceRows: result.rows, balanceLast: result.lastPage, balanceTotal: result.total }); }).catch(error => { if (sequence === this.balanceSequence) this.setData({ formError: page.errorText(error) }); }); },
  searchBalances() { this.setData({ balancePage: 1 }); this.loadBalances(); },
  turnBalance(event) { const target = this.data.balancePage + Number(event.currentTarget.dataset.delta); if (target > 0 && target <= this.data.balanceLast) { this.setData({ balancePage: target }); this.loadBalances(); } },
  chooseBalance(event) { const row = this.data.balanceRows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (row) this.setData({ selectedBalance: row, serials: [], quantity: '', balanceOpen: false, formError: '' }); },
  scanSerial(event) {
    const result = event.detail.result; const balance = this.data.selectedBalance;
    if (!balance || Number(result.inventory_balance_id) !== Number(balance.id) || result.status !== 'available') return this.setData({ formError: '该序列号不属于所选可用库存批次' });
    if (this.data.serials.some(row => row.id === result.inventory_serial_id)) return this.setData({ formError: '该序列号已核对' });
    this.setData({ serials: this.data.serials.concat({ id: result.inventory_serial_id, serial_no: result.serial_no }), formError: '' });
  },
  addLine() {
    const balance = this.data.selectedBalance; const qty = Number(this.data.quantity);
    if (!balance || !(qty > 0) || qty > Number(balance.quantity_available)) return this.setData({ formError: '请填写大于零且不超过可用库存的数量' });
    if (this.data.lines.some(row => row.inventory_balance_id === balance.id)) return this.setData({ formError: '同一库存批次已添加，请移除后重新选择' });
    if (balance.serial_tracking_mode === 'required' && this.data.serials.length !== qty) return this.setData({ formError: '请逐个核对与调拨数量一致的序列号' });
    if (this.data.serials.length > qty) return this.setData({ formError: '已核对序列号数量超过调拨数量' });
    this.setData({ lines: this.data.lines.concat({ inventory_balance_id: balance.id, item_name: balance.item_name, batch_no: balance.batch_no, unit_name: balance.unit_name,
      quantity: this.data.quantity, serial_ids: this.data.serials.map(row => row.id), serial_nos: this.data.serials.map(row => row.serial_no) }), selectedBalance: null, serials: [], quantity: '', formError: '' });
  },
  removeLine(event) { this.setData({ lines: this.data.lines.filter(row => row.inventory_balance_id !== Number(event.currentTarget.dataset.id)) }); },
  async save(event) {
    if (this.data.saving) return;
    if (!this.data.target.location_id) return this.setData({ formError: '请扫描或选择目标仓库和申请库位' });
    if (this.data.physicalMode && (!this.data.physical || !this.data.reason.trim() || !this.data.targetBatch.trim())) return this.setData({ formError: '请核对实物、目标批次和调拨原因' });
    if (!this.data.physicalMode && !this.data.lines.length) return this.setData({ formError: '请先添加调拨明细' });
    this.setData({ saving: true, formError: '' }); let createdDraft = null;
    try {
      let result;
      if (this.data.physicalMode) result = await warehouse.submit('material_physical_transfer.dispatch', this.data.physical.id, { expected_version: this.data.physical.business_version, target_warehouse_id: this.data.target.warehouse_id, target_location_id: this.data.target.location_id, target_batch_no: this.data.targetBatch.trim(), reason: this.data.reason.trim() });
      else {
        result = await warehouse.submit('inventory_transfer.create', this.data.source.warehouse_id, { source_warehouse_id: this.data.source.warehouse_id, target_warehouse_id: this.data.target.warehouse_id, remark: this.data.reason || undefined, lines: this.data.lines.map(row => ({ inventory_balance_id: row.inventory_balance_id, target_location_id: this.data.target.location_id, quantity: row.quantity, serial_ids: row.serial_ids })) });
        createdDraft = result;
        this.setData({ formOpen: false, status: 'draft' });
        if (event.currentTarget.dataset.post) result = await warehouse.submit('inventory_transfer.post', result.id, { expected_version: result.business_version });
      }
      wx.showToast({ title: result.status === 'POSTED' || result.transfer_status === 'posted' ? '同仓移库已完成' : result.transfer_status === 'draft' ? '调拨草稿已保存' : '已调出，等待目标仓接收', icon: 'none' });
      this.setData({ formOpen: false, status: result.transfer_status === 'draft' ? 'draft' : result.status === 'POSTED' || result.transfer_status === 'posted' ? 'posted' : 'in_transit', page: 1 }); await this.load();
    } catch (error) { this.setData({ formError: page.errorText(error) }); if (error.pendingCommand || createdDraft) this.setData({ formOpen: false, status: createdDraft ? 'draft' : this.data.status }); await this.load(); this.setData({ error: page.errorText(error) }); }
    finally { this.setData({ saving: false }); }
  },
  async post(event) { const row = this.data.rows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (!row || this.data.saving) return; this.setData({ saving: true }); try { const result = await warehouse.submit('inventory_transfer.post', row.id, { expected_version: row.business_version }); wx.showToast({ title: result.transfer_status === 'posted' ? '同仓移库已完成' : '已调出，等待目标仓接收', icon: 'none' }); await this.load(); } catch (error) { this.setData({ error: page.errorText(error) }); await this.load(); } finally { this.setData({ saving: false }); } },
  async recover(event) { const job = this.data.pendingJobs[Number(event.currentTarget.dataset.index)]; if (!job || this.data.saving) return; this.setData({ saving: true }); try { const result = await warehouse.submit(job.action, job.aggregate_id, {}); this.setData({ status: result.transfer_status === 'draft' ? 'draft' : result.status === 'POSTED' || result.transfer_status === 'posted' ? 'posted' : 'in_transit' }); wx.showToast({ title: '原操作结果已核对', icon: 'none' }); await this.load(); } catch (error) { await this.load(); this.setData({ error: page.errorText(error) }); } finally { this.setData({ saving: false }); } },
  stop() {},
});
