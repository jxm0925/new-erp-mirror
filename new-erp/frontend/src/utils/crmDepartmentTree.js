export function crmDepartmentTree(departments = []) {
  const byId = new Map()
  departments.forEach(department => {
    const id = Number(department.id)
    if (!Number.isInteger(id) || id <= 0 || byId.has(id)) return
    const parentId = Number(department.parent_id)
    byId.set(id, {
      ...department,
      id,
      parent_id: Number.isInteger(parentId) && parentId > 0 ? parentId : 0,
      name: String(department.name || '')
    })
  })

  const children = new Map()
  const roots = []
  byId.forEach(department => {
    if (department.parent_id !== department.id && byId.has(department.parent_id)) {
      if (!children.has(department.parent_id)) children.set(department.parent_id, [])
      children.get(department.parent_id).push(department)
    } else {
      roots.push(department)
    }
  })

  const result = []
  const visited = new Set()
  const append = (department, depth = 0, parentPath = '', isLast = true) => {
    if (visited.has(department.id)) return
    visited.add(department.id)
    const pathLabel = parentPath ? `${parentPath} / ${department.name}` : department.name
    const childRows = (children.get(department.id) || []).filter(child => !visited.has(child.id))
    result.push({
      ...department,
      depth,
      hasChildren: childRows.length > 0,
      isLast,
      branchPrefix: depth ? (isLast ? '└─ ' : '├─ ') : '',
      pathLabel
    })
    childRows.forEach((child, index) => append(child, depth + 1, pathLabel, index === childRows.length - 1))
  }
  roots.forEach(department => append(department))
  // 旧组织数据可能失父或成环，剩余节点独立展开，避免选项丢失或无限递归。
  byId.forEach(department => append(department))
  const rootRows = result.filter(department => department.depth === 0)
  rootRows.forEach((department, index) => { department.isLast = index === rootRows.length - 1 })
  return result
}
