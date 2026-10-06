const colors = ['#008b4b', '#d99a32', '#657b94', '#a783b5', '#36a99c', '#c67c65']
const amount = value => Number(value || 0)

export const money = value => value === null || value === undefined ? '—' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
export const axisMoney = value => Math.abs(value) >= 100000000 ? `${Number((value / 100000000).toFixed(1))}亿` : Math.abs(value) >= 10000 ? `${Number((value / 10000).toFixed(1))}万` : value
export const hasAmounts = (rows, keys) => rows.some(row => keys.some(key => amount(row[key]) !== 0))

export function businessDateTime(value, timeZone = 'UTC') {
  if (!value || Number.isNaN(new Date(value).getTime())) return '—'
  const parts = new Intl.DateTimeFormat('zh-CN', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value))
  const part = key => parts.find(item => item.type === key).value
  return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')}`
}

export function dateRangeFor(preset, today = new Date()) {
  const end = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const start = new Date(end)
  if (preset === 'month') start.setDate(1)
  else if (preset === 'year') { start.setMonth(0); start.setDate(1) }
  else start.setDate(start.getDate() - (preset === '7' ? 6 : 29))
  const format = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
  return [format(start), format(end)]
}

const base = label => ({
  animationDuration: 350,
  color: colors,
  textStyle: { fontFamily: 'Microsoft YaHei, sans-serif', color: '#64748b', fontSize: 11 },
  aria: { enabled: true, label: { description: label } },
  // richText 让供应商、账户名称作为文本渲染，避免将业务名称送入 HTML tooltip。
  tooltip: { trigger: 'axis', renderMode: 'richText', confine: true, valueFormatter: money },
})

export function cashTrendOption(rows, currency) {
  return {
    ...base(`收付款趋势，单位${currency}`),
    legend: { top: 4, itemWidth: 16, itemHeight: 8, textStyle: { color: '#64748b' } },
    grid: { top: 48, right: 22, left: 16, bottom: 18, containLabel: true },
    xAxis: { type: 'category', boundaryGap: false, data: rows.map(row => row.date), axisLabel: { formatter: value => value.slice(5), hideOverlap: true }, axisLine: { lineStyle: { color: '#dce3e8' } }, axisTick: { show: false } },
    yAxis: { type: 'value', axisLabel: { formatter: axisMoney }, splitLine: { lineStyle: { color: '#edf1f4', type: 'dashed' } } },
    series: [
      { name: '收款', type: 'line', triggerLineEvent: true, showSymbol: rows.length < 12, symbolSize: 5, lineStyle: { width: 2.5 }, areaStyle: { opacity: 0.06 }, data: rows.map(row => amount(row.receipt_amount)) },
      { name: '付款', type: 'line', triggerLineEvent: true, showSymbol: rows.length < 12, symbolSize: 5, lineStyle: { width: 2.5 }, data: rows.map(row => amount(row.payment_amount)) },
      { name: '收支净额', type: 'line', triggerLineEvent: true, showSymbol: false, lineStyle: { width: 1.5, type: 'dashed' }, data: rows.map(row => amount(row.net_amount)) },
    ],
  }
}

export function compositionOption(rows, label) {
  return {
    ...base(label),
    tooltip: { trigger: 'item', renderMode: 'richText', confine: true, valueFormatter: money },
    legend: { type: 'scroll', bottom: 4, itemWidth: 10, itemHeight: 10, textStyle: { color: '#64748b', fontSize: 11 } },
    series: [{ name: label, type: 'pie', radius: ['43%', '68%'], center: ['50%', '43%'], avoidLabelOverlap: true,
      label: { show: false }, emphasis: { label: { show: true, formatter: '{b}\n{d}%', fontSize: 13, fontWeight: 600 } },
      itemStyle: { borderColor: '#fff', borderWidth: 3, borderRadius: 3 },
      data: rows.filter(row => amount(row.amount) > 0).map(row => ({ name: row.label, value: amount(row.amount) })),
    }],
  }
}

export function balanceOption(rows, label, nameKey, valueKey) {
  return {
    ...base(label),
    grid: { top: 14, bottom: 12, left: 12, right: 26, containLabel: true },
    xAxis: { type: 'value', axisLabel: { formatter: axisMoney }, splitLine: { lineStyle: { color: '#edf1f4', type: 'dashed' } } },
    yAxis: { type: 'category', inverse: true, data: rows.map(row => row[nameKey]), axisTick: { show: false }, axisLine: { show: false }, axisLabel: { width: 106, overflow: 'truncate', color: '#475569' } },
    series: [{ name: label, type: 'bar', barMaxWidth: 18, itemStyle: { borderRadius: [0, 3, 3, 0] },
      data: rows.map(row => ({ value: amount(row[valueKey]), itemStyle: { color: amount(row[valueKey]) < 0 ? '#c67c65' : '#008b4b' } })),
    }],
  }
}
