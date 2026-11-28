export function poolNames(metadata = {}, scope, current) {
  const source = (metadata.pool_catalog || {})[scope] || metadata.all_customer_pools || metadata.customer_pools || metadata.pools || []
  const names = source.filter(item => typeof item === 'string' || ((!item.status || item.status === 'enabled') && (!scope || !(item.applicable_scopes || []).length || item.applicable_scopes.includes(scope))))
    .map(item => typeof item === 'string' ? item : item.pool_name)
  return [...new Set([...names, current].filter(Boolean))]
}

export function stageOptions(metadata = {}, scope) {
  const catalog = (metadata.stage_catalog || {})[scope] || metadata.nine_step_stages
  if (Array.isArray(catalog)) return catalog.map(item => typeof item === 'string' ? { value: item, label: item } : item)
  const stages = scope === 'opportunity' ? metadata.stages : catalog
  return Object.entries(stages || {}).map(([value, label]) => typeof label === 'object' ? { value, ...label } : { value, label })
}

export function inferTradeType(department = {}, values = []) {
  if (department.trade_type) return department.trade_type
  const name = department.pathLabel || department.department_path || department.department_name || department.name || ''
  const choices = /外贸/.test(name) ? ['外贸', 'foreign'] : /B端|B 端|内贸业务/.test(name) ? ['内贸业务', 'domestic'] : ['内贸销售', 'domestic']
  return choices.find(value => !values.length || values.includes(value)) || choices[0]
}
