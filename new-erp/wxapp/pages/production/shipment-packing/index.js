const packing = require('../../../services/packing');
const view = require('../../../utils/warehouse-page');
const statusLabels = { READY: '待加工', WAITING: '等待前序', IN_PROGRESS: '加工中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' };
const clone = value => JSON.parse(JSON.stringify(value));
Page({
  data: { shipmentId: 0, page: 1, lastPage: 1, total: 0, rows: [], loading: true, busy: false, error: '', keyword: '',
    detailOpen: false, operation: {}, pending: false, operationError: '', peopleOpen: false, peopleRows: [], selectedPeople: [], peopleKeyword: '',
    peoplePage: 1, peopleLast: 1, departments: [{ id: 0, name: '全部部门' }], departmentIndex: 0,
    materialsOpen: false, materialRows: [], materialDrafts: [], materialKeyword: '', materialPage: 1, materialLast: 1, materialError: '',
    categories: [{ id: 0, category_name: '全部分类' }], categoryIndex: 0,
    identityOpen: false, identityRows: [], selectedIdentities: [], identityMaterial: {}, identityPage: 1, identityLast: 1, identityKeyword: '',
    inspectionOpen: false, inspectionResult: 'passed', inspectionReason: '' },
  onLoad(options) { this.peopleSelected = {}; this.materialSelected = {}; this.identitySelected = {}; this.setData({ shipmentId: Number(options.shipment_id || 0) }); this.load(); },
  onUnload() { this.sequence = (this.sequence || 0) + 1; this.detailSequence = (this.detailSequence || 0) + 1; this.invalidateSelectors(); },
  invalidateSelectors() { ['peopleSequence', 'materialSequence', 'identitySequence'].forEach(key => { this[key] = (this[key] || 0) + 1; }); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  decorate(row) {
    const can = view.permissions();
    const actions = Object.assign({}, row.allowed_actions || {});
    Object.keys(actions).forEach(action => { actions[action] = actions[action] && can(action === 'inspect' ? 'sales_order.shipment.packing.quality' : 'sales_order.shipment.packing.execute'); });
    const owner = (row.participants || []).find(person => person.role === 'OWNER');
    return Object.assign({}, row, { statusLabel: statusLabels[row.status] || row.status, ownerName: owner ? owner.name : '未接单',
      is_public_snapshot: row.is_public_snapshot === true || row.is_public_snapshot === 1 || row.is_public_snapshot === '1',
      qtyText: view.quantity(row.planned_base_qty), allowed_actions: actions,
      contents: (row.contents || []).map(content => Object.assign({}, content, { qtyText: view.quantity(content.base_qty), serialText: (content.serial_nos || []).join('、') })) });
  },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return packing.operations({ shipment_id: this.data.shipmentId || undefined, page: this.data.page, per_page: 10, keyword: this.data.keyword }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response); this.setData({ rows: paged.rows.map(row => this.decorate(row)), page: paged.page, lastPage: paged.lastPage, total: paged.total, loading: false });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  search() { this.setData({ page: 1 }); this.load(); },
  turnPage(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.lastPage && !this.data.busy) { this.setData({ page }); this.load(); } },
  openOperation(event) { return this.showOperation(Number(event.currentTarget.dataset.id)); },
  showOperation(id) {
    const sequence = this.detailSequence = (this.detailSequence || 0) + 1;
    this.setData({ detailOpen: true, operation: Number(this.data.operation.id) === Number(id) ? this.data.operation : {}, operationError: '', pending: Boolean(packing.pending(id)) });
    return packing.operation(id).then(response => { if (sequence === this.detailSequence) this.setData({ operation: this.decorate(response.data || response) }); })
      .catch(error => { if (sequence === this.detailSequence) this.setData({ operationError: view.errorText(error) }); });
  },
  closeDetail() { if (!this.data.busy) { this.detailSequence++; this.invalidateSelectors(); this.setData({ detailOpen: false, peopleOpen: false, materialsOpen: false, identityOpen: false, inspectionOpen: false }); } },
  runAction(event) { const action = event.currentTarget.dataset.action; if (action === 'complete') return this.complete(); return this.execute(action); },
  execute(action, payload) {
    if (this.data.busy || !this.data.operation.id) return Promise.resolve(false);
    this.setData({ busy: true, operationError: '', materialError: '' });
    const op = this.data.operation;
    return packing.action(op.id, Object.assign({ action, expected_version: op.business_version }, payload || {})).then(() => {
      this.setData({ pending: false, peopleOpen: false, materialsOpen: false, identityOpen: false, inspectionOpen: false });
      return Promise.all([this.load(), this.showOperation(op.id)]).then(() => true);
    }).catch(error => {
      const details = Object.values(error.errors || (error.details && error.details.errors) || {}).flat().join('；');
      this.setData({ operationError: details || view.errorText(error), materialError: details || view.errorText(error), pending: Boolean(error.pendingCommand) });
      return false;
    }).finally(() => this.setData({ busy: false }));
  },
  recover() { return this.execute('recover'); },
  complete() { if (this.data.busy) return; wx.showModal({ title: '完成包装工序', content: `确认本包裹 ${this.data.operation.qtyText} 的产品已完成本工序？`, confirmColor: '#008b4b', success: result => { if (result.confirm) this.execute('complete', { completed_base_qty: this.data.operation.planned_base_qty }); } }); },
  openPeople() {
    if (this.data.busy || this.data.pending) return;
    this.peopleSelected = {};
    (this.data.operation.participants || []).filter(row => row.role === 'COLLABORATOR' && row.is_active).forEach(row => { this.peopleSelected[row.employee_legacy_id] = { legacy_id: row.employee_legacy_id, nickname: row.name }; });
    this.setData({ peopleOpen: true, peopleRows: [], peopleKeyword: '', peoplePage: 1, peopleLast: 1, departmentIndex: 0, selectedPeople: Object.values(this.peopleSelected) }); return this.loadPeople(true);
  },
  loadPeople(include) {
    const sequence = this.peopleSequence = (this.peopleSequence || 0) + 1; const operationId = this.data.operation.id;
    return packing.people(this.data.operation.id, { keyword: this.data.peopleKeyword, page: this.data.peoplePage, per_page: 10,
      department_id: this.data.departments[this.data.departmentIndex].id || undefined, include_departments: include ? 1 : undefined }).then(response => {
      if (sequence !== this.peopleSequence || !this.data.peopleOpen || operationId !== this.data.operation.id) return;
      const paged = view.rows(response); this.setData({ peopleRows: paged.rows.map(row => Object.assign({}, row, { selected: Boolean(this.peopleSelected[row.legacy_id]) })), peoplePage: paged.page, peopleLast: paged.lastPage });
      if (response.departments) this.setData({ departments: [{ id: 0, name: '全部部门' }].concat(response.departments) });
    }).catch(error => { if (sequence === this.peopleSequence && this.data.peopleOpen) wx.showToast({ title: view.errorText(error), icon: 'none' }); });
  },
  peopleInput(event) { this.setData({ peopleKeyword: event.detail.value }); },
  searchPeople() { this.setData({ peoplePage: 1 }); this.loadPeople(); },
  chooseDepartment(event) { this.setData({ departmentIndex: Number(event.detail.value), peoplePage: 1 }); this.loadPeople(); },
  turnPeople(event) { const page = this.data.peoplePage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.peopleLast) { this.setData({ peoplePage: page }); this.loadPeople(); } },
  togglePerson(event) { const id = Number(event.currentTarget.dataset.id); const row = this.data.peopleRows.find(row => Number(row.legacy_id) === id) || this.peopleSelected[id]; if (this.peopleSelected[id]) delete this.peopleSelected[id]; else this.peopleSelected[id] = row; this.setData({ selectedPeople: Object.values(this.peopleSelected), peopleRows: this.data.peopleRows.map(person => Object.assign({}, person, { selected: Boolean(this.peopleSelected[person.legacy_id]) })) }); },
  savePeople() { return this.execute('collaborators', { employee_legacy_ids: Object.keys(this.peopleSelected).map(Number) }); },
  closePeople() { if (!this.data.busy) { this.peopleSequence = (this.peopleSequence || 0) + 1; this.setData({ peopleOpen: false }); } },
  openMaterials() { if (this.data.busy || this.data.pending) return; this.materialSelected = {}; this.setData({ materialsOpen: true, materialRows: [], materialDrafts: [], materialError: '', materialKeyword: '', materialPage: 1, materialLast: 1, categoryIndex: 0 }); return this.loadMaterials(true); },
  loadMaterials(include) {
    const sequence = this.materialSequence = (this.materialSequence || 0) + 1; const operationId = this.data.operation.id;
    return packing.materials(this.data.operation.id, { keyword: this.data.materialKeyword, page: this.data.materialPage, per_page: 10,
      category_id: this.data.categories[this.data.categoryIndex].id || undefined, include_categories: include ? 1 : undefined }).then(response => {
      if (sequence !== this.materialSequence || !this.data.materialsOpen || operationId !== this.data.operation.id) return;
      const paged = view.rows(response); this.setData({ materialRows: paged.rows.map(row => Object.assign({}, row, { selected: Boolean(this.materialSelected[row.id]) })), materialPage: paged.page, materialLast: paged.lastPage });
      if (response.categories) this.setData({ categories: [{ id: 0, category_name: '全部分类' }].concat(response.categories) });
    }).catch(error => { if (sequence === this.materialSequence && this.data.materialsOpen) this.setData({ materialError: view.errorText(error) }); });
  },
  materialInput(event) { this.setData({ materialKeyword: event.detail.value }); },
  searchMaterials() { this.setData({ materialPage: 1 }); this.loadMaterials(); },
  chooseCategory(event) { this.setData({ categoryIndex: Number(event.detail.value), materialPage: 1 }); this.loadMaterials(); },
  turnMaterials(event) { const page = this.data.materialPage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.materialLast) { this.setData({ materialPage: page }); this.loadMaterials(); } },
  chooseMaterial(event) { const id = Number(event.currentTarget.dataset.id); const row = this.data.materialRows.find(row => Number(row.id) === id); if (!row || this.materialSelected[id]) return; this.materialSelected[id] = Object.assign({}, clone(row), { base_qty: '', identities: [] }); this.updateDrafts(); },
  updateDrafts() { this.setData({ materialDrafts: Object.values(this.materialSelected), materialRows: this.data.materialRows.map(row => Object.assign({}, row, { selected: Boolean(this.materialSelected[row.id]) })) }); },
  quantityInput(event) { const id = Number(event.currentTarget.dataset.id); if (this.materialSelected[id]) { this.materialSelected[id].base_qty = event.detail.value; this.updateDrafts(); } },
  removeMaterial(event) { delete this.materialSelected[Number(event.currentTarget.dataset.id)]; this.updateDrafts(); },
  openIdentities(event) { const material = this.materialSelected[Number(event.currentTarget.dataset.id)]; if (!material || this.data.busy) return; this.identitySelected = Object.fromEntries(material.identities.map(row => [row.id, row])); this.setData({ identityOpen: true, identityRows: [], identityKeyword: '', identityMaterial: material, identityPage: 1, identityLast: 1, selectedIdentities: Object.values(this.identitySelected) }); return this.loadIdentities(); },
  loadIdentities() { const sequence = this.identitySequence = (this.identitySequence || 0) + 1; const sourceId = this.data.identityMaterial.id; const operationId = this.data.operation.id; return packing.identities(operationId, { inventory_balance_id: sourceId, keyword: this.data.identityKeyword, page: this.data.identityPage, per_page: 10 }).then(response => { if (sequence !== this.identitySequence || !this.data.identityOpen || sourceId !== this.data.identityMaterial.id || operationId !== this.data.operation.id) return; const paged = view.rows(response); this.setData({ identityRows: paged.rows.map(row => Object.assign({}, row, { selected: Boolean(this.identitySelected[row.id]) })), identityPage: paged.page, identityLast: paged.lastPage }); }).catch(error => { if (sequence === this.identitySequence && this.data.identityOpen) wx.showToast({ title: view.errorText(error), icon: 'none' }); }); },
  identityInput(event) { this.setData({ identityKeyword: event.detail.value }); },
  searchIdentities() { this.setData({ identityPage: 1 }); this.loadIdentities(); },
  turnIdentities(event) { const page = this.data.identityPage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.identityLast) { this.setData({ identityPage: page }); this.loadIdentities(); } },
  toggleIdentity(event) { const id = Number(event.currentTarget.dataset.id); const row = this.data.identityRows.find(row => Number(row.id) === id) || this.identitySelected[id]; if (this.identitySelected[id]) delete this.identitySelected[id]; else this.identitySelected[id] = row; this.setData({ selectedIdentities: Object.values(this.identitySelected), identityRows: this.data.identityRows.map(row => Object.assign({}, row, { selected: Boolean(this.identitySelected[row.id]) })) }); },
  confirmIdentities() { const material = this.materialSelected[this.data.identityMaterial.id]; material.identities = Object.values(this.identitySelected); material.base_qty = String(material.physical || material.serial_tracking_mode === 'required' ? material.identities.length : Math.max(Number(material.base_qty) || 0, material.identities.length)); this.updateDrafts(); this.setData({ identityOpen: false }); },
  closeIdentities() { if (!this.data.busy) { this.identitySequence = (this.identitySequence || 0) + 1; this.setData({ identityOpen: false }); } },
  saveMaterials() { const rows = Object.values(this.materialSelected); if (!rows.length || rows.some(row => !(Number(row.base_qty) > 0))) return this.setData({ materialError: '请填写每项实际耗用数量，板料须选择真实实物。' }); return this.execute('materials', { materials: rows.map(row => ({ inventory_balance_id: row.id, base_qty: row.base_qty, physical_material_ids: row.physical ? row.identities.map(identity => identity.id) : [], inventory_serial_ids: !row.physical && row.serial_tracking_mode !== 'none' ? row.identities.map(identity => identity.id) : [] })) }); },
  closeMaterials() { if (!this.data.busy) { this.materialSequence = (this.materialSequence || 0) + 1; this.identitySequence = (this.identitySequence || 0) + 1; this.setData({ materialsOpen: false, identityOpen: false }); } },
  openInspection() { this.setData({ inspectionOpen: true, inspectionResult: 'passed', inspectionReason: '' }); },
  inspectionChange(event) { this.setData({ inspectionResult: event.detail.value }); },
  inspectionInput(event) { this.setData({ inspectionReason: event.detail.value }); },
  saveInspection() { return this.execute('inspect', { result: this.data.inspectionResult, reason: this.data.inspectionReason }); },
  closeInspection() { if (!this.data.busy) this.setData({ inspectionOpen: false }); },
  back: view.back,
  stop() {},
});
