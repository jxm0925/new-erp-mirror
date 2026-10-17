import test from 'node:test'
import assert from 'node:assert/strict'
import { percentToRatio, ratioToPercent, buildPerformanceShares, performanceSelectionTotals, defaultPerformanceShares, buildPerformanceRatePayload } from '../src/utils/production-performance.mjs'

test('confirmed zero and empty personal shares have distinct meanings', () => {
  assert.equal(percentToRatio('0'), '0.00000000')
  assert.throws(() => percentToRatio(''))
  assert.equal(ratioToPercent('0.00000000'), '0')
  assert.equal(ratioToPercent(null), '')
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, percent: '' }]))
  assert.equal(buildPerformanceShares([{ employee_legacy_id: 1, percent: '0' }, { employee_legacy_id: 2, percent: '100' }])[0].share_ratio, '0.00000000')
})

test('fraction conversion preserves six decimal percent places', () => {
  assert.equal(percentToRatio('15.123456'), '0.15123456')
  assert.equal(ratioToPercent('0.15123456'), '15.123456')
  assert.equal(percentToRatio('100'), '1.00000000')
  assert.equal(ratioToPercent('1.00000000'), '100')
  for (const invalid of ['100.000001', '-1', '1e2', '0.0000001']) assert.throws(() => percentToRatio(invalid))
})

test('viewing historical excluded participation preserves its facts but a new edit requires a full allocation', () => {
  const rows = [{ employee_legacy_id: 1, eligible: true, percent: '60' }, { employee_legacy_id: 2, eligible: true, percent: '20' }, { employee_legacy_id: 3, eligible: false, percent: '20' }]
  assert.deepEqual(performanceSelectionTotals(rows), { declared: 100, credited: 80, remainder: 20 })
  const original = structuredClone(rows)
  const shares = buildPerformanceShares(rows)
  assert.equal(shares[0].share_ratio, '0.60000000')
  assert.equal(shares[2].eligible, true)
  assert.deepEqual(rows, original)
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, percent: '80' }], true, '旧原因不能绕过新规则'))
})

test('incomplete or oversized shares and duplicate people cannot be submitted', () => {
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, percent: '5' }]))
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, percent: '60' }, { employee_legacy_id: 2, percent: '50' }]))
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 1, percent: '50' }, { employee_legacy_id: 1, percent: '50' }]))
  assert.throws(() => buildPerformanceShares([{ employee_legacy_id: 0, percent: '100' }]))
  assert.throws(() => buildPerformanceShares([]))
})

test('manual owner and collaborator 70/30 allocation uses real identities and exact ratios', () => {
  const shares = buildPerformanceShares([{ employee_legacy_id: 9, percent: '30' }, { employee_legacy_id: 3, percent: '70' }])
  assert.deepEqual(shares, [{ employee_legacy_id: 3, eligible: true, share_ratio: '0.70000000', remark: null }, { employee_legacy_id: 9, eligible: true, share_ratio: '0.30000000', remark: null }])
})

test('one participant gets 100 percent and three participants receive the exact residual', () => {
  assert.deepEqual(defaultPerformanceShares([{ employee_legacy_id: 3 }]), [{ employee_legacy_id: 3, share_ratio: '1.00000000' }])
  const thirds = defaultPerformanceShares([{ employee_legacy_id: 3 }, { employee_legacy_id: 1 }, { employee_legacy_id: 2 }])
  assert.deepEqual(thirds.map(row => row.share_ratio), ['0.33333333', '0.33333333', '0.33333334'])
  assert.deepEqual(buildPerformanceShares(thirds.map(row => ({ ...row, percent: ratioToPercent(row.share_ratio) }))).map(row => row.share_ratio), thirds.map(row => row.share_ratio))
})

test('defaults use all current participant identities and remain 100 percent across server pages', () => {
  const shares = defaultPerformanceShares(Array.from({ length: 41 }, (_, index) => ({ employee_legacy_id: index + 1 })))
  const submitted = buildPerformanceShares(shares.map(row => ({ ...row, percent: ratioToPercent(row.share_ratio) })))
  assert.equal(submitted.length, 41)
  assert.equal(submitted.reduce((sum, row) => sum + BigInt(row.share_ratio.slice(2)), 0n), 100000000n)
  assert.deepEqual(defaultPerformanceShares([{ employee_legacy_id: 0 }]), [])
})

test('performance rate percent is independent of the full personal allocation and retains version and reason', () => {
  const scope = { scope_type: 'quantity_operation', scope_id: 6, scope_version: 4, rate_version: 2 }
  const payload = buildPerformanceRatePayload(scope, '1', '  重新核定工序比例  ', 'rate-1')
  assert.deepEqual(payload, { client_command_id: 'rate-1', expected_scope_version: 4, expected_rate_version: 2, performance_rate: '0.01000000', reason: '重新核定工序比例' })
  assert.equal(buildPerformanceRatePayload({ ...scope, rate_version: 0 }, '2', '首次核定', 'rate-2').performance_rate, '0.02000000')
  for (const [percent, reason] of [['', '原因'], ['101', '原因'], ['1', ''], ['1', 'x'.repeat(501)]]) assert.throws(() => buildPerformanceRatePayload(scope, percent, reason, 'rate-error'))
  assert.throws(() => buildPerformanceRatePayload({ ...scope, scope_version: 0 }, '1', '原因', 'rate-error'))
  assert.throws(() => buildPerformanceRatePayload({ ...scope, scope_type: 'unknown' }, '1', '原因', 'rate-error'))
})
