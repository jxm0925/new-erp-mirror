import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const source = await readFile(new URL('../src/utils/dateTime.js', import.meta.url), 'utf8')
const { erpDateTime } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)

test('UTC ISO 时间按业务时区换算，不再直接截断', () => {
  assert.equal(erpDateTime('2026-10-12T05:52:12.000000Z', 19, 'Asia/Shanghai'), '2026-10-12 13:52:12')
  assert.equal(erpDateTime('2026-10-11T17:30:00Z', 16, 'Asia/Shanghai'), '2026-10-12 01:30')
  assert.equal(erpDateTime('2026-10-12T05:52:12.000000Z', 19, 'America/New_York'), '2026-10-12 01:52:12')
  // 美东夏令时结束后偏移变为 -05:00
  assert.equal(erpDateTime('2026-11-02T05:00:00Z', 16, 'America/New_York'), '2026-11-02 00:00')
  assert.equal(erpDateTime('2026-10-12T05:52:12+00:00', 10, 'Asia/Shanghai'), '2026-10-12')
})

test('数据库直出的本地时间字符串原样截取', () => {
  assert.equal(erpDateTime('2026-10-12 13:52:12'), '2026-10-12 13:52:12')
  assert.equal(erpDateTime('2026-10-12 13:52:12', 16), '2026-10-12 13:52')
  assert.equal(erpDateTime('2026-10-12'), '2026-10-12')
})

test('空值返回空串，由调用方决定占位符', () => {
  assert.equal(erpDateTime(null), '')
  assert.equal(erpDateTime(''), '')
  assert.equal(erpDateTime(undefined), '')
})
