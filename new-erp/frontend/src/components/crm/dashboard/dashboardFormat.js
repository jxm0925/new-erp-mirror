export const countText = value => value === null || value === undefined ? '—' : Number(value).toLocaleString('zh-CN')
export const amountText = (value, currency = '', unit = 10000) => value === null || value === undefined || value === '' ? '待核实' : `${currency ? currency + ' ' : ''}${(Number(value) / unit).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}${unit === 10000 ? '万' : ''}`
export function currencyAmount(summary, key, currency) {
  const rows = (summary || {}).currencies || []
  const row = rows.find(item => item.currency === currency)
  return row ? row[key] : null
}
export function moneyText(summary, key, currency) {
  if (!summary) return '—'
  const rows = (summary.currencies || []).filter(item => !currency || item.currency === currency)
  return rows.length ? rows.map(item => amountText(item[key], item.currency)).join(' / ') : summary.order_count === 0 ? '0' : '—'
}
export const pageState = () => ({ data: null, loading: false, error: '', sequence: 0, page: 1, per_page: 10 })
export const pageRows = state => (state.data || {}).data || []
export const pageTotal = state => (state.data || {}).total || 0
