// 采购单位折算成库存单位后，库存单位的小数位有限制（如“根”只能是整数）。
// 返回不小于 minimum、且折算后符合库存单位精度的最小采购数量；minimum 本身就合规时返回 null。
export function minimalValidPurchaseQuantity(factor, baseDecimals = 4, purchaseDecimals = 4, minimum = 1) {
  const f = Number(factor)
  if (!(f > 0)) return null
  const baseScale = Math.pow(10, Math.max(0, Number(baseDecimals) || 0))
  const purchaseScale = Math.pow(10, Math.max(0, Number(purchaseDecimals) || 0))
  const fits = quantity => {
    const base = quantity * f * baseScale
    return Math.abs(base - Math.round(base)) < 1e-6
  }
  if (fits(minimum)) return null
  const start = Math.ceil(minimum * purchaseScale - 1e-9)
  for (let k = start; k <= start + 100000; k++) {
    const quantity = Number((k / purchaseScale).toFixed(8))
    if (fits(quantity)) return quantity
  }
  return null
}
