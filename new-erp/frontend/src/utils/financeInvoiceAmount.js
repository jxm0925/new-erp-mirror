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
