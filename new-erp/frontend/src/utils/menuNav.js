import { canAccessRoute, routeDeclaresPermission } from './routeAccess'

export const UNGROUPED_MENU_MARKER = '__UNGROUPED__'

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
  const rawGroupName = typeof node.group_name === 'string'
    ? node.group_name.trim()
    : (typeof node.remark === 'string' ? node.remark.trim() : '')
  return {
    name: node.name,
    icon: node.icon,
    path: route.path,
    to: route.fullPath,
    direct: route.path === node.path,
    rawGroupName
  }
}

/**
 * 全模块左侧导航默认二级业务分组定义：
 * - 优先使用后台「系统管理 -> 菜单管理」中配置的 group_name（自定义或修改后的分组名）；
 * - 未单独配置时自动回退到此默认业务分组与排序，未列入此映射的自定义菜单归入「其他功能」。
 */
export const SECTION_GROUPS = {
  production: [
    { name: '工单与车间执行', paths: ['/production/work-orders', '/production/board', '/production/serials'] },
    { name: '技术与绩效统计', paths: ['/production/tech', '/production/drawing-statistics', '/production/performance/orders'] },
    { name: '生产基础配置', paths: ['/production/operations', '/production/routings', '/production/performance/templates'] }
  ],
  master: [
    { name: '商品与物料体系', paths: ['/master/products', '/master/skus', '/master/items', '/master/sku-item-relations'] },
    { name: '往来与基础档案', paths: ['/master/suppliers', '/master/warehouse-locations', '/master/base-archives', '/master/imports'] }
  ],
  purchase: [
    { name: '需求与采购计划', paths: ['/purchase/production-shortages', '/purchase/requests', '/purchase/plans'] },
    { name: '订单与到货退货', paths: ['/purchase/orders', '/purchase/receipts', '/purchase/returns'] }
  ],
  inventory: [
    { name: '出入库与生产供料', paths: ['/inventory/posting', '/inventory/inbound-confirmations', '/inventory/material-deliveries', '/inventory/material-returns'] },
    { name: '库存查询与预警', paths: ['/inventory/balances', '/inventory/transactions', '/inventory/alerts'] },
    { name: '盘点调拨与调整', paths: ['/inventory/stocktakes', '/inventory/transfers', '/inventory/adjustments'] }
  ],
  sales: [
    { name: '工作台与看板', paths: ['/sales/workbench', '/sales/crm-dashboard'] },
    { name: '客户与商机 (CRM)', paths: ['/sales/leads', '/sales/opportunities', '/sales/customers', '/sales/crm-team'] },
    { name: '订单与履约交付', paths: ['/sales/orders', '/sales/shipments', '/sales/returns', '/sales/aftersales'] }
  ],
  finance: [
    { name: '收付款与往来结算', paths: ['/finance/receipts', '/finance/payments', '/finance/payables', '/finance/invoices'] },
    { name: '资金账户与换汇', paths: ['/finance/accounts', '/finance/transfers', '/finance/account-valuations'] },
    { name: '财务统计与规则', paths: ['/finance/statistics', '/finance/funding-policies'] }
  ],
  approval: [
    { name: '待办与审批', paths: ['/approvals/tasks'] },
    { name: '流程与表单配置', paths: ['/approvals/flows', '/approvals/forms'] }
  ],
  system: [
    { name: '组织与账号权限', paths: ['/system/departments', '/system/business-staff', '/system/admins', '/system/roles'] },
    { name: '系统与菜单配置', paths: ['/system/menus', '/system/document-number-rules'] }
  ]
}

export function defaultGroupNameForMenu(sectionCode, path) {
  if (!sectionCode || !path) return ''
  const groups = SECTION_GROUPS[sectionCode] || []
  const matched = groups.find(group => Array.isArray(group.paths) && group.paths.includes(path))
  return matched ? matched.name : ''
}

export function defaultGroupsForSection(sectionCode) {
  return (SECTION_GROUPS[sectionCode] || []).map(group => group.name)
}

function resolveEffectiveGroup(sectionCode, item, hasDefaultGroups) {
  if (item.rawGroupName === UNGROUPED_MENU_MARKER) return ''
  if (item.rawGroupName) return item.rawGroupName
  return defaultGroupNameForMenu(sectionCode, item.path) || (hasDefaultGroups ? '其他功能' : '')
}

function groupSectionItems(sectionCode, dedupedFlat, raw, keep) {
  const groupDefs = SECTION_GROUPS[sectionCode]
  const hasExplicitGroupConfig = dedupedFlat.some(item => !!item.rawGroupName)

  // 当后台尚未配置任何自定义分组时，若模块没有默认分组，沿用原生目录层级；有默认分组则按默认业务分阶段排序展示。
  if (!hasExplicitGroupConfig) {
    if (!groupDefs) {
      return raw.map(item => item.children ? { ...item, children: item.children.filter(keep) } : item)
        .filter(item => item.children ? item.children.length : keep(item))
    }

    const byPath = new Map()
    dedupedFlat.forEach(item => {
      if (!byPath.has(item.path)) byPath.set(item.path, item)
    })
    const usedPaths = new Set()

    const groups = groupDefs.map(group => {
      const children = group.paths.map(path => {
        const item = byPath.get(path)
        if (!item || usedPaths.has(path)) return null
        usedPaths.add(path)
        return item
      }).filter(Boolean)
      return { name: group.name, children }
    }).filter(group => group.children.length)

    const leftover = dedupedFlat.filter(item => !usedPaths.has(item.path))
    if (leftover.length) {
      groups.push({ name: '其他功能', children: leftover })
    }

    // 权限受限角色仅剩单个极短分组时直接平铺，避免仅 1~2 个入口仍显示分组标题。
    if (groups.length === 1 && groups[0].children.length <= 2) {
      return groups[0].children
    }
    return groups
  }

  // 后台已在菜单管理配置了分组名称：按菜单排序权重（dedupedFlat 顺序）与分组名称聚合展示。
  const buckets = []
  const groupMap = new Map()
  dedupedFlat.forEach(item => {
    const groupName = resolveEffectiveGroup(sectionCode, item, !!groupDefs)
    if (!groupName) {
      buckets.push(item)
      return
    }
    let bucket = groupMap.get(groupName)
    if (!bucket) {
      bucket = { name: groupName, children: [], hasExplicit: false }
      groupMap.set(groupName, bucket)
      buckets.push(bucket)
    }
    if (item.rawGroupName && item.rawGroupName !== UNGROUPED_MENU_MARKER) {
      bucket.hasExplicit = true
    }
    bucket.children.push(item)
  })

  // 如果仅剩 1 个未显式命名且条目 <= 2 的默认兜底分组，精简平铺；显式命名的分组始终保留标题。
  if (buckets.length === 1 && buckets[0].children && buckets[0].children.length <= 2 && !buckets[0].hasExplicit) {
    return buckets[0].children
  }
  return buckets.map(bucket => (bucket.children ? { name: bucket.name, children: bucket.children } : bucket))
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
    const dedupedFlat = flat.filter(keep)
    const items = groupSectionItems(node.code, dedupedFlat, raw, keep)
    const firstPath = dedupedFlat.map(item => item.path).find(Boolean) || ''
    return { key: node.code, title: node.name, icon: node.icon || 'el-icon-menu', items, match: firstPath ? `/${firstPath.split('/')[1]}` : `/${node.code}` }
  }).filter(section => section.items.length)
}
