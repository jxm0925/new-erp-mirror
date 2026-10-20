import { createErpApi } from './http'
import { executeProductionDecision } from './production-decisions'

const api = createErpApi({ fallbackMessage: '工艺档案操作失败' })
const catalogPath = type => '/v1/erp/production/' + type

export const listProcessCatalog = (type, params) => api.get(catalogPath(type), { params })
export const createProcessCatalog = (type, payload) => api.post(catalogPath(type), payload)
export const updateProcessCatalog = (type, id, payload) => api.put(catalogPath(type) + '/' + id, payload)
export const saveProcessCatalog = (type, id, payload, creationId) => executeProductionDecision(
  'catalog_' + type + '_' + (id || creationId), payload,
  data => id ? updateProcessCatalog(type, id, data) : createProcessCatalog(type, data)
)
