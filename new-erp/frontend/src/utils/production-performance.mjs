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

export function buildPerformanceShares(rows, noncreditedConfirmed, reason) {
  let declared = 0n
  let credited = 0n
  const shares = []
  const seen = new Set()
  for (const row of rows) {
    if (row.eligible === null || row.eligible === undefined || row.eligible === '') {
      if (String(row.percent ?? '').trim() !== '') throw new Error('填写个人份额后，请明确是否计个人绩效')
      continue
    }
    if (typeof row.eligible !== 'boolean') throw new Error('请明确是否计个人绩效')
    const id = Number(row.employee_legacy_id)
    if (!Number.isSafeInteger(id) || id <= 0 || seen.has(id)) throw new Error('参与人员无效或重复')
    seen.add(id)
    const ratio = percentToRatio(row.percent)
    const scaled = ratio === '1.00000000' ? 100000000n : BigInt(ratio.slice(2))
    declared += scaled
    if (row.eligible) credited += scaled
    shares.push({ employee_legacy_id: id, eligible: row.eligible, share_ratio: ratio, remark: String(row.remark || '').trim() || null })
  }
  if (declared > 100000000n) throw new Error('本工序登记的个人份额合计不能超过 100%')
  if (credited < 100000000n && (!noncreditedConfirmed || !String(reason || '').trim())) throw new Error('请确认剩余份额不计个人绩效并填写原因')
  return shares.sort((a, b) => a.employee_legacy_id - b.employee_legacy_id)
}

export function performanceSelectionTotals(rows) {
  let declared = 0
  let credited = 0
  for (const row of rows) {
    if (row.eligible !== true && row.eligible !== false) continue
    try {
      const percent = Number(ratioToPercent(percentToRatio(row.percent)))
      declared += percent
      if (row.eligible) credited += percent
    } catch (_) { /* Invalid entries are reported on confirmation. */ }
  }
  return { declared: Number(declared.toFixed(6)), credited: Number(credited.toFixed(6)), remainder: Number((100 - credited).toFixed(6)) }
}
