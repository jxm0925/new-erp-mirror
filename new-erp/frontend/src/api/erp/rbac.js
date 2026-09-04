import api from './master'

export const listPermissions = params => api.get('/v1/erp/rbac/permissions', { params })
export const savePermission = data => api.post('/v1/erp/rbac/permissions', data)
export const listRoles = params => api.get('/v1/erp/rbac/roles', { params })
export const saveRole = data => api.post('/v1/erp/rbac/roles', data)
export const listRoleUsers = (roleId, params = {}) => api.get('/v1/erp/rbac/role-users', { params: { role_id: roleId, ...params } })
export const saveRoleUsers = data => api.post('/v1/erp/rbac/role-users', data)
export const listDepartments = params => api.get('/v1/erp/departments', { params })
export const listDepartmentMembers = (id, params = {}) => api.get(`/v1/erp/departments/${id}/members`, { params })
export const saveDepartmentPrincipals = (id, principalIds) => api.post(`/v1/erp/departments/${id}/principals`, { principal_ids: principalIds })
export const listUsers = params => api.get('/v1/erp/user-directory/users', { params })
export const syncDepartments = () => api.post('/v1/erp/departments/sync')
