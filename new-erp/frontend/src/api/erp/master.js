import axios from 'axios'

// 与 training 项目一致：前端直连后端固定 API 入口，不使用 Vue dev-server 代理。
// VUE_APP_BASE_API 是唯一配置来源，避免迁移目录后仍误连旧 Nginx 站点。
const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout: 20000 })
api.interceptors.request.use(config => {
  const token = localStorage.getItem('erp_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})
api.interceptors.response.use(response => response, error => {
  if (error.response?.status === 401) {
    localStorage.removeItem('erp_token')
    localStorage.removeItem('erp_user')
    localStorage.removeItem('erp_me')
    localStorage.removeItem('erp_permissions')
    if (window.location.hash !== '#/login') window.location.hash = '#/login'
  }
  const validationErrors = error.response?.data?.errors
  const firstValidationMessage = validationErrors && Object.values(validationErrors).flat().find(Boolean)
  const message = firstValidationMessage || error.response?.data?.message || '请求失败，请稍后重试'
  return Promise.reject(Object.assign(error, { userMessage: message }))
})

export const listEntity = (entity, params) => api.get(`/v1/erp/master/${entity}`, { params })
export const getEntity = (entity, id) => api.get(`/v1/erp/master/${entity}/${id}`)
export const uploadProductImage = form => api.post('/v1/erp/master/products/image-upload', form, { headers: { 'Content-Type': 'multipart/form-data' } })
export const uploadSkuImage = form => api.post('/v1/erp/master/skus/image-upload', form, { headers: { 'Content-Type': 'multipart/form-data' } })
export const saveEntity = (entity, data) => data.id
  ? api.put(`/v1/erp/master/${entity}/${data.id}`, data)
  : api.post(`/v1/erp/master/${entity}`, data)
export const disableEntity = (entity, id) => api.post(`/v1/erp/master/${entity}/${id}/disable`)
export const enableEntity = (entity, id) => api.post(`/v1/erp/master/${entity}/${id}/enable`)
export const deleteEntity = (entity, id) => api.delete(`/v1/erp/master/${entity}/${id}`)
export const listItemCategories = params => api.get('/v1/erp/master/item-categories', { params })
export const getItemCategoryTree = () => api.get('/v1/erp/master/item-categories/tree')
export const getItemCategory = id => api.get(`/v1/erp/master/item-categories/${id}`)
export const saveItemCategory = data => data.id
  ? api.put(`/v1/erp/master/item-categories/${data.id}`, data)
  : api.post('/v1/erp/master/item-categories', data)
export const disableItemCategory = id => api.post(`/v1/erp/master/item-categories/${id}/disable`)
export const enableItemCategory = id => api.post(`/v1/erp/master/item-categories/${id}/enable`)
export const deleteItemCategory = id => api.delete(`/v1/erp/master/item-categories/${id}`)
export const reserveDocumentNumber = data => api.post('/v1/erp/document-numbers/reserve', data)
export const listDocumentNumberRules = params => api.get('/v1/erp/document-numbers/rules', { params })
export const listDocumentNumberRuleTypes = () => api.get('/v1/erp/document-numbers/rule-types')
export const previewDocumentNumberRule = data => api.post('/v1/erp/document-numbers/rules/preview', data)
export const createDocumentNumberRule = data => api.post('/v1/erp/document-numbers/rules', data)
export const updateDocumentNumberRule = (id, data) => api.put(`/v1/erp/document-numbers/rules/${id}`, data)
export const enableDocumentNumberRule = id => api.post(`/v1/erp/document-numbers/rules/${id}/enable`)
export const disableDocumentNumberRule = id => api.post(`/v1/erp/document-numbers/rules/${id}/disable`)
export const getSupplierCapabilities = supplierId => api.get(`/v1/erp/master/suppliers/${supplierId}/capabilities`)
export const saveSupplierCategories = (supplierId, data) => api.put(`/v1/erp/master/suppliers/${supplierId}/capabilities/categories`, data)
export const listSupplierItemRelations = (supplierId, params) => api.get(`/v1/erp/master/suppliers/${supplierId}/item-relations`, { params })
export const saveSupplierItemRelation = (supplierId, data) => api.post(`/v1/erp/master/suppliers/${supplierId}/item-relations`, data)
export const disableSupplierItemRelation = (supplierId, relationId, data) => api.post(`/v1/erp/master/suppliers/${supplierId}/item-relations/${relationId}/disable`, data)
export const listSupplierQuotations = (supplierId, params) => api.get(`/v1/erp/master/suppliers/${supplierId}/quotations`, { params })
export const saveSupplierQuotation = (supplierId, data) => api.post(`/v1/erp/master/suppliers/${supplierId}/quotations`, data)
export const disableSupplierQuotation = (supplierId, quotationId, data) => api.post(`/v1/erp/master/suppliers/${supplierId}/quotations/${quotationId}/disable`, data)
export const listSupplierPurchaseHistory = (supplierId, params) => api.get(`/v1/erp/master/suppliers/${supplierId}/purchase-history`, { params })
export const listSupplierRelationHistory = (supplierId, params) => api.get(`/v1/erp/master/suppliers/${supplierId}/relation-history`, { params })
export const listSupplierQuotationHistory = (supplierId, params) => api.get(`/v1/erp/master/suppliers/${supplierId}/quotation-history`, { params })
export const listDefaultSkuItemRelations = params => api.get('/v1/erp/master/sku-item-relations/defaults', { params })
export const getDefaultSkuItemRelation = skuId => api.get(`/v1/erp/master/sku-item-relations/${skuId}`)
export const getSkuItemRelationHistory = skuId => api.get(`/v1/erp/master/sku-item-relations/${skuId}/history`)
export const setDefaultSkuItem = (skuId, data) => api.post(`/v1/erp/master/sku-item-relations/${skuId}/set-primary`, data)
export const auditDefaultSkuItemRelations = params => api.post('/v1/erp/master/sku-item-relations/audit', params)
export const resolveDuplicateSkuItemRelation = (skuId, data) => api.post(`/v1/erp/master/sku-item-relations/${skuId}/resolve-duplicate`, data)
export const removeWrongSkuItemBinding = (skuId, data) => api.post(`/v1/erp/master/sku-item-relations/${skuId}/remove-wrong-binding`, data)
// Compatibility readers used by existing SKU/detail/dashboard areas. New Stage 4 pages use the explicit APIs above.
export const listRelations = params => api.get('/v1/erp/master/sku-item-relations', { params })
export const replacePrimaryRelation = data => api.post('/v1/erp/master/sku-item-relations/replace-primary', data)
export const listItemPurchaseConversions = (itemId, params) => api.get(`/v1/erp/master/items/${itemId}/purchase-conversions`, { params })
export const listItemPurchaseConversionOptions = (itemId, params) => api.get(`/v1/erp/master/items/${itemId}/purchase-conversions/options`, { params })
export const saveItemPurchaseConversion = (itemId, data) => data.id
  ? api.put(`/v1/erp/master/items/${itemId}/purchase-conversions/${data.id}`, data)
  : api.post(`/v1/erp/master/items/${itemId}/purchase-conversions`, data)
export const disableItemPurchaseConversion = (itemId, id, data) => api.post(`/v1/erp/master/items/${itemId}/purchase-conversions/${id}/disable`, data)
export const getItemMaterialPolicy = itemId => api.get(`/v1/erp/master/items/${itemId}/material-policy`)
export const getItemIntegratedForm = itemId => api.get(`/v1/erp/master/items/${itemId}/integrated-form`)
export const saveItemIntegratedForm = (itemId, data) => itemId
  ? api.put(`/v1/erp/master/items/${itemId}/integrated-form`, data)
  : api.post('/v1/erp/master/items/integrated-form', data)
export const listItemMaterialPolicyHistory = (itemId, params) => api.get(`/v1/erp/master/items/${itemId}/material-policy/history`, { params })
export const saveItemMaterialPolicyDraft = (itemId, data) => api.put(`/v1/erp/master/items/${itemId}/material-policy/draft`, data)
export const activateItemMaterialPolicy = (itemId, data) => api.post(`/v1/erp/master/items/${itemId}/material-policy/activate`, data)
export const uploadImport = form => api.post('/v1/erp/master/imports/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } })
export const previewImport = id => api.post(`/v1/erp/master/imports/${id}/preview`)
export const importRows = (id, params) => api.get(`/v1/erp/master/imports/${id}/rows`, { params })
export const confirmImport = id => api.post(`/v1/erp/master/imports/${id}/confirm`)
export const errorExportUrl = id => `${api.defaults.baseURL}/v1/erp/master/imports/${id}/errors/export`

export default api
