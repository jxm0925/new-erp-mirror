import api from './master'
import { completionCommandStore } from '../../utils/completionCommand'

const inflight = new Map()

function actorId() {
  const user = JSON.parse(localStorage.getItem('erp_user') || '{}')
  const id = Number(user.legacy_id || user.id || 0)
  if (!id || !localStorage.getItem('erp_token')) throw new Error('请先登录 ERP')
  return id
}

function commandStore(action, id) {
  return completionCommandStore(localStorage, `erp_warehouse_pending:${encodeURIComponent(process.env.VUE_APP_BASE_API || '')}:${actorId()}:${action}:${id}`)
}

export function pendingWarehouseCommands(action) {
  const prefix = `erp_warehouse_pending:${encodeURIComponent(process.env.VUE_APP_BASE_API || '')}:${actorId()}:${action}:`
  return Object.keys(localStorage).filter(key => key.startsWith(prefix)).map(key => completionCommandStore(localStorage, key).read()).filter(Boolean)
}

export function pendingWarehouseCommand(action, id) {
  return commandStore(action, id).read()
}

// Keep the original command until the ledger proves its result; a changed form cannot replace an uncertain write.
export function submitWarehouseCommand(action, id, payload = {}) {
  const actor = actorId()
  const key = `${actor}:${action}:${id}`
  if (inflight.has(key)) return inflight.get(key)
  const store = commandStore(action, id)
  const previous = store.read()
  const job = previous || store.prepare({ id: Number(id), action, payload: { ...payload,
    client_command_id: 'warehouse-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12) } })
  let posting = false
  const sameActor = () => { if (actorId() !== actor) throw new Error('登录账号已变化，请重新打开当前单据') }
  const finish = response => {
    sameActor()
    if (response.action !== job.action || Number(response.aggregate_id) !== Number(job.id)) throw new Error('返回结果与当前操作不一致，请重新核对')
    store.clear()
    return response.result
  }
  const operation = Promise.resolve().then(async () => {
    if (previous) {
      const { data } = await api.get('/v1/erp/inventory/warehouse-commands/result', { params: { client_command_id: job.payload.client_command_id } })
      const state = data.data || data
      if (state.status === 'SUCCEEDED') return finish(state.response)
      if (state.status === 'FAILED') {
        sameActor()
        store.clear()
        const failure = state.response || {}
        throw Object.assign(new Error(failure.message || '原操作未成功，请调整后重新提交'), {
          userMessage: failure.message, errorCode: failure.error_code,
          response: { status: failure.status, data: failure.details || {} }
        })
      }
      if (state.status !== 'NOT_FOUND') throw new Error('操作正在处理，请稍后核对结果')
    }
    sameActor()
    posting = true
    const { client_command_id, ...data } = job.payload
    const response = await api.post('/v1/erp/inventory/warehouse-commands', {
      action: job.action, aggregate_id: job.id, client_command_id, payload: data
    })
    return finish(response.data.data || response.data)
  }).catch(error => {
    if (!previous && posting && [409, 422].includes(error.response?.status)
        && !['idempotency_hash_conflict', 'command_processing'].includes(error.errorCode)) store.clear()
    error.pendingCommand = Boolean(store.read())
    error.userMessage = error.userMessage || error.message
    throw error
  }).finally(() => inflight.delete(key))
  inflight.set(key, operation)
  return operation
}

export const listWarehouseQueue = params => api.get('/v1/erp/inventory/warehouse-workspace', { params })
export const getWarehouseDocument = (kind, id, params) => api.get(`/v1/erp/inventory/warehouse-workspace/documents/${kind}/${id}`, { params })
export const resolveWarehouseScan = code => api.get('/v1/erp/inventory/scan/resolve', { params: { code: String(code || '').trim() } })
export const getWarehouseLocators = params => api.get('/v1/erp/inventory/warehouse-workspace/locators', { params })
