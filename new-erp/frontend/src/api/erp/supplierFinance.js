import api from './master'

export const listSupplierFinanceStatistics = params => api.get('/v1/erp/finance/supplier-finance/statistics', { params })
export const listSupplierFinanceEntries = (supplierId, params) => api.get(`/v1/erp/finance/supplier-finance/${supplierId}/entries`, { params })
