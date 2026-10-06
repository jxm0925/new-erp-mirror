const sourceLabels = {
  sales_order: '销售订单',
  sales_order_refund: '客户退款',
  purchase_receipt: '采购到货（历史）',
  purchase_settlement_source: '采购结算来源',
  purchase_return_ap_offset: '采购退货冲应付',
  purchase_return_supplier_refund: '供应商退款',
}

export const sourceLabel = type => sourceLabels[type] || type
export const sourceKey = row => `${row.type || row.source_business_type}:${row.id || row.source_document_id}`
export const allocationKey = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
export const money = value => Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })

export function sourceOptionsFor(direction, partyType) {
  const type = direction === 'receipt'
    ? (partyType === 'supplier' ? 'purchase_return_supplier_refund' : 'sales_order')
    : (partyType === 'customer' ? 'sales_order_refund' : 'purchase_settlement_source')
  return [{ value: type, label: sourceLabel(type) }]
}

export function sourceMismatch(source, doc) {
  if (!sourceOptionsFor(doc.direction, doc.party_type).some(option => option.value === source.type)) return '业务来源与收付款方向或交易对手类型不一致'
  if (source.partyType !== doc.party_type || Number(source.partyId) !== Number(doc.party_id)) return '业务来源与交易对手不一致'
  if (source.currency !== doc.currency) return '业务来源与资金账户币种不一致'
  return ''
}

// 用四位小数的整数比较金额，避免 0.1 + 0.2 的浮点误差影响即时提示；正式金额仍由服务端复核。
export function amountUnits(value) {
  const text = String(value ?? '')
  if (!/^\d+(\.\d{1,4})?$/.test(text)) return null
  const [whole, decimal = ''] = text.split('.')
  const units = Number(whole) * 10000 + Number(decimal.padEnd(4, '0'))
  return Number.isSafeInteger(units) ? units : null
}

export function allocationTotal(rows) {
  return rows.reduce((sum, row) => sum + (amountUnits(row.allocated_amount) || 0), 0) / 10000
}

export function pendingFromSource(source, available, existing = null) {
  const balance = amountUnits(source.remainingAmount)
  if (balance === null) throw new Error('业务来源未返回可核销余额，请重新查询')
  return {
    ...(existing || {}),
    source_business_type: source.type,
    source_document_id: source.id,
    source_document_no: source.no,
    source_amount: source.amount,
    source_allocated_amount: source.allocatedAmount,
    source_remaining_amount: source.remainingAmount,
    allocated_amount: existing ? existing.allocated_amount : (Math.min(balance, Math.max(0, amountUnits(available) || 0)) / 10000).toFixed(4),
    idempotency_key: existing?.idempotency_key || allocationKey(),
    source_error: '',
  }
}

export function serializeAllocationItems(rows) {
  return rows.map(row => ({
    source_business_type: row.source_business_type,
    source_document_id: Number(row.source_document_id),
    ...(row.source_line_id ? { source_line_id: Number(row.source_line_id) } : {}),
    allocated_amount: String(row.allocated_amount),
    idempotency_key: row.idempotency_key,
  }))
}

export function validatePendingAllocations(rows, available, { allowEmpty = true } = {}) {
  if (!rows.length) return allowEmpty ? '' : '请先选择待核销业务'
  const balance = amountUnits(available)
  if (balance === null || balance <= 0) return '请先填写有效的收付款金额'
  let total = 0
  const seen = new Set()
  for (const row of rows) {
    if (row.source_error) return row.source_error
    if (seen.has(sourceKey(row))) return '同一业务来源不能重复添加'
    seen.add(sourceKey(row))
    const amount = amountUnits(row.allocated_amount)
    if (amount === null || amount <= 0) return '每项核销金额必须大于 0，且最多 4 位小数'
    const remaining = amountUnits(row.source_remaining_amount)
    if (remaining === null) return '业务来源余额未取得，请重新选择'
    if (amount > remaining) return `业务单 ${row.source_document_no || row.source_document_id} 的核销金额超过当前待结算余额，请调整金额或重新选择`
    total += amount
  }
  return total > balance ? '本次核销合计不得超过资金金额或可用余额' : ''
}
