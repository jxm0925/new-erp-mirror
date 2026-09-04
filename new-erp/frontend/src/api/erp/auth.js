import api from './master'

export const login = data => api.post('/v1/erp/auth/login', data)
export const me = () => api.get('/v1/erp/auth/me')
export const logout = () => api.post('/v1/erp/auth/logout')

