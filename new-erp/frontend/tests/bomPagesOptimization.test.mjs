import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import compiler from 'vue-template-compiler'

const bomListSource = await readFile(new URL('../src/views/erp/bom/BomList.vue', import.meta.url), 'utf8')
const bomExpandSource = await readFile(new URL('../src/views/erp/bom/BomExpand.vue', import.meta.url), 'utf8')
const bomFormSource = await readFile(new URL('../src/views/erp/bom/BomForm.vue', import.meta.url), 'utf8')
const bomDetailSource = await readFile(new URL('../src/views/erp/bom/BomDetail.vue', import.meta.url), 'utf8')

test('BomList template compiles and satisfies all UI and dialog architecture mandates', () => {
  // 1. Vue compilation check using parseComponent
  const parsed = compiler.parseComponent(bomListSource)
  assert.ok(parsed.template, 'BomList template should exist')
  const compiled = compiler.compile(parsed.template.content)
  assert.deepEqual(compiled.errors, [], `Template compile errors: ${compiled.errors.join('; ')}`)

  // 2. Absolute ban on el-drawer (Dialog architecture mandate 2026-09-24)
  assert.ok(!bomListSource.includes('<el-drawer'), 'Must NOT contain el-drawer')
  assert.ok(!bomListSource.includes('</el-drawer>'), 'Must NOT contain </el-drawer>')
  assert.ok(bomListSource.includes('<el-dialog'), 'Must contain centered el-dialog')

  // 3. Page head and tip banner
  assert.ok(bomListSource.includes('class="page-head"'), 'Must have standard .page-head white card')
  assert.ok(bomListSource.includes('class="head-icon"'), 'Must have green .head-icon')
  assert.ok(bomListSource.includes('class="page-title"'), 'Must have .page-title')
  assert.ok(bomListSource.includes('class="erp-page-tip"'), 'Must have standard .erp-page-tip banner')

  // 4. Metric overview cards
  assert.ok(bomListSource.includes('class="metric-overview-grid"'), 'Must have .metric-overview-grid')
  assert.ok(bomListSource.includes('metric-all'), 'Must have all metric card')
  assert.ok(bomListSource.includes('metric-active'), 'Must have active metric card')
  assert.ok(bomListSource.includes('metric-pending'), 'Must have pending metric card')
  assert.ok(bomListSource.includes('metric-default'), 'Must have default metric card')

  // 5. Centered dialog header banner & Detail dialog
  assert.ok(bomListSource.includes('dialog-header-banner'), 'Must have .dialog-header-banner in dialog')
  assert.ok(bomListSource.includes('bom-detail-dialog'), 'Must have .bom-detail-dialog modal')
  assert.ok(bomListSource.includes('openDetail(row)'), 'View button must call openDetail(row)')

  // 6. Default pageSize is 10
  assert.ok(bomListSource.includes('perPage: 10'), 'Default pageSize must be 10 per 2026-10-15 mandate')
})

test('BomList script logic and filter methods execute accurately', () => {
  const scriptContent = compiler.parseComponent(bomListSource).script.content
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const options = new Function(scriptContent)()
  const vm = {
    ...options.data(),
    rows: [
      { id: 1, bom_no: 'BOM001', bom_name: '测试BOM1', status: 'active', audit_status: 'approved', is_default: true, version: 'V1.0' },
      { id: 2, bom_no: 'BOM002', bom_name: '测试BOM2', status: 'draft', audit_status: 'pending', is_default: false, version: 'V1.0' }
    ],
    total: 2,
    stats: { total: 2, active: 1, pending: 1, default: 1 },
    $message: { error: () => {}, success: () => {} },
    $router: { push: () => {} },
    load: () => {}
  }
  for (const [name, fn] of Object.entries(options.methods)) {
    if (name !== 'load') vm[name] = fn.bind(vm)
  }
  for (const [name, fn] of Object.entries(options.computed || {})) {
    Object.defineProperty(vm, name, { get: () => fn.call(vm) })
  }

  // Filter switching
  vm.filterStatus('active')
  assert.equal(vm.query.status, 'active')
  assert.equal(vm.activeFilterTag, '筛选: 正常启用')

  vm.filterAudit('pending')
  assert.equal(vm.query.audit_status, 'pending')
  assert.equal(vm.activeFilterTag, '筛选: 待审核')

  vm.filterDefault('true')
  assert.equal(vm.query.is_default, 'true')
  assert.equal(vm.activeFilterTag, '筛选: 默认方案')

  vm.filterAll()
  assert.equal(vm.query.status, '')
  assert.equal(vm.query.audit_status, '')
  assert.equal(vm.query.is_default, '')
  assert.equal(vm.activeFilterTag, '')

  // Next version helper
  assert.equal(vm.nextVersion('V1.0'), 'V1.1')
  assert.equal(vm.nextVersion('V2'), 'V3')

  // Cut requirement
  const cut = vm.cutRequirement({ cut_length_mm: 800, cut_width_mm: 400, cut_thickness_mm: 3, piece_qty: 2 })
  assert.ok(cut.includes('800 × 400 × 3 mm × 2件'))
})

test('BomForm template compiles and satisfies all UI, form structure and dialog architecture mandates', () => {
  // 1. Vue compilation check
  const parsed = compiler.parseComponent(bomFormSource)
  assert.ok(parsed.template, 'BomForm template should exist')
  const compiled = compiler.compile(parsed.template.content)
  assert.deepEqual(compiled.errors, [], `Template compile errors: ${compiled.errors.join('; ')}`)

  // 2. Absolute ban on el-drawer
  assert.ok(!bomFormSource.includes('<el-drawer'), 'BomForm must NOT contain el-drawer')
  assert.ok(!bomFormSource.includes('</el-drawer>'), 'BomForm must NOT contain </el-drawer>')
  assert.ok(bomFormSource.includes('<el-dialog'), 'BomForm must contain centered el-dialog for picker')

  // 3. Form document header aligned with ItemForm
  assert.ok(bomFormSource.includes('class="page-head"'), 'BomForm must have standard .page-head')
  assert.ok(bomFormSource.includes('class="page-title"'), 'BomForm must have .page-title')
  assert.ok(bomFormSource.includes('class="erp-page-tip"'), 'BomForm must have standard .erp-page-tip banner')

  // 4. Form cards structure & parallel top grid
  assert.ok(bomFormSource.includes('class="bom-top-cards-grid"'), 'BomForm must have .bom-top-cards-grid parallel layout')
  assert.ok(bomFormSource.includes('class="form-card"'), 'BomForm must have .form-card containers')
  assert.ok(bomFormSource.includes('基础属性与版本控制'), 'BomForm must have basic info section')
  assert.ok(bomFormSource.includes('产出对象与单位'), 'BomForm must have output target section')
  assert.ok(bomFormSource.includes('BOM 组成物料清单明细'), 'BomForm must have items detail section')
  assert.ok(bomFormSource.includes('方案状态与单据备注'), 'BomForm must have status & remark section')
  assert.ok(bomFormSource.includes('class="footer-bar"'), 'BomForm must have sticky .footer-bar')

  // 5. Centered dialog header & prominent close button, and 10 items pagination
  assert.ok(bomFormSource.includes('dialog-custom-header'), 'BomForm picker must have custom dialog header')
  assert.ok(bomFormSource.includes('item-picker-dialog'), 'BomForm picker must have item-picker-dialog modal')
  assert.ok(bomFormSource.includes('perPage: 10'), 'BomForm picker default perPage must be 10')
})

test('BomDetail redirects to modal dialog on BomList', () => {
  assert.ok(bomDetailSource.includes('$router.replace'), 'BomDetail must redirect')
  assert.ok(bomDetailSource.includes('/bom/boms'), 'BomDetail must redirect to /bom/boms')
  assert.ok(bomDetailSource.includes('view_id'), 'BomDetail must pass view_id query parameter')
})

test('BomExpand template compiles and satisfies all UI and calculation mandates', () => {
  // 1. Vue compilation check
  const parsed = compiler.parseComponent(bomExpandSource)
  assert.ok(parsed.template, 'BomExpand template should exist')
  const compiled = compiler.compile(parsed.template.content)
  assert.deepEqual(compiled.errors, [], `Template compile errors: ${compiled.errors.join('; ')}`)

  // 2. Absolute ban on el-drawer
  assert.ok(!bomExpandSource.includes('<el-drawer'), 'Must NOT contain el-drawer')

  // 3. Page head and tip banner
  assert.ok(bomExpandSource.includes('class="page-head"'), 'Must have standard .page-head white card')
  assert.ok(bomExpandSource.includes('class="head-icon"'), 'Must have green .head-icon')
  assert.ok(bomExpandSource.includes('class="page-title"'), 'Must have .page-title')
  assert.ok(bomExpandSource.includes('class="erp-page-tip"'), 'Must have standard .erp-page-tip banner')

  // 4. Metric overview cards (5-metric grid for simulation results)
  assert.ok(bomExpandSource.includes('class="metric-overview-grid metric-five-cols"'), 'Must have 5-metric grid')
  assert.ok(bomExpandSource.includes('metric-lines'), 'Must have lines metric card')
  assert.ok(bomExpandSource.includes('metric-tree'), 'Must have tree metric card')
  assert.ok(bomExpandSource.includes('metric-demand'), 'Must have demand metric card')
  assert.ok(bomExpandSource.includes('metric-avail'), 'Must have avail metric card')
  assert.ok(bomExpandSource.includes('metric-shortage'), 'Must have shortage metric card')

  // 5. Centered dialog for material picker
  assert.ok(bomExpandSource.includes('<el-dialog'), 'Must contain centered el-dialog')
  assert.ok(bomExpandSource.includes('dialog-header-banner'), 'Must have .dialog-header-banner in picker dialog')
})

test('BomExpand script logic and calculation payloads work accurately', () => {
  const scriptContent = compiler.parseComponent(bomExpandSource).script.content
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const options = new Function(scriptContent)()
  const vm = {
    ...options.data(),
    mode: 'bom',
    form: { bom_id: 101, sku_id: null, output_item_id: null, planned_qty: 5 }
  }
  for (const [name, fn] of Object.entries(options.methods)) vm[name] = fn.bind(vm)
  for (const [name, fn] of Object.entries(options.computed || {})) {
    Object.defineProperty(vm, name, { get: () => fn.call(vm) })
  }

  // BOM mode payload
  assert.deepEqual(vm.requestPayload(), { bom_id: 101, sku_id: null, output_item_id: null, planned_qty: 5 })

  // SKU mode payload
  vm.mode = 'sku'
  vm.form.sku_id = 202
  assert.deepEqual(vm.requestPayload(), { bom_id: null, sku_id: 202, output_item_id: null, planned_qty: 5 })

  // Cut requirement formatting
  const cutText = vm.cutRequirement({ cut_length_mm: 500, cut_width_mm: 200, cut_thickness_mm: 5, required_piece_qty: 10 }, true)
  assert.ok(cutText.includes('500 × 200 × 5 mm × 10件'))
})
