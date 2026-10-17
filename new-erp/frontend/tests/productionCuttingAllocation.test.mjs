import test from 'node:test'
import assert from 'node:assert/strict'
import { cuttingSourceSuggestion, validCuttingSource, validCuttingGroup, cuttingPickingLine } from '../src/utils/production-cutting-allocation.mjs'

const demand = { id: 8, cutting_unit_kind: 'root', remaining_to_prepare: '0.1', remaining_cut_piece_qty: 2 }
const source = { id: 12, cutting_unit_kind: 'root', picking_available_qty: '3', cutting_capacity_piece_qty: 20, remaining_cut_piece_qty: 2 }

test('two custom segments use one real root while keeping the fractional theoretical demand', () => {
  const row = { ...source, ...cuttingSourceSuggestion(source, demand, []) }
  assert.deepEqual({ quantity: row.quantity, pieces: row.planned_cut_piece_qty }, { quantity: '1', pieces: '2' })
  assert.equal(validCuttingSource(row), true)
  assert.equal(validCuttingGroup(demand, [row]), true)
  assert.deepEqual(cuttingPickingLine(demand, row), { target_material_requirement_id: 8, inventory_balance_id: 12, planned_pick_qty: '1', planned_cut_piece_qty: 2 })
  assert.equal(demand.remaining_to_prepare, '0.1')
})

test('raw fractional roots cannot masquerade as a whole root or oversell its cutting capacity', () => {
  assert.equal(validCuttingSource({ ...source, quantity: '0.1', planned_cut_piece_qty: '2' }), false)
  assert.equal(validCuttingSource({ ...source, quantity: '1', planned_cut_piece_qty: '21', remaining_cut_piece_qty: 25 }), false)
  assert.equal(validCuttingSource({ ...source, quantity: '1', planned_cut_piece_qty: '1.5' }), false)
})

test('split stock sources cannot duplicate the same remaining segment demand', () => {
  const a = { ...source, quantity: '1', planned_cut_piece_qty: '2' }
  const b = { ...a, id: 13 }
  assert.equal(validCuttingGroup(demand, [a, b]), false)
  b.planned_cut_piece_qty = '1'; a.planned_cut_piece_qty = '1'
  assert.equal(validCuttingGroup(demand, [a, b]), true)
})

test('missing dimensions or server capacity produce no made-up stock suggestion', () => {
  assert.deepEqual(cuttingSourceSuggestion({ ...source, cutting_capacity_piece_qty: null }, demand, []), { quantity: '', planned_cut_piece_qty: '' })
  assert.deepEqual(cuttingSourceSuggestion({ ...source, picking_available_qty: '0.8' }, demand, []), { quantity: '', planned_cut_piece_qty: '' })
})

test('available source capacity limits its suggested coverage and leaves the rest for another source', () => {
  const large = { ...demand, remaining_cut_piece_qty: 45 }
  const limited = { ...source, picking_available_qty: '1', remaining_cut_piece_qty: 45 }
  const first = { ...limited, ...cuttingSourceSuggestion(limited, large, []) }
  assert.deepEqual({ quantity: first.quantity, pieces: first.planned_cut_piece_qty }, { quantity: '1', pieces: '20' })
  const other = { ...limited, picking_available_qty: '3' }
  assert.deepEqual(cuttingSourceSuggestion(other, large, [first]), { quantity: '2', planned_cut_piece_qty: '25' })
})

test('stale source capacity or remaining demand blocks reuse of the previous cutting plan', () => {
  const chosen = { ...source, quantity: '1', planned_cut_piece_qty: '2' }
  assert.equal(validCuttingSource({ ...chosen, remaining_cut_piece_qty: 1 }), false)
  assert.equal(validCuttingSource({ ...chosen, cutting_capacity_piece_qty: 1 }), false)
  assert.equal(validCuttingSource({ ...chosen, picking_available_qty: 0 }), false)
})

test('ordinary materials retain decimal stock units and never send invented cutting identity', () => {
  const ordinary = { id: 7, quantity: '2.50000001', picking_available_qty: '3' }
  assert.equal(validCuttingGroup({ id: 2, remaining_to_prepare: '2.50000001' }, [ordinary]), true)
  assert.deepEqual(cuttingPickingLine({ id: 2 }, ordinary), { target_material_requirement_id: 2, inventory_balance_id: 7, planned_pick_qty: '2.50000001' })
})

test('explicit physical source IDs stay separate from quantity and from cut piece count', () => {
  const row = { ...source, cutting_unit_kind: 'sheet', quantity: '1', planned_cut_piece_qty: '2', physical_material_ids: [51] }
  const payload = cuttingPickingLine(demand, row)
  assert.deepEqual(payload.physical_material_ids, [51])
  assert.equal(payload.planned_pick_qty, '1')
  assert.equal(payload.planned_cut_piece_qty, 2)
  assert.equal('item_id' in payload, false)
})
