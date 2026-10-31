// 页面访问判断：路由守卫和左侧菜单共用同一套规则，菜单能看到的入口一定能打开，能打开的页面菜单里也有入口。
export function sessionAccess() {
  return {
    profile: JSON.parse(localStorage.getItem('erp_me') || '{}'),
    permissions: JSON.parse(localStorage.getItem('erp_permissions') || '[]')
  }
}

/** 路由上是否声明了权限要求（没有声明的页面，菜单可见性改看后台菜单授权）。 */
export function routeDeclaresPermission(route) {
  return route.matched.some(record => record.meta.permission || (record.meta.allPermissions || []).length)
}

export function canAccessRoute(route, { profile, permissions }) {
  if (profile.is_super_admin) return true
  const required = route.matched.map(record => record.meta.permission).find(Boolean)
  const all = route.matched.flatMap(record => record.meta.allPermissions || [])
  if (!all.every(code => permissions.includes(code))) return false
  if (!required) return true
  return Array.isArray(required) ? required.some(code => permissions.includes(code)) : permissions.includes(required)
}
