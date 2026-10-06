import api from './master'

export const getConsoleDashboard = params => api.get('/v1/erp/console/dashboard', { params })
