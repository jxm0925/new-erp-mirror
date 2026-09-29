import api from './cutting'
import { completionCommandStore, completionErrorIsDefinitive } from '../../utils/completionCommand'

const base = id => `/v1/erp/production/cutting/orders/${id}`
export const listRemnants = (id, params) => api.get(`${base(id)}/remnants`, { params })
export const getRemnantReceipt = (id, receipt) => api.get(`${base(id)}/remnant-receipts/${receipt}`)
const store = id => {
  const actor = JSON.parse(localStorage.getItem('erp_user') || '{}') || {}
  if (!actor.legacy_id && !actor.id) throw new Error('登录信息不完整，请重新登录后办理入库。')
  return completionCommandStore(localStorage, `remnant-receipt:${actor.legacy_id || actor.id}:${id}`)
}
export const pendingRemnantReceipt = id => store(id).read()
export const postRemnantReceipt = async (id, payload) => {
  const commands = store(id)
  const previous = commands.read()
  const job = commands.prepare(previous || { id, payload: { ...payload, client_command_id: `remnant-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
  let posting = false
  try {
    // Query the old command before replay. Keep its rows, versions and location unchanged
    // across page reloads or uncertain responses; a new ID could duplicate a successful post.
    if (previous) {
      const { data } = await api.get(`${base(id)}/remnant-receipt-command`, { params: { client_command_id: job.payload.client_command_id } })
      if (data.data.status === 'SUCCEEDED') { commands.clear(); return { data: { data: data.data.result } } }
      if (data.data.status !== 'NOT_FOUND') throw new Error('原入库请求仍在处理中，请稍后继续查询。')
    }
    posting = true
    const response = await api.post(`${base(id)}/remnant-receipts`, job.payload)
    commands.clear()
    return response
  } catch (error) {
    // A failed status lookup does not establish whether the original write succeeded.
    if (posting && completionErrorIsDefinitive(error) && error.errorCode !== 'idempotency_hash_conflict') commands.clear()
    throw error
  }
}
