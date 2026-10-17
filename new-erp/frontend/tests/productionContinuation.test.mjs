import test from 'node:test'
import assert from 'node:assert/strict'
import { continuationFocus, matchesContinuationSource, continuationExecutionStatus } from '../src/utils/production-continuation.mjs'

test('shipment continuation focus requires an exact physical balance and output pair', () => {
  assert.equal(continuationFocus({ continuation_output_id: '4' }), null)
  assert.equal(continuationFocus({ continuation_balance_id: '0', continuation_output_id: '4' }), null)
  const focus = continuationFocus({ continuation_balance_id: '8', continuation_output_id: '4', continuation_serial_id: '9', continuation_qty: '2.5' })
  assert.deepEqual(focus, { inventory_balance_id: 8, source_output_record_id: 4, inventory_serial_id: 9, base_qty: 2.5 })
  assert.equal(matchesContinuationSource({ inventory_balance_id: 8, source_output_record_id: 5 }, focus), false)
  assert.equal(matchesContinuationSource({ inventory_balance_id: 8, source_output_record_id: 4 }, focus), true)
})

test('focus values cannot smuggle invalid ids or invalid decimal quantities', () => {
  assert.equal(continuationFocus({ continuation_balance_id: '8x', continuation_output_id: '4' }), null)
  assert.equal(continuationFocus({ continuation_balance_id: '8', continuation_output_id: '4', continuation_qty: '-1' }).base_qty, 1)
  assert.equal(continuationFocus({ continuation_balance_id: '8', continuation_output_id: '4', continuation_qty: '1.234567891' }).base_qty, 1)
})

test('dry inventory state keeps formal dispatch and receiver confirmation distinct', () => {
  assert.equal(continuationExecutionStatus({ continuation_mode: 'same_work_order', internal_issue_status: 'WAIT_ISSUE' }), '干态入库，待试水领用')
  assert.equal(continuationExecutionStatus({ continuation_mode: 'new_work_order', internal_issue_status: 'WAIT_ISSUE' }), '库存已保留，待续接领用')
  assert.equal(continuationExecutionStatus({ internal_issue_status: 'ISSUED' }), '仓库已交出，待工序接收')
  assert.equal(continuationExecutionStatus({ internal_issue_status: 'RECEIVED' }), '已领用，继续后续工序')
})
