import api from './master'

export const getAliyunOssConfig = () => api.get('/v1/erp/system/third-party-services/aliyun-oss')

export const saveAliyunOssConfig = data => api.put('/v1/erp/system/third-party-services/aliyun-oss', data)

export const rotateAliyunOssSecret = data => api.post('/v1/erp/system/third-party-services/aliyun-oss/rotate-secret', data)

export const checkAliyunOssConnection = data => api.post('/v1/erp/system/third-party-services/aliyun-oss/check-connection', data)
