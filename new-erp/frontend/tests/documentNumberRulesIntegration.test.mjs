import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

async function setupPage (dependencies = {}, permissions = ['document_number_rule.view', 'document_number_rule.manage']) {
  const source = await readFile(new URL('../src/views/erp/system/DocumentNumberRuleList.vue', import.meta.url), 'utf8')
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import\s+[\s\S]*?\s+from\s+['"][^'"]+['"]/g, '')
    .replace('export default', 'return')

  const deps = {
    createDocumentNumberRule: async () => ({ data: { message: '创建成功' } }),
    updateDocumentNumberRule: async () => ({ data: { message: '更新成功' } }),
    disableDocumentNumberRule: async () => ({ data: { message: '停用成功' } }),
    enableDocumentNumberRule: async () => ({ data: { message: '启用成功' } }),
    listDocumentNumberRules: async () => ({ data: { data: [], total: 0 } }),
    listDocumentNumberRuleTypes: async () => ({ data: { data: [] } }),
    ...dependencies
  }

  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const messages = []
  const vm = {
    ...options.data(),
    $refs: {
      ruleForm: {
        validate: async () => true,
        clearValidate: () => {}
      }
    },
    $can: permission => permissions.includes(permission),
    $message: Object.fromEntries(['success', 'warning', 'error', 'info'].map(level => [level, message => messages.push({ level, message })])),
    ...options.methods
  }
  Object.entries(options.computed || {}).forEach(([key, get]) => Object.defineProperty(vm, key, { get: () => get.call(vm) }))
  return { vm, options, messages, source }
}

test('DocumentNumberRuleList conforms to Master Data Center and Dialog architectural standards', async () => {
  const { source } = await setupPage()

  // 1. 废除 Drawer，全面居中弹窗化
  assert.ok(!source.includes('<el-drawer'), 'Must completely eliminate el-drawer per 2026-09-24 rule')
  assert.ok(source.includes('<el-dialog'), 'Must use centered el-dialog')
  assert.ok(source.includes('number-rule-dialog'), 'Must define custom-class for dialog')

  // 2. 标头、提示条与概览卡片规范
  assert.ok(source.includes('class="page-head"'), 'Must have standardized .page-head')
  assert.ok(source.includes('class="head-icon"'), 'Must have standardized .head-icon')
  assert.ok(source.includes('class="erp-page-tip"'), 'Must have standard .erp-page-tip banner')
  assert.ok(source.includes('class="metric-overview-grid"'), 'Must provide 4-metric overview cards')
  assert.ok(source.includes('metric-card metric-all'), 'Must have metric card for all rules')
  assert.ok(source.includes('metric-card metric-enabled'), 'Must have metric card for enabled rules')
  assert.ok(source.includes('metric-card metric-disabled'), 'Must have metric card for disabled rules')
  assert.ok(source.includes('metric-card metric-types'), 'Must have metric card for business types')

  // 3. 仿真编号生成卡片与拆解
  assert.ok(source.includes('example-preview-box'), 'Must feature rich simulated number preview box')
  assert.ok(source.includes('preview-breakdown-bar'), 'Must breakdown rule into prefix + date + sequence')

  // 4. 消除单行压缩混淆 CSS
  const styles = source.match(/<style[^>]*>([\s\S]*?)<\/style>/g) || []
  assert.ok(styles.length > 0, 'Styles must be present')
  assert.ok(!source.includes('.number-rule-page{min-height:calc'), 'Must not contain single-line obfuscated CSS')
})

test('loads rule types and rule list, and calculates metric summaries correctly', async () => {
  const mockTypes = [
    { code: 'purchase_order', label: '采购订单', configured: true },
    { code: 'sales_order', label: '销售订单', configured: true },
    { code: 'work_order', label: '生产工单', configured: false }
  ]
  const mockRules = [
    { id: 1, document_type: 'purchase_order', business_type_label: '采购订单', name: '采购订单标准规则', prefix: 'PO', date_format: 'YYYYMMDD', sequence_length: 5, reset_cycle: 'daily', enabled: true, current_sequence: 12, format_example: 'PO2026101300012' },
    { id: 2, document_type: 'sales_order', business_type_label: '销售订单', name: '销售订单规则', prefix: 'SO', date_format: 'YYYYMMDD', sequence_length: 5, reset_cycle: 'daily', enabled: false, current_sequence: 0, format_example: 'SO2026101300001' }
  ]

  const { vm } = await setupPage({
    listDocumentNumberRuleTypes: async () => ({ data: { data: mockTypes } }),
    listDocumentNumberRules: async () => ({
      data: {
        data: mockRules,
        total: 50,
        stats: { total: 50, enabled: 50, disabled: 0 }
      }
    })
  })

  await vm.initialize()

  assert.equal(vm.types.length, 3)
  assert.equal(vm.rows.length, 2)
  assert.equal(vm.total, 50)
  assert.equal(vm.enabledCount, 50, 'Must accurately reflect global 50 enabled rules rather than page length 20')
  assert.equal(vm.disabledCount, 0, 'Must accurately reflect global 0 disabled rules')
  assert.equal(vm.configuredTypesCount, 2)
})

test('overview cards and status filters update query parameters and trigger search', async () => {
  let queriedWith = null
  const { vm } = await setupPage({
    listDocumentNumberRules: async params => {
      queriedWith = params
      return { data: { data: [], total: 0 } }
    }
  })

  vm.filterStatus('1')
  assert.equal(vm.query.enabled, '1')
  assert.equal(queriedWith.enabled, '1')
  assert.equal(vm.activeFilterTag, '状态：正常启用')

  vm.filterStatus('0')
  assert.equal(vm.query.enabled, '0')
  assert.equal(queriedWith.enabled, '0')
  assert.equal(vm.activeFilterTag, '状态：已停用')

  vm.clearFilterTag()
  assert.equal(vm.query.enabled, '')
  assert.equal(vm.query.document_type, '')
  assert.equal(vm.activeFilterTag, '')
})

test('formatExample and preview breakdown react immediately to rule configuration changes', async () => {
  const { vm } = await setupPage()

  vm.form.prefix = 'PR'
  vm.form.date_format = 'YYYYMM'
  vm.form.sequence_length = 4
  vm.syncCycle('YYYYMM')

  assert.equal(vm.form.reset_cycle, 'monthly')
  assert.ok(vm.formatExample.startsWith('PR'))
  assert.ok(vm.formatExample.endsWith('0001'))
  assert.equal(vm.previewSequencePart, '0001')

  vm.form.prefix = 'ITEM'
  vm.form.date_format = 'none'
  vm.form.sequence_length = 6
  vm.syncCycle('none')

  assert.equal(vm.form.reset_cycle, 'none')
  assert.equal(vm.formatExample, 'ITEM000001')
  assert.equal(vm.previewDatePart, '无日期')
  assert.equal(vm.previewSequencePart, '000001')
})

test('edit mode enforces change detection and mandates change_reason when dirty', async () => {
  let updatedPayload = null
  const { vm, messages } = await setupPage({
    updateDocumentNumberRule: async (id, payload) => {
      updatedPayload = payload
      return { data: { message: '规则已成功更新' } }
    }
  })

  const row = {
    id: 10,
    document_type: 'purchase_order',
    name: '采购原规则',
    prefix: 'PO',
    date_format: 'YYYYMMDD',
    sequence_length: 5,
    reset_cycle: 'daily',
    enabled: true
  }
  vm.rows = [row]
  vm.openEdit(row)

  assert.equal(vm.dialogVisible, true)
  assert.equal(vm.editing, true)

  // 1. 无变更点击保存：提示未发生变更
  await vm.save()
  assert.equal(messages[0].level, 'warning')
  assert.equal(messages[0].message, '编号规则没有发生任何变化')
  assert.equal(updatedPayload, null)

  // 2. 有变更但未填写修改原因：拦截并设置 reasonError
  vm.form.sequence_length = 6
  await vm.save()
  assert.equal(vm.reasonError, '规则实际发生变更时必须填写修改原因')
  assert.equal(updatedPayload, null)

  // 3. 填写修改原因：保存成功并关闭弹窗
  vm.form.change_reason = '业务扩展，流水位数升级为6位'
  await vm.save()
  assert.equal(messages[1].level, 'success')
  assert.equal(vm.dialogVisible, false)
  assert.equal(updatedPayload.sequence_length, 6)
  assert.equal(updatedPayload.change_reason, '业务扩展，流水位数升级为6位')
})

test('disable and enable workflow functions correctly with dialog feedback', async () => {
  let disabledId = null
  let enabledId = null

  const { vm, messages } = await setupPage({
    disableDocumentNumberRule: async id => {
      disabledId = id
      return { data: { message: '规则已停用' } }
    },
    enableDocumentNumberRule: async id => {
      enabledId = id
      return { data: { message: '规则已启用' } }
    }
  })

  const row = { id: 88, document_type: 'test_doc', name: '测试规则', prefix: 'TT', enabled: true }

  // 停用弹窗
  vm.openDisable(row)
  assert.equal(vm.disableVisible, true)
  assert.equal(vm.disableTarget.id, 88)

  await vm.confirmDisable()
  assert.equal(disabledId, 88)
  assert.equal(vm.disableVisible, false)
  assert.equal(messages[0].level, 'success')

  // 启用
  await vm.enable(row)
  assert.equal(enabledId, 88)
  assert.equal(messages[1].level, 'success')
})
