import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
const compiler = createRequire(import.meta.url)('vue-template-compiler')
const paths = {
  work: 'production/WorkOrderDetail', assembly: 'production/WorkOrderAssemblyPanel',
  sales: 'sales/SalesOrderDetail', confirmation: 'sales/SalesProductionConfirmation',
  public: 'inventory/PublicMaterialPreparationPanel', procurement: 'inventory/MaterialProcurementDialog'
}
const sources = Object.fromEntries(await Promise.all(Object.entries(paths).map(async ([key, path]) => [key, await readFile(new URL('../src/views/erp/' + path + '.vue', import.meta.url), 'utf8')])))
function mount(name, overrides = {}) {
  const bindings = { cachedPageRoute: {}, StockSelector: {}, OptionPicker: {}, WorkOrderCompletionPanel: {}, WorkOrderTechnicalPanel: {}, ProductionInventoryContinuationPanel: {}, ProductionAssignmentPanel: {}, WorkOrderOutputPlanPanel: {}, WorkOrderAssemblyPanel: {}, ProductionJobBundlePanel: {}, SalesOrderAttachmentPreviewDialog: {}, SalesOrderFinancePanel: {}, statusTag: () => '', statusText: value => value, pendingMaterialWrite: () => null, ...overrides }
  const script = sources[name].match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
  const options = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const vm = { ...options.data(), $can: () => true, $set: (o, k, v) => { o[k] = v }, $delete: (o, k) => { delete o[k] }, $emit() {}, $message: { success() {}, error() {}, warning() {} }, $router: { push() {} }, pageRoute: { params: { id: 7 }, query: {} }, $refs: {} }
  for (const [key, fn] of Object.entries(options.methods || {})) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(options.computed || {})) Object.defineProperty(vm, key, { get: () => fn.call(vm) })
  return vm
}

test('all six existing preparation flow templates compile against the installed Vue 2 compiler', () => {
  for (const [name, source] of Object.entries(sources)) assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [], name)
})

test('preparation shortage view requests its own stage and cannot select stock or create picking', async () => {
  let query; let writes = 0; let stockSelections = 0
  const vm = mount('public', { materialDemands: async params => { query = params; return { data: { data: [], total: 0 } } } })
  vm.view = 'preparation'; await vm.load(); assert.equal(query.demand_stage, 'preparation')
  vm.openDraft(); assert.equal(vm.draft.visible, false)
  vm.$refs.stock = { open() { stockSelections++ } }
  const demand = { id: 8, demand_stage: 'preparation', can_pick: false, can_procure: true }
  vm.stock(demand); assert.equal(stockSelections, 0)
  vm.draft.selected = { 8: { demand, sources: [{ id: 4, quantity: '2' }] } }
  vm.write = async () => { writes++ }; await vm.create(); assert.equal(writes, 0)
  assert.equal(vm.canProcure(demand), true); demand.can_procure = false; assert.equal(vm.canProcure(demand), false)
  vm.view = 'demands'; await vm.load(); assert.equal(query.demand_stage, 'execution')
})

test('preparation procurement sends its exact stable identity and versions without a target demand id', async () => {
  let sent
  const vm = mount('procurement', { materialWrite: async (key, payload) => { sent = payload; return { data: { data: { request_no: 'REQ-1' } } } }, createMaterialProcurement() {} })
  vm.open({ id: 88, preparation_material_requirement_id: 88, preparation_version: 3, work_order_version: 9, demand_stage: 'preparation', component_item_id: 21, item_name: '方管', unit_name: '根', can_procure: true, procureable_qty: '2.0000' })
  assert.equal(vm.lines[0].request_qty, '')
  vm.remark = '按有效工单缺料补料'; vm.lines[0].request_qty = '3'; assert.equal(vm.valid, false)
  vm.lines[0].request_qty = '1.5'; assert.equal(vm.valid, true); await vm.submit()
  assert.deepEqual(sent.items, [{ item_id: 21, request_qty: '1.5', preparation_material_requirement_id: 88, preparation_version: 3, work_order_version: 9 }])
  delete vm.sourceDemand.preparation_version; assert.equal(vm.valid, false)
  vm.open(); assert.equal(vm.sourceDemand, null)
})

test('work order before release uses paginated preparation quantities without recomputing shortages', async () => {
  let params
  const vm = mount('work', { materialDemands: async query => { params = query; return { data: { total: 30, data: [{ id: 90, required_qty: '12.0000', secured_qty: '3.0000', shortage_qty: '9.0000', item_name: '板材', unit_name: '张' }] } } } })
  vm.workOrder = { id: 7, status: 'WAIT_RELEASE' }; vm.materialPage = 2
  await vm.fetchMaterials(7)
  assert.deepEqual(params, { work_order_id: 7, demand_stage: 'preparation', page: 2, per_page: 20 })
  assert.deepEqual(vm.materials[0].quantity, { required_qty: '12.0000', issued_qty: '3.0000', remaining_qty: '9.0000' })
  assert.equal(vm.materialTotal, 30); assert.equal(vm.materials[0].line_no, 21)
})

test('late material page responses cannot overwrite a newer page of the same work order', async () => {
  const waits = []
  const vm = mount('work', { materialDemands: () => new Promise(resolve => waits.push(resolve)) })
  vm.workOrder = { id: 7, status: 'DRAFT' }
  const first = vm.fetchMaterials(); vm.materialPage = 2; const second = vm.fetchMaterials()
  waits[1]({ data: { data: [{ id: 2 }], total: 21 } }); await second
  waits[0]({ data: { data: [{ id: 1 }], total: 21 } }); await first
  assert.equal(vm.materials[0].id, 2)
})

test('sales displays material lifecycle and component execution without claiming the root is running', () => {
  const vm = mount('sales'); const opened = []; vm.$router.push = path => opened.push(path)
  assert.equal(vm.preparationText('prepared', false), '准备未齐')
  assert.equal(vm.preparationText('prepared', true), '部件已准备')
  assert.equal(vm.materialPreparationText('active'), '核料完成')
  assert.equal(vm.materialPreparationText('published'), '已发布')
  assert.equal(vm.materialPreparationText('schema_not_ready'), '准备结构未就绪')
  assert.equal(vm.materialPreparationText('not_prepared'), '待核料')
  assert.equal(vm.componentProgressText({ work_order_count: 2, states: [{ status_label: '生产中', count: 1 }, { status_label: '待发布', count: 1 }], completed_operation_count: 1, total_operation_count: 3, current_process_name: '焊接' }), '2 张部件工单（生产中 1、待发布 1）；工序完成 1/3；正在进行：焊接')
  vm.openTrackedWorkOrder({ work_order_id: 7, can_view: false }); assert.deepEqual(opened, [])
  vm.openTrackedWorkOrder({ work_order_id: 7, can_view: true }); assert.deepEqual(opened, ['/production/work-orders/7'])
})

test('sales tracking pagination ignores stale responses and retains the newest counts', async () => {
  const waits = []
  const vm = mount('sales', { getSalesOrder: () => new Promise(resolve => waits.push(resolve)) })
  vm.order = { id: 7 }; const first = vm.loadTracking(2); const second = vm.loadTracking(3)
  waits[1]({ data: { work_order_tracking: [{ work_order_id: 3 }], work_order_tracking_pagination: { current_page: 3, per_page: 10, total: 30 } } }); await second
  waits[0]({ data: { work_order_tracking: [{ work_order_id: 2 }], work_order_tracking_pagination: { current_page: 2, per_page: 10, total: 30 } } }); await first
  assert.equal(vm.order.work_order_tracking[0].work_order_id, 3); assert.equal(vm.tracking.current_page, 3)
})

test('release clears pre-release blockers and ignores an older preparation response', async () => {
  let resolvePreparation
  const vm = mount('work', {
    getAssemblyPlan: () => new Promise(resolve => { resolvePreparation = resolve }),
    getWorkOrder: async () => ({ data: { data: { id: 7, status: 'RELEASED', actions: {} } } })
  })
  vm.workOrder = { id: 7, status: 'WAIT_RELEASE' }
  vm.preparation = { issues: [{ message: '发布前旧阻塞' }] }
  const earlier = vm.fetchPreparation(7)
  await vm.fetchWorkOrder()
  assert.equal(vm.preparation, null); assert.deepEqual(vm.gateChecks, [])
  resolvePreparation({ data: { data: { issues: [{ message: '迟到的旧阻塞' }] } } }); await earlier
  assert.equal(vm.preparation, null); assert.deepEqual(vm.gateChecks, [])
})
