const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')

;(async () => {
  const utils = fs.readFileSync(path.join(__dirname, '../src/utils/completionCommand.js'), 'utf8').replace(/export /g, '')
  const source = fs.readFileSync(path.join(__dirname, '../src/api/erp/remnant-receipts.js'), 'utf8').replace(/^import .*$/gm, '').replace(/export /g, '')
  const values = new Map([['erp_user', '{"legacy_id":7}']])
  const storage = { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  const calls = []
  let get = async () => ({ data: { data: { status: 'NOT_FOUND' } } })
  let post = async () => { throw new Error('connection lost after server commit') }
  const api = { get: (...args) => { calls.push(['get', ...args]); return get(...args) }, post: (...args) => { calls.push(['post', ...args]); return post(...args) } }
  const context = vm.createContext({ localStorage: storage, api, Date, Math, JSON, Error })
  vm.runInContext(utils + '\n' + source + '\nthis.client = { postRemnantReceipt, pendingRemnantReceipt }', context)
  const { client } = context
  const original = { warehouse_id: 1, location_id: 2, lines: [{ result_id: 3, expected_version: 4 }] }
  await assert.rejects(client.postRemnantReceipt(12, original), /connection lost/)
  const saved = client.pendingRemnantReceipt(12)
  assert.equal(saved.payload.lines[0].expected_version, 4)
  // A status endpoint failure must never discard an uncertain successful write.
  get = async () => { throw { response: { status: 404 } } }
  await assert.rejects(client.postRemnantReceipt(12, { warehouse_id: 99 }))
  assert.deepEqual(client.pendingRemnantReceipt(12), saved)
  assert.equal(calls.filter(x => x[0] === 'post').length, 1)
  get = async () => ({ data: { data: { status: 'SUCCEEDED', result: { receipt_id: 8 } } } })
  assert.equal((await client.postRemnantReceipt(12, {})).data.data.receipt_id, 8)
  assert.equal(client.pendingRemnantReceipt(12), null)
  assert.equal(calls.filter(x => x[0] === 'post').length, 1)
  await assert.rejects(client.postRemnantReceipt(12, original))
  const replay = client.pendingRemnantReceipt(12)
  get = async () => ({ data: { data: { status: 'NOT_FOUND' } } })
  post = async (_url, payload) => { assert.deepEqual(payload, replay.payload); return { data: { receipt_id: 9 } } }
  await client.postRemnantReceipt(12, { warehouse_id: 99, lines: [] })
  assert.equal(client.pendingRemnantReceipt(12), null)
  post = async () => { throw { response: { status: 409 }, errorCode: 'version_conflict' } }
  await assert.rejects(client.postRemnantReceipt(12, original))
  assert.equal(client.pendingRemnantReceipt(12), null)
  post = async () => { throw { response: { status: 409 }, errorCode: 'idempotency_hash_conflict' } }
  await assert.rejects(client.postRemnantReceipt(12, original))
  assert.ok(client.pendingRemnantReceipt(12))
  storage.setItem('erp_user', '{"legacy_id":8}')
  assert.equal(client.pendingRemnantReceipt(12), null)
  assert.equal(client.pendingRemnantReceipt(13), null)
  storage.setItem('erp_user', '{"legacy_id":7}')
  assert.ok(client.pendingRemnantReceipt(12))
  console.log('PASS: lost responses, failed result lookup, successful recovery without repost, exact replay, stale rejection, identity conflict and actor isolation.')
})().catch(error => { console.error(error); process.exitCode = 1 })
