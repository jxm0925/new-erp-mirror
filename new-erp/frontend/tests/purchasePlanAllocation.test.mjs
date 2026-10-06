import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const source = await readFile(new URL('../src/utils/purchasePlanAllocation.js', import.meta.url), 'utf8')
const { planAllocation } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
const snap = qty => ({ planned_base_qty: qty })
const line = splits => ({ item_id: 34, purchase_quantity: 2, _planningPreview: snap(12), splits })
const split = (qty, supplier = 1) => ({ supplier_id: supplier, _planningPreview: snap(qty) })

test('两根折合12米，分配一根6米时进度为50%', () => {
  const result = planAllocation(line([split(6)]))
  assert.equal(result.percent, 50)
  assert.equal(result.delta, 6)
  assert.equal(result.balanced, false)
})
test('数量足够但未选供应商、超配和空行均不能标为配平', () => {
  assert.equal(planAllocation(line([split(12, null)])).state, 'supplier')
  assert.equal(planAllocation(line([split(18)])).state, 'over')
  assert.equal(planAllocation(line([split(18)])).delta, -6)
  assert.equal(planAllocation({ splits: [] }).balanced, false)
})
test('不同采购单位按库存数量合并，已分配6米加6米完成12米计划', () => {
  const result = planAllocation(line([split(6), split(6, 2)]))
  assert.equal(result.balanced, true)
  assert.equal(result.percent, 100)
})
test('已保存需求占用分配与实际采购分配不同，详情仍按实际采购数量', () => {
  const result = planAllocation({ item_id: 34, required_qty: 3, remaining_qty: 0,
    purchase_conversion_snapshot: snap(12),
    splits: [{ supplier_id: 1, purchase_qty: 3, purchase_conversion_snapshot: snap(6) }] })
  assert.equal(result.target, 12)
  assert.equal(result.allocated, 6)
  assert.equal(result.state, 'short')
})
test('重算中或失败不能用旧快照显示配平', () => {
  const row = line([split(12)])
  row.splits[0]._planningPending = true
  assert.equal(planAllocation(row).state, 'pending')
  row.splits[0]._planningPending = false
  row.splits[0]._planningPreview = null
  row.splits[0].purchase_conversion_snapshot = snap(12)
  row.splits[0]._planningError = '单位精度错误'
  assert.equal(planAllocation(row).state, 'error')
  assert.equal(planAllocation(row).balanced, false)
})
test('拆分使用 purchase_quantity 时，若换算快照未就绪，按录入数量和换算因子统计折合库存，进度条不归零', () => {
  const lineItem = { item_id: 34, purchase_quantity: 10, _planningPreview: { planned_base_qty: 10, conversion_factor_snapshot: 1 }, splits: [
    { supplier_id: 1, purchase_quantity: 5 }
  ] }
  const result = planAllocation(lineItem)
  assert.equal(result.target, 10)
  assert.equal(result.allocated, 5)
  assert.equal(result.percent, 50)
  assert.equal(result.delta, 5)
})

