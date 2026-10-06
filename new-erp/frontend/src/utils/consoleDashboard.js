export function consoleTime(value, timezone = 'Asia/Shanghai') {
  if (!value) return '--'
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return '--'
  return new Intl.DateTimeFormat('sv-SE', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date)
}

export function consoleWait(value, now = Date.now()) {
  const time = value && new Date(value).getTime()
  if (!time || !Number.isFinite(time)) return '--'
  const hours = Math.max(0, Math.floor((now - time) / 3600000))
  return hours < 1 ? '不足1小时' : hours < 24 ? `${hours}小时` : `${Math.floor(hours / 24)}天`
}

export function consoleTrend(rows) {
  const peak = Math.max(...(rows || []).map(r => Number(r.count)), 1)
  return (rows || []).map(r => ({ ...r, height: r.count > 0 ? Math.max(8, Math.round(r.count / peak * 92)) : 0 }))
}

export function consoleAmounts(rows) {
  if (rows === null || rows === undefined) return null
  return rows.length ? rows.map(r => `${r.currency || '未指定币种'} ${Number(r.amount).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`).join('；') : '0'
}

export function consoleQuantities(rows) {
  if (rows === null || rows === undefined) return null
  return rows.length ? rows.map(r => `${Number(r.quantity).toLocaleString('zh-CN', { maximumFractionDigits: 8 })} ${r.unit_name || '未指定单位'}`).join('；') : '0'
}
