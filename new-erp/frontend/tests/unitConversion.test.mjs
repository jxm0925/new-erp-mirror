import test from 'node:test'
import assert from 'node:assert/strict'
import { minimalValidPurchaseQuantity } from '../src/utils/unitConversion.js'

test('1 米 = 0.2 根且根为整数时，最小合规采购量为 5 米', () => {
  assert.equal(minimalValidPurchaseQuantity(0.2, 0, 3), 5)
})

test('换算后本身合规时返回 null，不改动默认数量', () => {
  assert.equal(minimalValidPurchaseQuantity(6, 0, 0), null)
  assert.equal(minimalValidPurchaseQuantity(0.2, 3, 3), null)
})

test('因子 0.4 时最小合规量为 2.5（采购单位允许小数）', () => {
  assert.equal(minimalValidPurchaseQuantity(0.4, 0, 3), 2.5)
})
