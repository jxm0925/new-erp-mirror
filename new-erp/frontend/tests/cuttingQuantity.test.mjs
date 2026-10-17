import test from 'node:test'
import assert from 'node:assert/strict'
import { cuttingUnitKind, previewCuttingQuantity } from '../src/utils/cutting-quantity.mjs'

const unit = code => ({ unit_code: code })
const length = (cutLength = 300, pieces = 2) => ({ cutting_mode: 'length', cut_length_mm: cutLength, piece_qty: pieces })
const sheet = () => ({ cutting_mode: 'sheet', cut_length_mm: 500, cut_width_mm: 400, cut_thickness_mm: 1.5, piece_qty: 3 })

test('metres and square metres calculate geometric consumption', () => {
  assert.equal(previewCuttingQuantity(length(), unit('M')).quantity, 0.6)
  assert.equal(previewCuttingQuantity(sheet(), unit('M2')).quantity, 0.6)
})
test('root and sheet stock counts are theoretical fractions with explicit standard dimensions', () => {
  assert.equal(previewCuttingQuantity({ ...length(), standard_stock_length_mm: 6000 }, unit('ROOT')).quantity, 0.1)
  const result = previewCuttingQuantity({ ...sheet(), stock_dimensions: { length_mm: 2440, width_mm: 1220 } }, unit('SHEET'))
  assert.equal(result.quantity, 0.2016)
  assert.equal(result.theoreticalQuantity, 0.20155872)
})
test('missing reference dimensions never fall back to this cut size', () => {
  assert.ok(previewCuttingQuantity(length(), unit('ROOT')).error)
  assert.ok(previewCuttingQuantity(sheet(), unit('SHEET')).error)
})
test('unknown units cannot be inferred from a translated name', () => {
  assert.equal(cuttingUnitKind({ unit_code: 'UNKNOWN', unit_name: '米' }), null)
  assert.ok(previewCuttingQuantity(length(), unit('KG')).error)
  assert.equal(cuttingUnitKind({ unit_code: 'AREA', symbol: '㎡' }), 'm2')
})
test('rotation is explicit and material thickness and piece count are required', () => {
  const row = { ...sheet(), cut_length_mm: 900, cut_width_mm: 600, piece_qty: 1, stock_dimensions: { length_mm: 800, width_mm: 1000 } }
  assert.ok(previewCuttingQuantity(row, unit('SHEET')).error)
  assert.equal(previewCuttingQuantity({ ...row, allow_cut_rotation: true }, unit('SHEET')).quantity, 0.675)
  assert.ok(previewCuttingQuantity({ ...sheet(), cut_thickness_mm: null }, unit('M2')).error)
  assert.ok(previewCuttingQuantity(length(300, 1.5), unit('M')).error)
})
test('quantity cannot silently round to zero in existing four-place storage', () => {
  assert.ok(previewCuttingQuantity(length(0.01, 1), unit('M')).error)
})
