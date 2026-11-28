import { listCrmCustomerPools, saveCrmCustomerPool, setCrmCustomerPoolStatus } from './crm'
import api, { listEntity, saveEntity, enableEntity, disableEntity, deleteEntity } from './master'

export const listBusinessLines = params => api.get('/v1/erp/master/business-lines', { params })
export const createBusinessLine = payload => api.post('/v1/erp/master/business-lines', payload)
export const updateBusinessLine = (id, payload) => api.put(`/v1/erp/master/business-lines/${id}`, payload)
export const setBusinessLineStatus = (row, enabled) => api.post(`/v1/erp/master/business-lines/${row.id}/status`, { status: enabled ? 'enabled' : 'disabled', expected_version: row.business_version })

export const listArchives = (kind, params) => {
  if (kind === 'categories') return listEntity('categories', { ...params, category_type: 'product' })
  if (kind === 'customer-pools') return listCrmCustomerPools(params)
  if (kind === 'business-lines') return listBusinessLines(params)
  if (kind === 'carriers') return api.get('/v1/erp/master/carriers', { params })
  return api.get(kind === 'payments' ? '/v1/erp/finance/payment-methods' : '/v1/erp/master/trade-platforms', { params })
}

export const saveArchive = (kind, form) => {
  if (kind === 'categories') return saveEntity('categories', { ...form, category_type: 'product' })
  const { id, ...payload } = form
  if (kind === 'customer-pools') return saveCrmCustomerPool({ id, ...payload })
  if (kind === 'business-lines') return id ? updateBusinessLine(id, payload) : createBusinessLine(payload)
  const path = kind === 'carriers' ? '/v1/erp/master/carriers' : (kind === 'payments' ? '/v1/erp/finance/payment-methods' : '/v1/erp/master/trade-platforms')
  return id ? api.put(`${path}/${id}`, payload) : api.post(path, payload)
}

export const setArchiveStatus = (kind, row, enabled) => {
  if (kind === 'categories') return (enabled ? enableEntity : disableEntity)('categories', row.id)
  if (kind === 'customer-pools') return setCrmCustomerPoolStatus(row, enabled ? 'enabled' : 'disabled')
  if (kind === 'business-lines') return setBusinessLineStatus(row, enabled)
  const path = kind === 'carriers' ? '/v1/erp/master/carriers' : (kind === 'payments' ? '/v1/erp/finance/payment-methods' : '/v1/erp/master/trade-platforms')
  return api.post(`${path}/${row.id}/status`, { status: enabled ? 'enabled' : 'disabled', expected_version: row.business_version })
}

export const deleteProductCategory = id => deleteEntity('categories', id)
