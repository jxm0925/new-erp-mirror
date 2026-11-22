import api from './master'

const root = '/v1/erp/crm/daily-reports'

export const dailyAccess = () => api.get(root + '/access')
export const dailyCandidates = params => api.get(root + '/candidates', { params })
export const readDaily = (path, paramsOrDate) => {
  const params = typeof paramsOrDate === 'string' ? { date: paramsOrDate } : { ...(paramsOrDate || {}) }
  return api.get(root + '/' + path, { params })
}
export const saveDaily = (path, form) => api.put(root + '/' + path, form)
export const saveSupervisorNote = payload => api.put(root + '/sales/supervisor-note', payload)
export const analyzeDaily = (path, date, version, extra = {}) =>
  api.post(root + '/' + path + '/analyze', { date, version, ...(extra || {}) }, { timeout: 80000 })
export const resolveDailyHelp = (dept, item, decision) =>
  api.put(root + '/team/' + dept + '/coordination/' + item.id, { version: item.version, ...decision })
export const resubmitDailyHelp = (item, note) =>
  api.put(root + '/sales/coordination/' + item.id, { version: item.version, note })

export async function exportDaily(title, sheets) {
  const xlsx = await import('xlsx')
  const book = xlsx.utils.book_new()
  sheets.forEach(([name, rows]) => {
    const safeRows = Array.isArray(rows) ? rows : []
    const ws = xlsx.utils.aoa_to_sheet(safeRows)
    const maxCols = safeRows.reduce((m, r) => Math.max(m, Array.isArray(r) ? r.length : 0), 0)
    const cols = []
    for (let c = 0; c < maxCols; c++) {
      let maxW = 10
      for (const r of safeRows) {
        const val = Array.isArray(r) && r[c] != null ? String(r[c]) : ''
        const firstLine = val.split('\n')[0] || ''
        let w = 0
        for (const ch of firstLine) w += ch.charCodeAt(0) > 255 ? 2 : 1
        maxW = Math.min(48, Math.max(maxW, w + 3))
      }
      cols.push({ wch: maxW })
    }
    if (cols.length) ws['!cols'] = cols
    xlsx.utils.book_append_sheet(book, ws, String(name).slice(0, 31))
  })
  xlsx.writeFile(book, title + '.xlsx')
}

export function pickCurrencyAmount(amounts, currency = 'CNY') {
  if (!amounts || typeof amounts !== 'object') return 0
  if (amounts[currency] != null) return Number(amounts[currency]) || 0
  return 0
}

export const helpStatus = { pending: '待协调', accepted: '处理中', returned: '已退回', done: '已完成' }
