import api from './master'

export const getCrmViewMetadata = params => api.get('/v1/erp/crm/saved-views/metadata', { params })
export const listCrmViews = params => api.get('/v1/erp/crm/saved-views', { params })
export const getCrmView = id => api.get(`/v1/erp/crm/saved-views/${id}`)
export const createCrmView = data => api.post('/v1/erp/crm/saved-views', data)
export const updateCrmView = (id, data) => api.put(`/v1/erp/crm/saved-views/${id}`, data)
export const deleteCrmView = (id, params) => api.delete(`/v1/erp/crm/saved-views/${id}`, { params })

const selectionKey = resource => {
  const user = JSON.parse(localStorage.getItem('erp_user') || '{}')
  const id = user.legacy_id || user.id
  return id ? `erp:crm-view:${process.env.VUE_APP_BASE_API || ''}:${id}:${resource}` : null
}

export const rememberedCrmView = resource => {
  try {
    const key = selectionKey(resource)
    const id = key && localStorage.getItem(key)
    return id && /^\d+$/.test(id) ? Number(id) : null
  } catch (_) { return null }
}

export const rememberCrmView = (resource, id) => {
  try {
    const key = selectionKey(resource)
    if (key) {
      if (id) localStorage.setItem(key, String(id))
      else localStorage.removeItem(key)
    }
  } catch (_) { /* 服务端视图仍可正常使用。 */ }
}
