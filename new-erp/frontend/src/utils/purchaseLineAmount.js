import { invoiceTaxAmount, roundInvoiceAmount, splitGrossInvoiceAmount } from './financeInvoiceAmount.js'

// 采购数量、单价各保留八位。先按十进制相乘，最后一次取分。
const scale = 100000000n
export function purchaseDecimal(value) {
  if (value == null || value === '') return undefined
  const text = String(value).trim().replace(/^\.(?=\d)/, '0.').replace(/\.$/, '')
  const match = /^(\d+)(?:\.(\d*))?e([+-]?\d+)$/i.exec(text)
  if (!match || Math.abs(Number(match[3])) > 32) return text
  const digits = match[1] + (match[2] || '')
  const point = match[1].length + Number(match[3])
  if (point <= 0) return '0.' + '0'.repeat(-point) + digits
  return point < digits.length ? digits.slice(0, point) + '.' + digits.slice(point) : digits.padEnd(point, '0')
}
function fixed8(value) {
  return BigInt(roundInvoiceAmount(purchaseDecimal(value) || 0, 8).replace('.', ''))
}
function roundedQuotient(numerator, denominator) {
  return (2n * numerator + denominator) / (2n * denominator)
}
function decimalUnits(units, places) {
  const text = String(units).padStart(places + 1, '0')
  return text.slice(0, -places) + '.' + text.slice(-places)
}
function lineCents(quantity, unitPrice) {
  return roundedQuotient(fixed8(quantity) * fixed8(unitPrice), scale * scale / 100n)
}
export function purchaseCents(value) {
  return BigInt(roundInvoiceAmount(purchaseDecimal(value) || 0).replace('.', ''))
}
export function amountFromCents(cents) {
  return decimalUnits(cents, 2)
}

export function purchaseLineAmount(quantity, unitPrice) {
  return amountFromCents(lineCents(quantity, unitPrice))
}

export function purchasePriceRatio(value, divisor) {
  const units = fixed8(divisor)
  return units ? purchasePriceText(decimalUnits(roundedQuotient(fixed8(value) * scale, units), 8)) : ''
}

export function purchasePriceProduct(value, factor) {
  return purchasePriceText(decimalUnits(roundedQuotient(fixed8(value) * fixed8(factor), scale), 8))
}

export function purchaseUnitPrice(quantity, amount, originalPrice) {
  const units = fixed8(quantity)
  if (!units || amount == null || amount === '') return originalPrice
  if (originalPrice != null && decimalUnits(lineCents(quantity, originalPrice), 2) === roundInvoiceAmount(amount)) return purchasePriceText(originalPrice)
  return purchasePriceRatio(amount, quantity)
}

// 关联订单分批到货时，金额、税额和运费分别按原快照比例分摊到分。
export function purchaseShare(amount, part, total) {
  const units = fixed8(total)
  return units ? amountFromCents(roundedQuotient(fixed8(amount) * fixed8(part), units * (scale / 100n))) : '0.00'
}

export function purchasePriceText(value) {
  const decimal = purchaseDecimal(value)
  if (!decimal) return ''
  const [whole, fraction = ''] = decimal.split('.')
  const digits = fraction.replace(/0+$/, '')
  return whole.replace(/^0+(?=\d)/, '') + (digits ? '.' + digits : '')
}

export function formatPurchasePrice(value) {
  const text = purchasePriceText(value)
  if (!text) return '—'
  const [whole, fraction] = text.split('.')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return fraction ? grouped + '.' + fraction : grouped
}

export function purchaseTaxParts(amount, rate, taxMode, freight = 0, allocatedTax = null) {
  const excluded = taxMode === 'tax_excluded'
  const tax = allocatedTax ?? (excluded ? invoiceTaxAmount(amount, rate) : splitGrossInvoiceAmount(amount, rate).tax)
  const taxCents = purchaseCents(tax)
  const gross = purchaseCents(amount) + purchaseCents(freight) + (excluded ? taxCents : 0n)
  return { amount, excl: amountFromCents(gross - taxCents), tax, incl: amountFromCents(gross) }
}
