import { canAccessRoute, routeDeclaresPermission } from './routeAccess'

function leaves(nodes) {
  return (nodes || []).reduce((list, node) => list.concat(node.children && node.children.length ? leaves(node.children) : (node.path ? [node] : [])), [])
}

// 菜单路径解析成真实页面（已合并的旧入口跟着跳转、带上查询参数）；可见与否和路由守卫同一规则，路由没声明权限时看后台菜单授权。
function entry(node, router, access) {
  if (!node.path) return null
  const route = router.resolve(node.path).route
  if (!route.matched.length || route.path === '/console') return null
  const visible = routeDeclaresPermission(route) ? canAccessRoute(route, access) : (!!access.profile.is_super_admin || node.granted !== false)
  if (!visible) return null
  return { name: node.name, icon: node.icon, path: route.path, to: route.fullPath, direct: route.path === node.path }
}

/**
 * 左侧导航：后台菜单树（一级是模块）→ 分区、条目、分组。
 * 已合并的旧入口（物料类目、供应商往来、开票申请等）解析到合并后的页面；同一页面只留一个入口，优先保留页面本身的菜单。
 */
export function buildMenuSections(menus, router, access) {
  return (menus || []).map(node => {
    const nodes = node.children && node.children.length ? node.children : [node]
    const raw = nodes.map(child => child.children && child.children.length
      ? { name: child.name, children: leaves(child.children).map(leaf => entry(leaf, router, access)).filter(Boolean) }
      : entry(child, router, access)).filter(Boolean)
    const flat = raw.flatMap(item => item.children || [item])
    const seen = {}
    flat.forEach(item => { if (!seen[item.path] || (item.direct && !seen[item.path].direct)) seen[item.path] = item })
    const keep = item => seen[item.path] === item
    const items = raw.map(item => item.children ? { ...item, children: item.children.filter(keep) } : item)
      .filter(item => item.children ? item.children.length : keep(item))
    const firstPath = flat.map(item => item.path).find(Boolean) || ''
    return { key: node.code, title: node.name, icon: node.icon || 'el-icon-menu', items, match: firstPath ? `/${firstPath.split('/')[1]}` : `/${node.code}` }
  }).filter(section => section.items.length)
}
