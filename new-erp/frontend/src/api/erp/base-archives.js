import api, { listEntity, saveEntity, enableEntity, disableEntity, deleteEntity } from './master'

export const listArchives = (kind, params) => {
  if (kind === 'categories') return listEntity('categories', { ...params, category_type: 'product' })
  if (kind === 'carriers') return api.get('/v1/erp/master/carriers', { params })
  return api.get(kind === 'payments' ? '/v1/erp/finance/payment-methods' : '/v1/erp/master/trade-platforms', { params })
}

export const saveArchive = (kind, form) => {
  if (kind === 'categories') return saveEntity('categories', { ...form, category_type: 'product' })
  const { id, ...payload } = form
  const path = kind === 'carriers' ? '/v1/erp/master/carriers' : (kind === 'payments' ? '/v1/erp/finance/payment-methods' : '/v1/erp/master/trade-platforms')
  return id ? api.put(`${path}/${id}`, payload) : api.post(path, payload)
}

export const setArchiveStatus = (kind, row, enabled) => {
  if (kind === 'categories') return (enabled ? enableEntity : disableEntity)('categories', row.id)
  const path = kind === 'carriers' ? '/v1/erp/master/carriers' : (kind === 'payments' ? '/v1/erp/finance/payment-methods' : '/v1/erp/master/trade-platforms')
  return api.post(`${path}/${row.id}/status`, { status: enabled ? 'enabled' : 'disabled', expected_version: row.business_version })
}

export const deleteProductCategory = id => deleteEntity('categories', id)
