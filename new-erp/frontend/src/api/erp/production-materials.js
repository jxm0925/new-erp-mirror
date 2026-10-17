import api from './master'

const root = '/v1/erp/production'
export const materialWorkspace = (action, params) => api.get(`${root}/material-picking-workspace/${action}`, { params })
export const previewCuttingSourcePlan = body => api.post(`${root}/material-picking-workspace/cutting-preview`, body)
export const materialDemands = params => api.get(`${root}/material-preparation-demands`, { params })
export const materialList = (kind, params) => api.get(`${root}/${kind === 'delivery' ? 'material-deliveries' : 'material-picking-tasks'}`, { params })
export const materialDetail = (kind, id, params) => api.get(`${root}/${kind === 'delivery' ? 'material-deliveries' : 'material-picking-tasks'}/${id}`, { params })
export const materialCommand = (kind, id, action, body) => api.post(`${root}/${kind === 'delivery' ? 'material-deliveries' : 'material-picking-tasks'}${id ? `/${id}` : ''}${action ? `/${action}` : ''}`, body)
export const materialEvents = (kind, id, params) => api.get(`${root}/material-execution-events/${kind === 'delivery' ? 'delivery' : 'picking_task'}/${id}`, { params })
const laborDocuments = { public_preparation: 'public-material-preparations', picking_task: 'material-picking-tasks', delivery: 'material-deliveries' }
export const materialLaborCommand = (kind, id, action, body) => api.post(`${root}/${laborDocuments[kind]}/${id}/labor/${action}`, body)
export const getProductionLaborSummary = (kind, id, params) => api.get(`${kind === 'sales_order' ? '/v1/erp/sales/orders' : `${root}/work-orders`}/${id}/labor-summary`, { params })
