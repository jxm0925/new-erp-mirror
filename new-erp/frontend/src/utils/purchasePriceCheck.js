// 采购价格预警的文案：与历史价相比的涨跌说明。
const money = value => Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })

export function priceCheckText(line, unitName = '') {
  if (!line) return ''
  const unit = unitName ? `/${unitName}` : ''
  if (line.level === 'new') return `${line.item_name || line.item_code}：首次采购，暂无历史价可比`
  const source = line.same_supplier ? '该供应商上次' : `其他供应商（${line.last_supplier_name || '-'}）最近`
  const diff = Number(line.diff_percent || 0)
  const trend = diff > 0 ? `高 ${diff}%` : diff < 0 ? `低 ${Math.abs(diff)}%` : '持平'
  return `${line.item_name || line.item_code}：本次 ¥${money(line.current_base_price)}${unit}，比${source}（${line.last_date || '-'}，¥${money(line.last_base_price)}${unit}）${trend}`
}

export function priceWarnings(result) {
  return ((result && result.lines) || []).filter(line => line.level === 'warning')
}
