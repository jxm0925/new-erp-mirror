export function roundInvoiceAmount(value, scale = 2) {
  const match = /^(\d+)(?:\.(\d*))?$/.exec(String(value == null ? 0 : value).trim())
  if (!match) return ''
  const fraction = (match[2] || '').padEnd(scale + 1, '0')
  const factor = 10n ** BigInt(scale)
  let units = BigInt(match[1]) * factor + BigInt(fraction.slice(0, scale) || '0')
  if (Number(fraction[scale]) >= 5) units += 1n
  return `${units / factor}.${String(units % factor).padStart(scale, '0')}`
}

export function invoiceTaxAmount(excl, rate) {
  const amount = roundInvoiceAmount(excl)
  const taxRate = roundInvoiceAmount(Number(rate).toFixed(8), 8)
  if (!amount || !taxRate) return ''
  const units = (BigInt(amount.replace('.', '')) * BigInt(taxRate.replace('.', '')) + 5000000000n) / 10000000000n
  return `${units / 100n}.${String(units % 100n).padStart(2, '0')}`
}

export function sumInvoiceAmounts(values, scale = 2) {
  const units = values.reduce((sum, value) => {
    const amount = roundInvoiceAmount(value, scale)
    return sum + (amount ? BigInt(amount.replace('.', '')) : 0n)
  }, 0n)
  const factor = 10n ** BigInt(scale)
  return `${units / factor}.${String(units % factor).padStart(scale, '0')}`
}

// 按含税金额反算时，未税金额先取到分，税额承接差额。
export function splitGrossInvoiceAmount(gross, rate) {
  const amount = roundInvoiceAmount(gross)
  const percent = roundInvoiceAmount(typeof rate === 'number' ? rate.toFixed(8) : rate, 8)
  if (!amount || !percent) return { net: '', tax: '' }
  const cents = BigInt(amount.replace('.', ''))
  const hundredPercent = 10000000000n
  const divisor = hundredPercent + BigInt(percent.replace('.', ''))
  const net = (cents * hundredPercent + divisor / 2n) / divisor
  const decimal = value => `${value / 100n}.${String(value % 100n).padStart(2, '0')}`
  return { net: decimal(net), tax: decimal(cents - net) }
}
