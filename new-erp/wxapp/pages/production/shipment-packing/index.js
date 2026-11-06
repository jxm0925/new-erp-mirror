const packing = require('../../../services/packing');
const view = require('../../../utils/warehouse-page');
const personalShares = require('../../../utils/personal-shares');
const materialUsage = require('../../../utils/packing-material-usage');
const statusLabels = { READY: '待作业', WAITING: '等待前序', IN_PROGRESS: '作业中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' };
const statusTags = { READY: 'blue', WAITING: 'gray', IN_PROGRESS: 'amber', WAIT_QUALITY: 'purple', COMPLETED: 'green', CANCELLED: 'gray' };
const outboundLabels = { pending: '待库管审核', approved: '审核通过', rejected: '已驳回', posted: '已出库', withdrawn: '已撤回' };
const outboundTags = { pending: 'amber', approved: 'green', rejected: 'red', posted: 'green', withdrawn: 'gray' };
const TABS = [{ key: 'mine', label: '我的待做' }, { key: 'available', label: '可接作业' }, { key: 'all', label: '全部作业' }];
const clone = value => JSON.parse(JSON.stringify(value));
const pad = n => (n < 10 ? '0' : '') + n;
function clock(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`;
}
function minutesText(m) {
  const v = Number(m || 0);
  return v >= 60 ? `${Math.floor(v / 60)}时${Math.round(v % 60)}分` : `${Math.round(v)}分`;
}
function hm(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return isNaN(d.getTime()) ? '' : `${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function buildSpecText(obj) {
  if (!obj) return '';
  if (obj.specification) return obj.specification;
  const parts = [obj.item_spec || obj.component_item_spec || obj.spec, obj.material_grade].filter(Boolean);
  if (parts.length) return parts.join(' · ');
  if (obj.sku_name && obj.sku_name !== obj.product_name && obj.sku_name !== obj.item_name) return obj.sku_name;
  return obj.sku_name || '';
}
function describeOutbound(application) {
  if (!application) return null;
  const { status, delivery_mode } = application;
  const deliveryLabel = ({ dispatch: '物流发运', pickup: '客户自提' })[delivery_mode] || '';
  const statusLabel = status === 'posted' && deliveryLabel
    ? (delivery_mode === 'pickup' ? '已提货' : '已发运')
    : (outboundLabels[status] || '出库申请');
  return Object.assign({}, application, {
    statusLabel, deliveryLabel, statusTag: outboundTags[status] || 'gray',
    packageText: (application.package_nos || []).join('、'),
  });
}
Page({
  data: { shipmentId: 0, page: 1, lastPage: 1, total: 0, rows: [], loading: true, busy: false, error: '', keyword: '',
    tabs: TABS, scope: 'mine', counts: { mine: 0, available: 0 }, userName: '', clockText: '00:00:00',
    repairReturnOpen: false, repairReturnSerial: {}, repairReturnReason: '', repairReturnError: '', finishedLocatorOpen: false, finishedLocator: {},
    detailOpen: false, showMaterials: false, operation: {}, pending: false, operationError: '', peopleOpen: false, peopleRows: [], selectedPeople: [], peopleKeyword: '',
    peoplePage: 1, peopleLast: 1, departments: [{ id: 0, name: '全部部门' }], departmentIndex: 0, shareRows: [], peopleError: '',
    materialsOpen: false, materialRows: [], materialDrafts: [], materialKeyword: '', materialPage: 1, materialLast: 1, materialError: '',
    dispatchOpen: false, dispatchMode: 'dispatch', carrierName: '', trackingNo: '', dispatchError: '',
    categories: [{ id: 0, category_name: '全部分类' }], categoryIndex: 0,
    identityOpen: false, identityRows: [], selectedIdentities: [], identityMaterial: {}, identityPage: 1, identityLast: 1, identityKeyword: '',
    operationSerialIds: [], inspectionOpen: false, inspectionResult: 'passed', inspectionReason: '', inspectionMedia: [], mediaBusy: false, inspectionError: '', inspectionNeedsReturn: false,
    repairLocatorOpen: false, repairLocator: {}, reworkOpen: false, reworkRows: [], reworkPage: 1, reworkLast: 1, reworkKeyword: '', reworkError: '', returnOperation: null },
  onLoad(options = {}) { this.peopleSelected = {}; this.materialSelected = {}; this.identitySelected = {}; const user = wx.getStorageSync('erp_user') || {};
    this.fromOutside = Boolean(options.operation_id);
    this.setData({ shipmentId: Number(options.shipment_id || 0), userName: user.nickname || user.display_name || user.username || '',
      scope: TABS.some(t => t.key === options.scope) ? options.scope : (options.shipment_id ? 'all' : 'mine') }); this.load(); if (options.operation_id) { this.showOperation(Number(options.operation_id)); } },
  onHide() { this.stopClock(); },
  onShow() { if (this.data.detailOpen && this.data.operation.id) this.startClock(); },
  onUnload() { this.stopClock(); this.sequence = (this.sequence || 0) + 1; this.detailSequence = (this.detailSequence || 0) + 1; this.invalidateSelectors(); },
  invalidateSelectors() { ['peopleSequence', 'materialSequence', 'identitySequence'].forEach(key => { this[key] = (this[key] || 0) + 1; }); },
  onPullDownRefresh() {
    const refresh = this.data.detailOpen ? this.refreshDetail() : this.load();
    return Promise.resolve(refresh).finally(() => wx.stopPullDownRefresh());
  },
  decorate(row) {
    const can = view.permissions();
    const actions = Object.assign({}, row.allowed_actions || {});
    Object.keys(actions).forEach(action => { actions[action] = actions[action] && can(action === 'inspect' ? 'sales_order.shipment.packing.quality' : 'sales_order.shipment.packing.execute'); });
    const owner = (row.participants || []).find(person => person.role === 'OWNER');
    const info = row.order_info || null;
    const contents = row.contents || [];
    const my = row.my || { closed_minutes: 0, timing: false };
    const repairRows = (row.serial_results || []).filter(serial => serial.repair && serial.repair.state !== 'returned');
    const specText = contents.map(c => buildSpecText(c)).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join('、');
    const outbound = row.outbound || {};
    const outboundMaterials = describeOutbound(outbound.materials);
    actions.materials = Boolean(actions.materials && !['pending', 'approved'].includes(outboundMaterials?.status));
    return Object.assign({}, row, { statusLabel: statusLabels[row.status] || row.status, statusTag: statusTags[row.status] || 'gray', ownerName: owner ? owner.name : '待接单',
      my, productText: contents.map(c => c.product_name || c.item_name).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join('、'),
      specText,
      outbound: {
        materials: outboundMaterials, dispatch: describeOutbound(outbound.dispatch),
        can_request_dispatch: Boolean(outbound.can_request_dispatch),
        dispatch_block_reason: outbound.dispatch_block_reason || '',
        dispatchPackageText: (outbound.dispatch_package_nos || []).join('、') || row.package_no || '',
      },
      myMinutesText: minutesText(my.closed_minutes) + (my.timing ? '+' : ''), totalMinutesText: minutesText(row.labor && row.labor.closed_minutes),
      changedText: ((info && info.changed) || []).map(c => c.label).join('、'),
      repairRows, repairCount: repairRows.length,
      participants: (row.participants || []).map(person => Object.assign({}, person, { minutesText: minutesText(person.actual_labor_minutes) })),
      sessionRows: (((row.labor || {}).sessions || {}).data || []).map(session => Object.assign({}, session, {
        timeText: hm(session.started_at) + (session.ended_at ? ' ~ ' + hm(session.ended_at).slice(6) : ' 起'),
        minutesText: session.ended_at ? minutesText(session.actual_labor_minutes) : '计时中' })),
      material_requirements: (row.material_requirements || []).map(req => Object.assign({}, req, {
        specText: buildSpecText(req),
        qtyText: view.quantity(req.required_base_qty),
      })),
      is_public_snapshot: row.is_public_snapshot === true || row.is_public_snapshot === 1 || row.is_public_snapshot === '1',
      qtyText: view.quantity(row.planned_base_qty), allowed_actions: actions,
      serialResults: (row.serial_results || []).map(serial => Object.assign({}, serial, {
        selected: this.data.operationSerialIds.includes(serial.inventory_serial_id),
        canSelect: Boolean(serial.allowed_actions && (serial.allowed_actions.complete || serial.allowed_actions.inspect)),
        statusLabel: serial.line_status === 'repair' ? '返修中' : (({ waiting: '等待前序', ready: '待作业', completed: '已完成', wait_quality: '待检验', failed: '不合格' })[serial.status] || serial.status),
        tag: serial.line_status === 'repair' ? 'red' : (({ ready: 'blue', completed: 'green', wait_quality: 'purple', failed: 'red' })[serial.status] || 'gray'),
      })),
      materials: (row.materials || []).map(material => {
        const usage = material.usage_snapshot || {}; const labels = { length_mm: '长', width_mm: '宽', thickness_mm: '厚' };
        return Object.assign({}, material, {
          specText: buildSpecText(material),
          usageText: Object.keys(labels).filter(axis => usage.dimensions && usage.dimensions[axis] !== undefined && usage.dimensions[axis] !== null)
            .map(axis => labels[axis] + ' ' + usage.dimensions[axis] + ' mm').join(' / ') || (usage.area_usage ? '木板 ' + usage.area_usage.board_length_mm + '×' + usage.area_usage.board_width_mm + ' mm：用料 ' + usage.area_usage.used_area_m2 + ' m²，' + (usage.area_usage.remnant === 'loss' ? '损耗 ' + usage.area_usage.loss_area_m2 + ' m²' : '余板回库') : ''),
          usageRemark: usage.remark || '',
        });
      }),
      contents: (row.contents || []).map(content => Object.assign({}, content, {
        specText: buildSpecText(content),
        qtyText: view.quantity(content.base_qty),
        serialText: (content.serial_nos || []).join('、'),
      })) });
  },
  load() {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return packing.operations({ shipment_id: this.data.shipmentId || undefined, page: this.data.page, per_page: 10, keyword: this.data.keyword,
      scope: this.data.scope === 'all' ? undefined : this.data.scope, include_counts: 1 }).then(response => {
      if (sequence !== this.sequence) return;
      const paged = view.rows(response); this.setData({ rows: paged.rows.map(row => this.decorate(row)), page: paged.page, lastPage: paged.lastPage, total: paged.total, loading: false,
        counts: response.counts || this.data.counts });
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  clearKeyword() { this.setData({ keyword: '', page: 1 }); this.load(); },
  pickScope(event) { const scope = event.currentTarget.dataset.scope; if (scope === this.data.scope) return; this.setData({ scope, page: 1, rows: [] }); this.load(); },
  callPhone(event) { const phone = event.currentTarget.dataset.phone; if (phone) wx.makePhoneCall({ phoneNumber: String(phone) }); },
  startClock() {
    this.stopClock();
    const my = this.data.operation && this.data.operation.my;
    if (!my) return;
    const base = Number(my.closed_minutes || 0) * 60;
    const started = my.timing && my.started_at ? new Date(my.started_at).getTime() : 0;
    const tick = () => this.setData({ clockText: clock(base + (started ? (Date.now() - started) / 1000 : 0)) });
    tick();
    if (started) this.timer = setInterval(tick, 1000);
  },
  stopClock() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },
  toggleMaterialsSection() { this.setData({ showMaterials: !this.data.showMaterials }); },
  refreshDetail() { if (!this.data.busy && this.detailOperationId) return this.showOperation(this.detailOperationId); },
  toggleSerial(event) {
    const id = Number(event.currentTarget.dataset.id);
    const serial = (this.data.operation.serialResults || []).find(row => row.inventory_serial_id === id);
    if (!serial || !serial.canSelect || this.data.busy) return;
    const ids = this.data.operationSerialIds.includes(id) ? this.data.operationSerialIds.filter(x => x !== id) : this.data.operationSerialIds.concat([id]);
    this.setData({ operationSerialIds: ids, 'operation.serialResults': this.data.operation.serialResults.map(row => Object.assign({}, row, { selected: ids.includes(row.inventory_serial_id) })) });
  },
  openRepairReturn(event) {
    const lineId = Number(event.currentTarget.dataset.id);
    const serial = (this.data.operation.serial_results || []).find(row => row.shipment_line_id === lineId);
    if (!serial || this.data.busy) return;
    this.setData({ repairReturnOpen: true, repairReturnSerial: serial, repairReturnReason: '', repairReturnError: '', finishedLocator: {} });
  },
  closeRepairReturn() { if (!this.data.busy) this.setData({ repairReturnOpen: false }); },
  repairReturnInput(event) { this.setData({ repairReturnReason: event.detail.value }); },
  openFinishedLocator() { if (!this.data.busy) this.setData({ finishedLocatorOpen: true }); },
  chooseFinishedLocator(event) { this.setData({ finishedLocator: event.detail, finishedLocatorOpen: false }); },
  closeFinishedLocator() { this.setData({ finishedLocatorOpen: false }); },
  saveRepairReturn() {
    const op = this.data.operation; const serial = this.data.repairReturnSerial;
    if (this.data.busy || !serial.shipment_line_id) return;
    if (!this.data.finishedLocator.location_id) return this.setData({ repairReturnError: '请选择成品仓库和库位' });
    if (!String(this.data.repairReturnReason || '').trim()) return this.setData({ repairReturnError: '请填写返修合格说明' });
    this.setData({ busy: true, repairReturnError: '' });
    return packing.repairReturn(op.shipment_id, serial.shipment_line_id, { expected_version: op.shipment_version,
      finished_location_id: this.data.finishedLocator.location_id, reason: String(this.data.repairReturnReason).trim() }).then(response => {
      wx.showToast({ title: response.message || '已返回待发区，请重新试水', icon: 'none' });
      this.setData({ repairReturnOpen: false });
      return Promise.all([this.load(), this.showOperation(op.id)]);
    }).catch(error => this.setData({ repairReturnError: view.errorText(error) })).finally(() => this.setData({ busy: false }));
  },
  search() { this.setData({ page: 1 }); this.load(); },
  turnPage(event) { const page = this.data.page + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.lastPage && !this.data.busy) { this.setData({ page }); this.load(); } },
  openOperation(event) { this.setData({ operationSerialIds: [] }); return this.showOperation(Number(event.currentTarget.dataset.id)); },
  showOperation(id) {
    this.detailOperationId = id;
    const sequence = this.detailSequence = (this.detailSequence || 0) + 1;
    this.setData({ detailOpen: true, operation: Number(this.data.operation.id) === Number(id) ? this.data.operation : {}, operationError: '', pending: Boolean(packing.pending(id)) });
    return packing.operation(id).then(response => { if (sequence === this.detailSequence) { this.setData({ operation: this.decorate(response.data || response) }); this.startClock(); } })
      .catch(error => { if (sequence === this.detailSequence) this.setData({ operationError: view.errorText(error) }); });
  },
  closeDetail() {
    if (this.data.busy) return;
    this.stopClock();
    if (this.fromOutside && getCurrentPages().length > 1) { wx.navigateBack(); return; }
    this.fromOutside = false;
    this.detailOperationId = 0;
    this.detailSequence = (this.detailSequence || 0) + 1; this.invalidateSelectors();
    this.setData({ detailOpen: false, peopleOpen: false, materialsOpen: false, dispatchOpen: false, identityOpen: false, inspectionOpen: false, repairReturnOpen: false });
    this.load();
  },
  runAction(event) { const action = event.currentTarget.dataset.action; if (action === 'complete') return this.complete(); return this.execute(action); },
  execute(action, payload) {
    if (this.data.busy || !this.data.operation.id) return Promise.resolve(false);
    this.setData({ busy: true, operationError: '', materialError: '', dispatchError: '' });
    const op = this.data.operation;
    return packing.action(op.id, Object.assign({ action, expected_version: op.business_version }, payload || {})).then(() => {
      this.setData({ pending: false, peopleOpen: false, materialsOpen: false, dispatchOpen: false, identityOpen: false, inspectionOpen: false, operationSerialIds: [] });
      return Promise.all([this.load(), this.showOperation(op.id)]).then(() => true);
    }).catch(error => {
      const message = Object.values(error.errors || (error.details && error.details.errors) || {}).flat().join('；') || view.errorText(error);
      this.setData({ operationError: message, materialError: message, dispatchError: message, peopleError: error.pendingCommand ? '原操作结果待确认，请重试原操作。' : message, pending: Boolean(error.pendingCommand) });
      return false;
    }).finally(() => this.setData({ busy: false }));
  },
  recover() { return this.execute('recover'); },
  openDispatch() {
    const op = this.data.operation;
    if (this.data.busy || this.data.pending || !op.outbound?.can_request_dispatch) return;
    const previous = op.outbound.dispatch || {};
    this.setData({
      dispatchOpen: true, dispatchMode: previous.delivery_mode === 'pickup' ? 'pickup' : 'dispatch',
      carrierName: previous.carrier_name || op.order_info?.carrier_name || '', trackingNo: previous.tracking_no || '', dispatchError: '',
    });
  },
  closeDispatch() { if (!this.data.busy) this.setData({ dispatchOpen: false }); },
  chooseDispatchMode(event) { this.setData({ dispatchMode: event.detail.value }); },
  dispatchInput(event) {
    const field = event.currentTarget.dataset.field;
    if (field === 'carrierName' || field === 'trackingNo') this.setData({ [field]: event.detail.value });
  },
  saveDispatch() {
    if (this.data.busy) return;
    if (this.data.pending) return this.recover();
    const { dispatchMode, carrierName, trackingNo } = this.data;
    const payload = { delivery_mode: dispatchMode };
    if (dispatchMode === 'dispatch') Object.assign(payload, { carrier_name: carrierName.trim(), tracking_no: trackingNo.trim() });
    return this.execute('request_dispatch', payload);
  },
  changeOutbound(event) {
    const { kind, action } = event.currentTarget.dataset;
    const op = this.data.operation;
    const application = op.outbound?.[kind];
    const confirming = action === 'confirm_dispatch';
    if (this.data.busy || this.data.pending || !(confirming ? application?.can_confirm : application?.can_withdraw)) return;
    const title = confirming ? (application.delivery_mode === 'pickup' ? '确认提货' : '确认发运') : '撤回出库申请';
    view.modal(this, {
      title, confirmText: title,
      subtitle: confirming ? '请在货物实际交付后确认' : '',
      kvRows: [{ k: '包裹', v: application.packageText || op.package_no }],
      success: result => {
        if (result.confirm && op.id === this.data.operation.id && !this.data.pending) this.execute(action, { application_id: application.id });
      },
    });
  },
  complete() {
    if (this.data.busy) return;
    const ids = this.actionSerials('complete');
    if (ids === false) return;
    const op = this.data.operation || {};
    view.modal(this, {
      mediaEnabled: true,
      title: '确认完成发货作业',
      subtitle: '请核对本次完工设备数量与当前工序',
      kvRows: [
        { k: '当前工序', v: op.operation_name || '发货作业' },
        { k: '完成数量', v: ids ? `${ids.length} 台` : (op.qtyText || ''), red: true },
      ],
      confirmText: '确认完工',
      success: result => {
        if (result.confirm && op.id === this.data.operation.id) this.execute('complete', Object.assign({ media: result.media || [] }, ids ? { inventory_serial_ids: ids, completed_base_qty: ids.length } : { completed_base_qty: op.planned_base_qty }));
      },
    });
  },
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
      const paged = view.rows(response); this.setData({ peopleRows: paged.rows.map(row => Object.assign({}, row, { selected: Boolean(this.peopleSelected[row.legacy_id]), avatarText: String(row.nickname || row.username || '工')[0] })), peoplePage: paged.page, peopleLast: paged.lastPage });
      if (response.departments) this.setData({ departments: [{ id: 0, name: '全部部门' }].concat(response.departments) });
    }).catch(error => { if (sequence === this.peopleSequence && this.data.peopleOpen) wx.showToast({ title: view.errorText(error), icon: 'none' }); });
  },
  peopleInput(event) { this.setData({ peopleKeyword: event.detail.value }); },
  clearPeopleKeyword() { this.setData({ peopleKeyword: '', peoplePage: 1 }); this.loadPeople(); },
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
  equalPeopleShares() { if (this.data.busy || this.data.pending || !this.data.shareRows.length) return; const equal = personalShares.defaultShares(this.data.shareRows); this.data.shareRows.forEach(person => { person.percent = personalShares.ratioToPercent(equal.find(share => share.employee_legacy_id === Number(person.employee_legacy_id)).share_ratio); }); this.setData({ shareRows: this.data.shareRows, peopleError: '' }); },
  savePeople() { if (this.data.busy || (!this.data.pending && !this.data.shareRows.length)) return; if (this.data.pending) return this.execute('recover'); let shares; try { shares = personalShares.buildShares(this.data.shareRows); } catch (error) { return this.setData({ peopleError: error.message }); } return this.execute('collaborators', { employee_legacy_ids: Object.keys(this.peopleSelected).map(Number), shares }); },
  closePeople() { if (!this.data.busy) { this.peopleSequence = (this.peopleSequence || 0) + 1; this.setData({ peopleOpen: false }); } },
  openMaterials() { if (this.data.busy || this.data.pending || !this.data.operation.allowed_actions?.materials) return; this.materialSelected = {}; this.setData({ materialsOpen: true, materialRows: [], materialDrafts: [], materialError: '', materialKeyword: '', materialPage: 1, materialLast: 1, categoryIndex: 0 }); return this.loadMaterials(true); },
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
  chooseMaterial(event) { if (this.data.busy || this.data.pending) return; const id = Number(event.currentTarget.dataset.id); const row = this.data.materialRows.find(row => Number(row.id) === id); if (!row || this.materialSelected[id]) return; const size = String(row.spec || '').match(/(\d+(?:\.\d+)?)\s*[*×xX]\s*(\d+(?:\.\d+)?)/); this.materialSelected[id] = Object.assign({}, clone(row), { base_qty: '', identities: [], usage_dimensions: { length_mm: '', width_mm: '', thickness_mm: '' }, usage_remark: '', area: { enabled: false, board_length_mm: size ? size[1] : '', board_width_mm: size ? size[2] : '', pieces_text: '', remnant: 'return', text: '' } }); this.updateDrafts(); },
  areaToggle(event) { const id = Number(event.currentTarget.dataset.id); const row = this.materialSelected[id]; if (this.data.busy || this.data.pending || !row || row.physical) return; row.area.enabled = !row.area.enabled; this.applyArea(row); },
  areaInput(event) { const id = Number(event.currentTarget.dataset.id); const field = event.currentTarget.dataset.field; const row = this.materialSelected[id]; if (this.data.busy || this.data.pending || !row || !['board_length_mm', 'board_width_mm', 'pieces_text'].includes(field)) return; row.area[field] = event.detail.value; this.applyArea(row); },
  areaRemnant(event) { const id = Number(event.currentTarget.dataset.id); const row = this.materialSelected[id]; if (this.data.busy || this.data.pending || !row) return; row.area.remnant = event.currentTarget.dataset.value === 'loss' ? 'loss' : 'return'; this.applyArea(row); },
  applyArea(row) { if (row.area.enabled) { row.area.text = materialUsage.areaText(row); try { row.base_qty = String(materialUsage.areaResult(row).boards); } catch (error) { /* 尺寸未填完整时保留原数量 */ } } this.updateDrafts(); },
  updateDrafts() { this.setData({ materialDrafts: Object.values(this.materialSelected), materialRows: this.data.materialRows.map(row => Object.assign({}, row, { selected: Boolean(this.materialSelected[row.id]) })) }); },
  quantityInput(event) { if (this.data.busy || this.data.pending) return; const id = Number(event.currentTarget.dataset.id); if (this.materialSelected[id]) { this.materialSelected[id].base_qty = event.detail.value; this.updateDrafts(); } },
  usageDimensionInput(event) { const id = Number(event.currentTarget.dataset.id); const axis = event.currentTarget.dataset.axis; if (this.data.busy || this.data.pending || !this.materialSelected[id] || !['length_mm', 'width_mm', 'thickness_mm'].includes(axis)) return; this.materialSelected[id].usage_dimensions[axis] = event.detail.value; this.updateDrafts(); },
  usageRemarkInput(event) { const id = Number(event.currentTarget.dataset.id); if (!this.data.busy && !this.data.pending && this.materialSelected[id]) { this.materialSelected[id].usage_remark = event.detail.value; this.updateDrafts(); } },
  removeMaterial(event) { if (this.data.busy || this.data.pending) return; delete this.materialSelected[Number(event.currentTarget.dataset.id)]; this.updateDrafts(); },
  openIdentities(event) { const material = this.materialSelected[Number(event.currentTarget.dataset.id)]; if (!material || this.data.busy || this.data.pending) return; this.identitySelected = Object.fromEntries(material.identities.map(row => [row.id, row])); this.setData({ identityOpen: true, identityRows: [], identityKeyword: '', identityMaterial: material, identityPage: 1, identityLast: 1, selectedIdentities: Object.values(this.identitySelected) }); return this.loadIdentities(); },
  loadIdentities() { const sequence = this.identitySequence = (this.identitySequence || 0) + 1; const sourceId = this.data.identityMaterial.id; const operationId = this.data.operation.id; return packing.identities(operationId, { inventory_balance_id: sourceId, keyword: this.data.identityKeyword, page: this.data.identityPage, per_page: 10 }).then(response => { if (sequence !== this.identitySequence || !this.data.identityOpen || sourceId !== this.data.identityMaterial.id || operationId !== this.data.operation.id) return; const paged = view.rows(response); this.setData({ identityRows: paged.rows.map(row => Object.assign({}, row, { selected: Boolean(this.identitySelected[row.id]) })), identityPage: paged.page, identityLast: paged.lastPage }); }).catch(error => { if (sequence === this.identitySequence && this.data.identityOpen) wx.showToast({ title: view.errorText(error), icon: 'none' }); }); },
  identityInput(event) { this.setData({ identityKeyword: event.detail.value }); },
  searchIdentities() { this.setData({ identityPage: 1 }); this.loadIdentities(); },
  turnIdentities(event) { const page = this.data.identityPage + Number(event.currentTarget.dataset.delta); if (page > 0 && page <= this.data.identityLast) { this.setData({ identityPage: page }); this.loadIdentities(); } },
  toggleIdentity(event) { const id = Number(event.currentTarget.dataset.id); const row = this.data.identityRows.find(row => Number(row.id) === id) || this.identitySelected[id]; if (this.identitySelected[id]) delete this.identitySelected[id]; else this.identitySelected[id] = row; this.setData({ selectedIdentities: Object.values(this.identitySelected), identityRows: this.data.identityRows.map(row => Object.assign({}, row, { selected: Boolean(this.identitySelected[row.id]) })) }); },
  confirmIdentities() { const material = this.materialSelected[this.data.identityMaterial.id]; material.identities = Object.values(this.identitySelected); material.base_qty = String(material.physical || material.serial_tracking_mode === 'required' ? material.identities.length : Math.max(Number(material.base_qty) || 0, material.identities.length)); this.updateDrafts(); this.setData({ identityOpen: false }); },
  closeIdentities() { if (!this.data.busy) { this.identitySequence = (this.identitySequence || 0) + 1; this.setData({ identityOpen: false }); } },
  saveMaterials() {
    if (this.data.busy) return;
    if (this.data.pending) return this.recover();
    let materials;
    try { materials = materialUsage.buildMaterials(Object.values(this.materialSelected)); } catch (error) { return this.setData({ materialError: error.message }); }
    return this.execute('materials', { materials });
  },
  closeMaterials() { if (!this.data.busy) { this.materialSequence = (this.materialSequence || 0) + 1; this.identitySequence = (this.identitySequence || 0) + 1; this.setData({ materialsOpen: false, identityOpen: false }); } },
  chooseOperationSerials(event) { const ids = event.detail.value.map(Number); this.setData({ operationSerialIds: ids, 'operation.serialResults': (this.data.operation.serialResults || []).map(row => Object.assign({}, row, { selected: ids.includes(row.inventory_serial_id) })) }); },
  actionSerials(action) {
    const rows = this.data.operation.serial_results || [];
    if (!rows.length) return null;
    const ids = this.data.operationSerialIds;
    if (!ids.length || ids.some(id => !rows.some(row => row.inventory_serial_id === id && row.allowed_actions && row.allowed_actions[action]))) { this.setData({ operationError: '请选择满足本次操作条件的设备序列号' }); return false; }
    return ids.slice();
  },
  needsReturnOperation() { return (this.data.operation.serial_results || []).some(row => this.data.operationSerialIds.includes(row.inventory_serial_id) && row.requires_return_operation); },
  openInspection(event) { if (this.actionSerials('inspect') === false) return; const result = event && event.currentTarget && event.currentTarget.dataset.result === 'failed' ? 'failed' : 'passed'; this.setData({ inspectionOpen: true, inspectionResult: result, inspectionReason: '', inspectionMedia: [], mediaBusy: false, inspectionError: '', repairLocator: {}, returnOperation: null, inspectionNeedsReturn: this.needsReturnOperation() }); },
  inspectionMediaChanged(event) { this.setData({ inspectionMedia: event.detail.value }); },
  mediaBusy(event) { this.setData({ mediaBusy: event.detail.busy }); },
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
    if (this.data.mediaBusy) return;
    const ids = this.actionSerials('inspect'); if (ids === false) return;
    const payload = { result: this.data.inspectionResult, reason: this.data.inspectionReason, media: this.data.inspectionMedia };
    if (ids) payload.inventory_serial_ids = ids;
    if (ids && payload.result === 'failed') {
      const needsReturn = this.needsReturnOperation();
      if (!payload.reason || !this.data.repairLocator.location_id || needsReturn && !this.data.returnOperation) return this.setData({ inspectionError: needsReturn ? '请填写不合格原因，并选择返修库位与返工工序' : '请填写不合格原因并选择返修库位' });
      payload.repair_location_id = this.data.repairLocator.location_id; if (needsReturn) payload.return_operation_id = this.data.returnOperation.id;
    }
    return this.execute('inspect', payload).then(result => { if (!result) this.setData({ inspectionError: this.data.operationError }); });
  },
  closeInspection() { if (!this.data.busy && !this.data.mediaBusy) this.setData({ inspectionOpen: false }); },
  back: view.back,
  stop() {},
});
