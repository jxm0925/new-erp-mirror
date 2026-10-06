export const newSystemCommand = () => {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()
  return `system-${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
}

// Keep the same command after an uncertain network result. Edited content gets
// a new command, so retries cannot duplicate a create or replay another payload.
export const systemFormCommand = (previous, payload, initial = newSystemCommand()) => {
  const signature = JSON.stringify(payload)
  return previous && previous.signature === signature ? previous : { signature, id: previous ? newSystemCommand() : initial }
}

export const parseSystemArray = value => {
  if (Array.isArray(value)) return value
  try {
    const parsed = JSON.parse(value || '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch (_) { return [] }
}

// Tree mode is explicit for organization selectors. Orphan historic nodes stay
// visible, and malformed cycles cannot recurse indefinitely or hide every node.
export const departmentTree = (rows, excludedId = null) => {
  const excluded = new Set(excludedId ? [Number(excludedId)] : [])
  let changed = true
  while (changed) {
    changed = false
    rows.forEach(row => {
      if (excluded.has(Number(row.parent_legacy_id)) && !excluded.has(Number(row.legacy_id))) {
        excluded.add(Number(row.legacy_id)); changed = true
      }
    })
  }
  const map = new Map(rows.filter(row => !excluded.has(Number(row.legacy_id))).map(row => [Number(row.legacy_id), row]))
  const visited = new Set()
  const build = (row, ancestry = new Set()) => {
    const id = Number(row.legacy_id)
    visited.add(id)
    const path = new Set([...ancestry, id])
    const children = rows.filter(item => map.has(Number(item.legacy_id)) && Number(item.parent_legacy_id) === id && !path.has(Number(item.legacy_id))).map(item => build(item, path))
    return { ...row, value: id, label: row.name, ...(children.length ? { children } : {}) }
  }
  const roots = [...map.values()].filter(row => !map.has(Number(row.parent_legacy_id)) || Number(row.legacy_id) === Number(row.parent_legacy_id)).map(row => build(row))
  for (const row of map.values()) if (!visited.has(Number(row.legacy_id))) roots.push(build(row))
  return roots
}

export const flatDepartmentTree = rows => {
  const result = []
  const flatten = (node, depth) => {
    result.push({ ...node, depth })
    ;(node.children || []).forEach(child => flatten(child, depth + 1))
  }
  departmentTree(rows).forEach(node => flatten(node, 0))
  return result
}

export const systemMutation = (row, command = newSystemCommand()) => ({ expected_version: Number(row.business_version || 1), client_command_id: command })

export const systemTime = value => value ? String(value).replace('T', ' ').slice(0, 19) : '-'

export const permissionClosure = (ids, permissions) => {
  const map = new Map(permissions.map(item => [Number(item.id), item]))
  const selected = new Set(ids.map(Number))
  for (const id of [...selected]) {
    let row = map.get(id)
    const visited = new Set([id])
    while (row && row.parent_id && !visited.has(Number(row.parent_id))) {
      const parent = Number(row.parent_id)
      if (!map.has(parent)) break
      selected.add(parent); visited.add(parent); row = map.get(parent)
    }
  }
  return [...selected].sort((a, b) => a - b)
}
