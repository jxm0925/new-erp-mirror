// Percent inputs remain strings so a confirmed zero is distinct from an empty field.
export function percentToRatio(value) {
  const text = String(value ?? '').trim()
  if (!/^\d+(\.\d{1,6})?$/.test(text)) throw new Error('请填写 0% 到 100% 的个人份额，最多六位小数')
  const [whole, decimals = ''] = text.split('.')
  const scaled = BigInt(whole) * 1000000n + BigInt(decimals.padEnd(6, '0'))
  if (scaled > 100000000n) throw new Error('个人份额不能超过 100%')
  return scaled === 100000000n ? '1.00000000' : '0.' + String(scaled).padStart(8, '0')
}

export function ratioToPercent(value) {
  if (value === null || value === undefined || value === '') return ''
  const text = String(value)
  if (!/^\d+(\.\d+)?$/.test(text)) return ''
  const [whole, decimals = ''] = text.split('.')
  const scaled = BigInt(whole) * 100000000n + BigInt(decimals.slice(0, 8).padEnd(8, '0'))
  const integer = scaled / 1000000n
  const remainder = String(scaled % 1000000n).padStart(6, '0').replace(/0+$/, '')
  return String(integer) + (remainder ? '.' + remainder : '')
}

export function buildPerformanceShares(rows) {
  let declared = 0n
  const shares = []
  const seen = new Set()
  if (!rows.length) throw new Error('请确认真实参与人员')
  for (const row of rows) {
    const id = Number(row.employee_legacy_id)
    if (!Number.isSafeInteger(id) || id <= 0 || seen.has(id)) throw new Error('参与人员无效或重复')
    seen.add(id)
    const ratio = percentToRatio(row.percent)
    const scaled = ratio === '1.00000000' ? 100000000n : BigInt(ratio.slice(2))
    declared += scaled
    // This creates a new allocation version. Historical eligibility remains in
    // its original saved assignment and is never rewritten while viewing it.
    shares.push({ employee_legacy_id: id, eligible: true, share_ratio: ratio, remark: String(row.remark || '').trim() || null })
  }
  if (declared !== 100000000n) throw new Error('本工序个人份额合计必须等于 100%')
  return shares.sort((a, b) => a.employee_legacy_id - b.employee_legacy_id)
}

export function defaultPerformanceShares(participants) {
  const ids = [...new Set(participants.map(row => Number(row.employee_legacy_id ?? row.legacy_id)))].sort((a, b) => a - b)
  if (!ids.length || ids.some(id => !Number.isSafeInteger(id) || id <= 0)) return []
  const portion = 100000000n / BigInt(ids.length)
  return ids.map((id, index) => {
    const value = index === ids.length - 1 ? 100000000n - portion * BigInt(index) : portion
    return { employee_legacy_id: id, share_ratio: value === 100000000n ? '1.00000000' : '0.' + String(value).padStart(8, '0') }
  })
}

export function personalShareGroups(scopes, selectedPeople = [], previousGroups = []) {
  return scopes.filter(scope => scope.can_plan).map(scope => {
    const people = new Map((scope.participants || []).map(person => [Number(person.employee_legacy_id), { ...person }]))
    selectedPeople.forEach(person => {
      const id = Number(person.employee_legacy_id ?? person.user_id ?? person.legacy_id)
      if (!Number.isSafeInteger(id) || id <= 0) throw new Error('协同人员必须引用真实账号')
      if (!people.has(id)) people.set(id, { employee_legacy_id: id, employee_name: person.display_name || person.nickname || person.username || person.employee_name || `账号 #${id}`, identity_exists: true, is_active: true })
    })
    const rows = [...people.values()].sort((a, b) => Number(a.employee_legacy_id) - Number(b.employee_legacy_id))
    const ids = rows.map(row => Number(row.employee_legacy_id)).join(',')
    const prior = previousGroups.find(group => group.scope_type === scope.scope_type && Number(group.scope_id) === Number(scope.scope_id))
    const priorMatches = prior && prior.rows.map(row => Number(row.employee_legacy_id)).sort((a, b) => a - b).join(',') === ids
    const saved = scope.share_plan && scope.share_plan.shares || []
    const savedMatches = saved.map(row => Number(row.employee_legacy_id)).sort((a, b) => a - b).join(',') === ids
    const shares = savedMatches ? saved : defaultPerformanceShares(rows)
    return { scope_type: scope.scope_type, scope_id: Number(scope.scope_id), scope_version: Number(scope.scope_version),
      assignment_version: Number(scope.share_plan && scope.share_plan.version_no || 0), name: scope.scope_name,
      owner_legacy_id: Number(scope.owner_legacy_id), rows: rows.map(person => ({ ...person,
        percent: priorMatches ? prior.rows.find(row => Number(row.employee_legacy_id) === Number(person.employee_legacy_id)).percent
          : ratioToPercent((shares.find(row => Number(row.employee_legacy_id) === Number(person.employee_legacy_id)) || {}).share_ratio) })) }
  })
}

export function buildPersonalScopeShares(groups) {
  if (!groups.length) throw new Error('没有可确认个人份额的真实工序，请刷新任务')
  return groups.map(group => ({ scope_type: group.scope_type, scope_id: group.scope_id,
    expected_scope_version: group.scope_version, expected_assignment_version: group.assignment_version,
    shares: buildPerformanceShares(group.rows) }))
}

export function restorePersonalShareGroups(scopes, plans, ownerId) {
  return (plans || []).map(plan => {
    const scope = scopes.find(row => row.scope_type === plan.scope_type && Number(row.scope_id) === Number(plan.scope_id))
    return { scope_type: plan.scope_type, scope_id: Number(plan.scope_id), scope_version: Number(plan.expected_scope_version),
      assignment_version: Number(plan.expected_assignment_version), owner_legacy_id: Number(scope && scope.owner_legacy_id || ownerId),
      name: scope && scope.scope_name || `工序 ${plan.scope_id}`, rows: plan.shares.map(share => {
        const person = scope && (scope.participants || []).find(row => Number(row.employee_legacy_id) === Number(share.employee_legacy_id))
        return { ...(person || {}), employee_legacy_id: Number(share.employee_legacy_id),
          employee_name: person && person.employee_name || `账号 #${share.employee_legacy_id}`, percent: ratioToPercent(share.share_ratio) }
      }) }
  })
}

export function buildPerformanceRatePayload(scope, percent, reason, clientCommandId) {
  if (!['unit_operation', 'quantity_operation', 'shipment_packing_operation'].includes(scope?.scope_type)
    || !Number.isSafeInteger(Number(scope.scope_id)) || Number(scope.scope_id) <= 0
    || !Number.isSafeInteger(Number(scope.scope_version)) || Number(scope.scope_version) < 1
    || !Number.isSafeInteger(Number(scope.rate_version ?? 0)) || Number(scope.rate_version ?? 0) < 0) throw new Error('工序版本资料不完整，请刷新后重试')
  const explanation = String(reason || '').trim()
  if (!explanation || explanation.length > 500) throw new Error('请填写调整原因，最多 500 字')
  let rate
  try { rate = percentToRatio(percent) } catch (_) { throw new Error('请填写 0% 到 100% 的工序比例，最多六位小数') }
  return { client_command_id: clientCommandId, expected_scope_version: Number(scope.scope_version),
    expected_rate_version: Number(scope.rate_version ?? 0), performance_rate: rate, reason: explanation }
}

export function performanceSelectionTotals(rows) {
  let declared = 0
  let credited = 0
  for (const row of rows) {
    try {
      const percent = Number(ratioToPercent(percentToRatio(row.percent)))
      declared += percent
      if (row.eligible !== false) credited += percent
    } catch (_) { /* Invalid entries are reported on confirmation. */ }
  }
  return { declared: Number(declared.toFixed(6)), credited: Number(credited.toFixed(6)), remainder: Number((100 - credited).toFixed(6)) }
}
