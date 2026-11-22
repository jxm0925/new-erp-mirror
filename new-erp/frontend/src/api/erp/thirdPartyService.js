import api from './master'

const BASE = '/v1/erp/system/third-party-services'

export const getAliyunOssConfig = () => api.get(`${BASE}/aliyun-oss`)

export const saveAliyunOssConfig = data => api.put(`${BASE}/aliyun-oss`, data)

export const rotateAliyunOssSecret = data => api.post(`${BASE}/aliyun-oss/rotate-secret`, data)

export const checkAliyunOssConnection = data => api.post(`${BASE}/aliyun-oss/check-connection`, data)

export const decryptAliyunOssSecret = data => api.post(`${BASE}/aliyun-oss/decrypt-secret`, data)

export const getCrmAiConfig = () => api.get(`${BASE}/crm-ai`)

export const saveCrmAiConfig = data => api.put(`${BASE}/crm-ai`, data)

export const listCrmAiModels = data => api.post(`${BASE}/crm-ai/models`, data, { timeout: 30000 })

export const checkCrmAiModel = data => api.post(`${BASE}/crm-ai/check`, data, { timeout: 75000 })

export const decryptCrmAiSecret = data => api.post(`${BASE}/crm-ai/decrypt-secret`, data)

export const setDefaultCrmAi = (serviceCode, data = {}) => api.post(`${BASE}/crm-ai/${serviceCode}/default`, data)

export const deleteCrmAi = serviceCode => api.delete(`${BASE}/crm-ai/${serviceCode}`)
