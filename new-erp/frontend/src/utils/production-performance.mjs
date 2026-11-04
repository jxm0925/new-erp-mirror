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
