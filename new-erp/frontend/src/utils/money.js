import { roundInvoiceAmount } from './financeInvoiceAmount.js'

// 与服务端结算一致：先按十进制对绝对值四舍五入，最后恢复符号。
export function settlementAmount(value) {
  if (value == null || value === '') return ''
  if (typeof value === 'number' && Math.abs(value) < 0.005) return '0.00'
  const text = String(value).trim()
  const rounded = roundInvoiceAmount(text.replace(/^[+-]/, ''))
  return text.startsWith('-') && rounded && rounded !== '0.00' ? '-' + rounded : rounded
}

export function formatMoney(value) {
  const amount = settlementAmount(value)
  if (!amount) return '—'
  const [whole, fraction] = amount.split('.')
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + fraction
}
