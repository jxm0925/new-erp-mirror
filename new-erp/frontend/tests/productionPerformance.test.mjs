import test from 'node:test'
import assert from 'node:assert/strict'
import { percentToRatio, ratioToPercent, buildPerformanceShares, performanceSelectionTotals } from '../src/utils/production-performance.mjs'

test('confirmed zero and empty personal shares have distinct meanings', () => {
  assert.equal(percentToRatio('0'), '0.00000000')
  assert.throws(() => percentToRatio(''))
  assert.equal(ratioToPercent('0.00000000'), '0')
  assert.equal(ratioToPercent(null), '')
  assert.deepEqual(buildPerformanceShares([{ employee_legacy_id: 1, eligible: null, percent: '' }], true, '全部不计'), [])
  assert.equal(buildPerformanceShares([{ employee_legacy_id: 1, eligible: true, percent: '0' }], true, '全部不计')[0].share_ratio, '0.00000000')
})

test('fraction conversion preserves six decimal percent places', () => {
  assert.equal(percentToRatio('15.123456'), '0.15123456')
  assert.equal(ratioToPercent('0.15123456'), '15.123456')
  assert.equal(percentToRatio('100'), '1.00000000')
  assert.equal(ratioToPercent('1.00000000'), '100')
  for (const invalid of ['100.000001', '-1', '1e2', '0.0000001']) assert.throws(() => percentToRatio(invalid))
})

test('partial shares stay unchanged and excluded participation is retained', () => {
  const rows = [{ employee_legacy_id: 1, eligible: true, percent: '60' }, { employee_legacy_id: 2, eligible: true, percent: '20' }, { employee_legacy_id: 3, eligible: false, percent: '20' }]
  const shares = buildPerformanceShares(rows, true, '临时参与部分不计绩效')
  assert.equal(shares[0].share_ratio, '0.60000000')
  assert.equal(shares[2].eligible, false)
  assert.deepEqual(performanceSelectionTotals(rows), { declared: 100, credited: 80, remainder: 20 })
  assert.throws(() => buildPerformanceShares(rows, false, ''))
})

test('unknown eligibility, oversized shares, and duplicate people cannot be submitted', () => {
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, eligible: null, percent: '5' }], true, '剩余不计'))
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, eligible: true, percent: '60' }, { employee_legacy_id: 2, eligible: false, percent: '50' }], true, '剩余不计'))
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, eligible: true, percent: '30' }, { employee_legacy_id: 1, eligible: true, percent: '30' }], true, '剩余不计'))
})
