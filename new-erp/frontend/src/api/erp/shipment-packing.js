import api from './master'

export const shipmentCommandId = () => `packing-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
const pendingKey = (kind, id) => {
  let actor = {}
  try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}') } catch (_) { /* No valid saved profile. */ }
  return `erp_packing_pending:${process.env.VUE_APP_BASE_API}:${actor.legacy_id || actor.id || ''}:${kind}:${id}`
}
export const pendingPackingCommand = (kind, id) => {
  try { return JSON.parse(localStorage.getItem(pendingKey(kind, id)) || 'null') } catch (_) { return null }
}
const inflight = new Map()
const packingCommand = (kind, id, path, payload) => {
  const key = pendingKey(kind, id)
  if (inflight.has(key)) return inflight.get(key)
  const old = pendingPackingCommand(kind, id)
  const job = old || { path, payload: JSON.parse(JSON.stringify({ ...payload, client_command_id: shipmentCommandId() })) }
  if (!old) localStorage.setItem(key, JSON.stringify(job))
  const operation = Promise.resolve().then(async () => {
    if (old) {
      const { data } = await api.get('/v1/erp/production/shipment-packing/commands/result', { params: { client_command_id: job.payload.client_command_id } })
      if (pendingKey(kind, id) !== key) throw Object.assign(new Error('登录账号已变化，请重新打开当前页面。'), { userMessage: '登录账号已变化，请重新打开当前页面。' })
      if (data.data.status === 'SUCCEEDED') { localStorage.removeItem(key); return { data: { data: data.data.response } } }
    }
    const response = await api.post(job.path, job.payload)
    if (pendingKey(kind, id) !== key) throw Object.assign(new Error('登录账号已变化，请重新打开当前页面。'), { userMessage: '登录账号已变化，请重新打开当前页面。' })
    localStorage.removeItem(key)
    return response
  }).catch(error => {
    if (error.response && (error.response.status === 422 || error.response.status === 409 && error.response.data?.error_code === 'packing_routing_changed')) localStorage.removeItem(key)
    error.pendingCommand = Boolean(localStorage.getItem(key))
    throw error
  }).finally(() => inflight.delete(key))
  inflight.set(key, operation)
  return operation
}
export const listShipments = params => api.get('/v1/erp/sales/shipments', { params })
export const getShipment = id => api.get(`/v1/erp/sales/shipments/${id}`)
export const createShipment = data => api.post('/v1/erp/sales/shipments', data)
export const actShipment = (id, action, data = {}) => api.post(`/v1/erp/sales/shipments/${id}/${action}`, data)
export const listShipmentSources = params => api.get('/v1/erp/sales/shipments/sources', { params })
export const listShipmentSourceSerials = (id, params) => api.get(`/v1/erp/sales/shipments/sources/${id}/serials`, { params })
export const getShipmentPacking = (id, params) => api.get(`/v1/erp/sales/shipments/${id}/packing`, { params })
export const configureShipmentPacking = (id, data) => packingCommand('configure', id, `/v1/erp/sales/shipments/${id}/packing`, data)
export const listPackingOperations = params => api.get('/v1/erp/production/shipment-packing/operations', { params })
export const getPackingOperation = (id, params) => api.get(`/v1/erp/production/shipment-packing/operations/${id}`, { params })
export const listPackingRoutings = (id, params) => api.get(`/v1/erp/sales/shipments/${id}/packing/routings`, { params })
export const actPackingOperation = (id, data) => packingCommand('operation', id, `/v1/erp/production/shipment-packing/operations/${id}/actions`, data)
export const listPackingPeople = (id, params) => api.get(`/v1/erp/production/shipment-packing/operations/${id}/people`, { params })
export const listPackingMaterials = (id, params) => api.get(`/v1/erp/production/shipment-packing/operations/${id}/material-sources`, { params })
export const listPackingIdentities = (id, params) => api.get(`/v1/erp/production/shipment-packing/operations/${id}/material-identities`, { params })
