const packing = require('../../../services/packing');
const view = require('../../../utils/warehouse-page');
const personalShares = require('../../../utils/personal-shares');
const materialUsage = require('../../../utils/packing-material-usage');
const statusLabels = { READY: '待加工', WAITING: '等待前序', IN_PROGRESS: '加工中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' };
const clone = value => JSON.parse(JSON.stringify(value));
Page({
  data: { shipmentId: 0, page: 1, lastPage: 1, total: 0, rows: [], loading: true, busy: false, error: '', keyword: '',
    detailOpen: false, operation: {}, pending: false, operationError: '', peopleOpen: false, peopleRows: [], selectedPeople: [], peopleKeyword: '',
    peoplePage: 1, peopleLast: 1, departments: [{ id: 0, name: '全部部门' }], departmentIndex: 0, shareRows: [], peopleError: '',
    materialsOpen: false, materialRows: [], materialDrafts: [], materialKeyword: '', materialPage: 1, materialLast: 1, materialError: '',
    categories: [{ id: 0, category_name: '全部分类' }], categoryIndex: 0,
    identityOpen: false, identityRows: [], selectedIdentities: [], identityMaterial: {}, identityPage: 1, identityLast: 1, identityKeyword: '',
    operationSerialIds: [], inspectionOpen: false, inspectionResult: 'passed', inspectionReason: '', inspectionError: '', inspectionNeedsReturn: false,
    repairLocatorOpen: false, repairLocator: {}, reworkOpen: false, reworkRows: [], reworkPage: 1, reworkLast: 1, reworkKeyword: '', reworkError: '', returnOperation: null },
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
      serialResults: (row.serial_results || []).map(serial => Object.assign({}, serial, {
        selected: this.data.operationSerialIds.includes(serial.inventory_serial_id),
        canSelect: Boolean(serial.allowed_actions && (serial.allowed_actions.complete || serial.allowed_actions.inspect)),
        statusLabel: ({ waiting: '等待前序', ready: '可开始', completed: '已完成', wait_quality: '待检验', failed: '不合格' })[serial.status] || serial.status,
      })),
      materials: (row.materials || []).map(material => {
        const usage = material.usage_snapshot || {}; const labels = { length_mm: '长', width_mm: '宽', thickness_mm: '厚' };
        return Object.assign({}, material, { usageText: Object.keys(labels).filter(axis => usage.dimensions && usage.dimensions[axis] !== undefined && usage.dimensions[axis] !== null)
          .map(axis => labels[axis] + ' ' + usage.dimensions[axis] + ' mm').join(' / ') || (usage.area_usage ? '木板 ' + usage.area_usage.board_length_mm + '×' + usage.area_usage.board_width_mm + ' mm：用料 ' + usage.area_usage.used_area_m2 + ' m²，' + (usage.area_usage.remnant === 'loss' ? '损耗 ' + usage.area_usage.loss_area_m2 + ' m²' : '余板回库') : ''), usageRemark: usage.remark || '' });
      }),
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
  openOperation(event) { this.setData({ operationSerialIds: [] }); return this.showOperation(Number(event.currentTarget.dataset.id)); },
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
      this.setData({ pending: false, peopleOpen: false, materialsOpen: false, identityOpen: false, inspectionOpen: false, operationSerialIds: [] });
      return Promise.all([this.load(), this.showOperation(op.id)]).then(() => true);
    }).catch(error => {
      const details = Object.values(error.errors || (error.details && error.details.errors) || {}).flat().join('；');
      this.setData({ operationError: details || view.errorText(error), materialError: details || view.errorText(error), peopleError: error.pendingCommand ? '原操作结果待确认，请重试原操作。' : details || view.errorText(error), pending: Boolean(error.pendingCommand) });
      return false;
    }).finally(() => this.setData({ busy: false }));
  },
  recover() { return this.execute('recover'); },
  complete() { if (this.data.busy) return; const ids = this.actionSerials('complete'); if (ids === false) return; wx.showModal({ title: '完成发货作业', content: '确认本次选择的 ' + (ids ? ids.length + ' 台' : this.data.operation.qtyText) + ' 已完成本工序？', confirmColor: '#008b4b', success: result => { if (result.confirm) this.execute('complete', ids ? { inventory_serial_ids: ids, completed_base_qty: ids.length } : { completed_base_qty: this.data.operation.planned_base_qty }); } }); },
  openPeople() {
    if (this.data.busy || this.data.pending) return;
    this.peopleSelected = {};
    (this.data.operation.participants || []).filter(row => row.role === 'COLLABORATOR' && row.is_active).forEach(row => { this.peopleSelected[row.employee_legacy_id] = { legacy_id: row.employee_legacy_id, nickname: row.name }; });
    this.setData({ peopleOpen: true, peopleRows: [], peopleKeyword: '', peoplePage: 1, peopleLast: 1, departmentIndex: 0, selectedPeople: Object.values(this.peopleSelected), shareRows: [], peopleError: '' }); this.refreshPeopleShares(); return this.loadPeople(true);
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
  togglePerson(event) { if (this.data.busy || this.data.pending) return; const id = Number(event.currentTarget.dataset.id); const row = this.data.peopleRows.find(row => Number(row.legacy_id) === id) || this.peopleSelected[id]; if (!row || id === Number(this.data.operation.owner_legacy_id)) return; if (this.peopleSelected[id]) delete this.peopleSelected[id]; else this.peopleSelected[id] = row; this.setData({ selectedPeople: Object.values(this.peopleSelected), peopleRows: this.data.peopleRows.map(person => Object.assign({}, person, { selected: Boolean(this.peopleSelected[person.legacy_id]) })) }); this.refreshPeopleShares(); },
  refreshPeopleShares() {
    const op = this.data.operation; const ownerId = Number(op.owner_legacy_id);
    const participants = (op.participants || []).filter(person => Number(person.employee_legacy_id) === ownerId || person.has_labor)
      .map(person => Object.assign({}, person, { employee_name: person.name }));
    if (!ownerId || !participants.some(person => Number(person.employee_legacy_id) === ownerId)) return this.setData({ shareRows: [], peopleError: '接单人资料不完整，请重新读取包装工序' });
    const previous = [{ scope_type: 'shipment_packing_operation', scope_id: op.id, rows: this.data.shareRows }];
    const groups = personalShares.groups([{ scope_type: 'shipment_packing_operation', scope_id: op.id, scope_version: op.business_version,
      owner_legacy_id: ownerId, scope_name: op.operation_name, can_plan: true, participants, share_plan: op.share_plan }], Object.values(this.peopleSelected), previous);
    this.setData({ shareRows: groups[0].rows, peopleError: '' });
  },
  peopleShareInput(event) { if (this.data.busy || this.data.pending) return; const id = Number(event.currentTarget.dataset.id); const rows = this.data.shareRows; const person = rows.find(row => Number(row.employee_legacy_id) === id); if (person) person.percent = event.detail.value; this.setData({ shareRows: rows, peopleError: '' }); },
  equalPeopleShares() { if (this.data.busy || this.data.pending) return; const equal = personalShares.defaultShares(this.data.shareRows); this.data.shareRows.forEach(person => { person.percent = personalShares.ratioToPercent(equal.find(share => share.employee_legacy_id === Number(person.employee_legacy_id)).share_ratio); }); this.setData({ shareRows: this.data.shareRows, peopleError: '' }); },
  savePeople() { if (this.data.pending) return this.execute('recover'); let shares; try { shares = personalShares.buildShares(this.data.shareRows); } catch (error) { return this.setData({ peopleError: error.message }); } return this.execute('collaborators', { employee_legacy_ids: Object.keys(this.peopleSelected).map(Number), shares }); },
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
  chooseMaterial(event) { const id = Number(event.currentTarget.dataset.id); const row = this.data.materialRows.find(row => Number(row.id) === id); if (!row || this.materialSelected[id]) return; const size = String(row.spec || '').match(/(\d+(?:\.\d+)?)\s*[*×xX]\s*(\d+(?:\.\d+)?)/); this.materialSelected[id] = Object.assign({}, clone(row), { base_qty: '', identities: [], usage_dimensions: { length_mm: '', width_mm: '', thickness_mm: '' }, usage_remark: '', area: { enabled: false, board_length_mm: size ? size[1] : '', board_width_mm: size ? size[2] : '', pieces_text: '', remnant: 'return', text: '' } }); this.updateDrafts(); },
  // 木板按面积扣库存：开启后由裁切块自动算出耗用数量。
  areaToggle(event) { const id = Number(event.currentTarget.dataset.id); const row = this.materialSelected[id]; if (this.data.busy || this.data.pending || !row || row.physical) return; row.area.enabled = !row.area.enabled; this.applyArea(row); },
  areaInput(event) { const id = Number(event.currentTarget.dataset.id); const field = event.currentTarget.dataset.field; const row = this.materialSelected[id]; if (this.data.busy || this.data.pending || !row || !['board_length_mm', 'board_width_mm', 'pieces_text'].includes(field)) return; row.area[field] = event.detail.value; this.applyArea(row); },
  areaRemnant(event) { const id = Number(event.currentTarget.dataset.id); const row = this.materialSelected[id]; if (this.data.busy || this.data.pending || !row) return; row.area.remnant = event.currentTarget.dataset.value === 'loss' ? 'loss' : 'return'; this.applyArea(row); },
  applyArea(row) { if (row.area.enabled) { row.area.text = materialUsage.areaText(row); try { row.base_qty = String(materialUsage.areaResult(row).boards); } catch (error) { /* 尺寸未填完整时保留原数量 */ } } this.updateDrafts(); },
  updateDrafts() { this.setData({ materialDrafts: Object.values(this.materialSelected), materialRows: this.data.materialRows.map(row => Object.assign({}, row, { selected: Boolean(this.materialSelected[row.id]) })) }); },
  quantityInput(event) { if (this.data.busy || this.data.pending) return; const id = Number(event.currentTarget.dataset.id); if (this.materialSelected[id]) { this.materialSelected[id].base_qty = event.detail.value; this.updateDrafts(); } },
  usageDimensionInput(event) { const id = Number(event.currentTarget.dataset.id); const axis = event.currentTarget.dataset.axis; if (this.data.busy || this.data.pending || !this.materialSelected[id] || !['length_mm', 'width_mm', 'thickness_mm'].includes(axis)) return; this.materialSelected[id].usage_dimensions[axis] = event.detail.value; this.updateDrafts(); },
  usageRemarkInput(event) { const id = Number(event.currentTarget.dataset.id); if (!this.data.busy && !this.data.pending && this.materialSelected[id]) { this.materialSelected[id].usage_remark = event.detail.value; this.updateDrafts(); } },
  removeMaterial(event) { delete this.materialSelected[Number(event.currentTarget.dataset.id)]; this.updateDrafts(); },
  openIdentities(event) { const material = this.materialSelected[Number(event.currentTarget.dataset.id)]; if (!material || this.data.busy) return; this.identitySelected = Object.fromEntries(material.identities.map(row => [row.id, row])); this.setData({ identityOpen: true, identityRows: [], identityKeyword: '', identityMaterial: material, identityPage: 1, identityLast: 1, selectedIdentities: Object.values(this.identitySelected) }); return this.loadIdentities(); },
  loadIdentities() { const sequence = this.identitySequence = (this.identitySequence || 0) + 1; const sourceId = this.data.identityMaterial.id; const operationId = this.data.operation.id; return packing.identities(operationId, { inventory_balance_id: sourceId, keyword: this.data.identityKeyword, page: this.data.identityPage, per_page: 10 }).then(response => { if (sequence !== this.identitySequence || !this.data.identityOpen || sourceId !== this.data.identityMaterial.id || operationId !== this.data.operation.id) return; const paged = view.rows(response); this.setData({ identityRows: paged.rows.map(row => Object.assign({}, row, { selected: Boolean(this.identitySelected[row.id]) })), identityPage: paged.page, identityLast: paged.lastPage }); }).catch(error => { if (sequence === this.identitySequence && this.data.identityOpen) wx.showToast({ title: view.errorText(error), icon: 'none' }); }); },
  identityInput(event) { this.setData({ identityKeyword: event.detail.value }); },
  searchIdentities() { this.setData({ identityPage: 1 }); this.loadIdentities(); },
  turnIdentities(event) { const page = this.data.identityPage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.identityLast) { this.setData({ identityPage: page }); this.loadIdentities(); } },
  toggleIdentity(event) { const id = Number(event.currentTarget.dataset.id); const row = this.data.identityRows.find(row => Number(row.id) === id) || this.identitySelected[id]; if (this.identitySelected[id]) delete this.identitySelected[id]; else this.identitySelected[id] = row; this.setData({ selectedIdentities: Object.values(this.identitySelected), identityRows: this.data.identityRows.map(row => Object.assign({}, row, { selected: Boolean(this.identitySelected[row.id]) })) }); },
  confirmIdentities() { const material = this.materialSelected[this.data.identityMaterial.id]; material.identities = Object.values(this.identitySelected); material.base_qty = String(material.physical || material.serial_tracking_mode === 'required' ? material.identities.length : Math.max(Number(material.base_qty) || 0, material.identities.length)); this.updateDrafts(); this.setData({ identityOpen: false }); },
  closeIdentities() { if (!this.data.busy) { this.identitySequence = (this.identitySequence || 0) + 1; this.setData({ identityOpen: false }); } },
  saveMaterials() { let materials; try { materials = materialUsage.buildMaterials(Object.values(this.materialSelected)); } catch (error) { return this.setData({ materialError: error.message }); } return this.execute('materials', { materials }); },
  closeMaterials() { if (!this.data.busy) { this.materialSequence = (this.materialSequence || 0) + 1; this.identitySequence = (this.identitySequence || 0) + 1; this.setData({ materialsOpen: false, identityOpen: false }); } },
  chooseOperationSerials(event) { const ids = event.detail.value.map(Number); this.setData({ operationSerialIds: ids, 'operation.serialResults': (this.data.operation.serialResults || []).map(row => Object.assign({}, row, { selected: ids.includes(row.inventory_serial_id) })) }); },
  actionSerials(action) {
    const rows = this.data.operation.serial_results || [];
    if (!rows.length) return null;
    const ids = this.data.operationSerialIds;
    if (!ids.length || ids.some(id => !rows.some(row => row.inventory_serial_id === id && row.allowed_actions && row.allowed_actions[action]))) { this.setData({ operationError: '请选择满足本次操作条件的设备' }); return false; }
    return ids.slice();
  },
  needsReturnOperation() { return (this.data.operation.serial_results || []).some(row => this.data.operationSerialIds.includes(row.inventory_serial_id) && row.requires_return_operation); },
  openInspection() { if (this.actionSerials('inspect') === false) return; this.setData({ inspectionOpen: true, inspectionResult: 'passed', inspectionReason: '', inspectionError: '', repairLocator: {}, returnOperation: null, inspectionNeedsReturn: this.needsReturnOperation() }); },
  inspectionChange(event) { this.setData({ inspectionResult: event.detail.value }); },
  inspectionInput(event) { this.setData({ inspectionReason: event.detail.value }); },
  openRepairLocator() { if (!this.data.busy) this.setData({ repairLocatorOpen: true }); },
  chooseRepairLocator(event) { this.setData({ repairLocator: event.detail, repairLocatorOpen: false }); },
  closeRepairLocator() { this.setData({ repairLocatorOpen: false }); },
  openReworkOperations() { this.setData({ reworkOpen: true, reworkPage: 1, reworkKeyword: '', reworkRows: [], reworkError: '' }); this.loadReworkOperations(); },
  loadReworkOperations() {
    const op = this.data.operation; const serial = (op.serial_results || []).find(row => this.data.operationSerialIds.includes(row.inventory_serial_id) && row.requires_return_operation);
    if (!serial) return;
    const sequence = this.reworkSequence = (this.reworkSequence || 0) + 1;
    return packing.reworkOperations(this.data.shipmentId || op.shipment_id, serial.shipment_line_id, { page: this.data.reworkPage, per_page: 10, keyword: this.data.reworkKeyword }).then(response => {
      if (sequence !== this.reworkSequence || !this.data.reworkOpen) return;
      const result = view.rows(response); this.setData({ reworkRows: result.rows, reworkPage: result.page, reworkLast: result.lastPage });
    }).catch(error => { if (sequence === this.reworkSequence) this.setData({ reworkError: view.errorText(error) }); });
  },
  reworkInput(event) { this.setData({ reworkKeyword: event.detail.value }); },
  searchReworkOperations() { this.setData({ reworkPage: 1 }); this.loadReworkOperations(); },
  turnReworkOperations(event) { const page = this.data.reworkPage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.reworkLast) { this.setData({ reworkPage: page }); this.loadReworkOperations(); } },
  chooseReworkOperation(event) { const row = this.data.reworkRows.find(row => Number(row.id) === Number(event.currentTarget.dataset.id)); if (row) this.setData({ returnOperation: row, reworkOpen: false }); },
  closeReworkOperations() { this.reworkSequence = (this.reworkSequence || 0) + 1; this.setData({ reworkOpen: false }); },
  saveInspection() {
    const ids = this.actionSerials('inspect'); if (ids === false) return;
    const payload = { result: this.data.inspectionResult, reason: this.data.inspectionReason };
    if (ids) payload.inventory_serial_ids = ids;
    if (ids && payload.result === 'failed') {
      const needsReturn = this.needsReturnOperation();
      if (!payload.reason || !this.data.repairLocator.location_id || needsReturn && !this.data.returnOperation) return this.setData({ inspectionError: needsReturn ? '请填写不合格原因，选择返修库位和返工工序' : '请填写不合格原因并选择返修库位' });
      payload.repair_location_id = this.data.repairLocator.location_id; if (needsReturn) payload.return_operation_id = this.data.returnOperation.id;
    }
    return this.execute('inspect', payload).then(result => { if (!result) this.setData({ inspectionError: this.data.operationError }); });
  },
  closeInspection() { if (!this.data.busy) this.setData({ inspectionOpen: false }); },
  back: view.back,
  stop() {},
});
