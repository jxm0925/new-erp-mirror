import api from './master'

const root = '/v1/erp/production'
export const materialWorkspace = (action, params) => api.get(`${root}/material-picking-workspace/${action}`, { params })
export const materialDemands = params => api.get(`${root}/material-preparation-demands`, { params })
export const materialList = (kind, params) => api.get(`${root}/${kind === 'delivery' ? 'material-deliveries' : 'material-picking-tasks'}`, { params })
export const materialDetail = (kind, id) => api.get(`${root}/${kind === 'delivery' ? 'material-deliveries' : 'material-picking-tasks'}/${id}`)
export const materialCommand = (kind, id, action, body) => api.post(`${root}/${kind === 'delivery' ? 'material-deliveries' : 'material-picking-tasks'}${id ? `/${id}` : ''}${action ? `/${action}` : ''}`, body)
export const materialEvents = (kind, id, params) => api.get(`${root}/material-execution-events/${kind === 'delivery' ? 'delivery' : 'picking_task'}/${id}`, { params })
