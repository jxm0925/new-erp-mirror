import { amountUnits } from './financeCashAllocation'

export const paymentTriggers = [
  { value: 'deposit', label: '订金' },
  { value: 'before_shipment', label: '发货前' },
  { value: 'after_receipt', label: '到货后' },
  { value: 'monthly', label: '月结' },
  { value: 'agreed_date', label: '约定日期' },
  { value: 'to_be_agreed', label: '待定' },
]

export const paymentTriggerLabel = value => paymentTriggers.find(item => item.value === value)?.label || value || '待定'
export const paymentStatusLabel = value => ({ unpaid: '未付款', partial: '部分付款', paid: '已付清', overpaid: '超合同付款' })[value] || value || '—'
export const paymentStatusType = value => ({ unpaid: 'info', partial: 'warning', paid: 'success', overpaid: 'danger' })[value] || 'info'
export const positivePaymentId = value => /^\d+$/.test(String(value || '')) && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null
export const purchaseLinkKey = row => `${Number(row.purchase_order_id)}:${Number(row.payment_plan_id) || 0}`
export const purchaseOrderCanPay = order => order?.audit_status === 'approved' && order?.finance_fact_status === 'frozen' && !['draft', 'submitted', 'cancelled'].includes(order.purchase_status)

export function currentBusinessDate() {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

export function paymentDueLabel(date, remaining, today = currentBusinessDate()) {
  if (!date) return '日期待定'
  if (!(amountUnits(remaining) > 0)) return date
  if (date < today) return `${date}（已到期）`
  if (date === today) return `${date}（今日到期）`
  return date
}

export function serializePaymentPlan(items) {
  return items.map(row => ({
    ...(row.id ? { id: Number(row.id) } : {}),
    title: row.title || null,
    trigger_type: row.trigger_type,
    amount: String(row.amount ?? ''),
    due_date: row.due_date || null,
    remark: String(row.remark || '').trim(),
  }))
}

export function validatePaymentPlan(items, contractAmount) {
  if (items.length > 100) return '每张采购订单最多安排 100 期付款'
  let total = 0
  for (const row of items) {
    const amount = amountUnits(row.amount)
    if (!paymentTriggers.some(trigger => trigger.value === row.trigger_type)) return '请选择付款条件'
    if (amount === null || amount <= 0) return '每期安排金额必须大于 0，且最多 4 位小数'
    total += amount
  }
  const contract = amountUnits(contractAmount)
  return contract !== null && total > contract ? '付款安排合计不能超过合同总额' : ''
}

export function serializePurchaseOrderAllocations(rows) {
  return rows.map(row => ({
    purchase_order_id: Number(row.purchase_order_id),
    payment_plan_id: positivePaymentId(row.payment_plan_id),
    amount: String(row.amount ?? ''),
  }))
}

export function purchaseAllocationTotal(rows) {
  return rows.reduce((sum, row) => sum + (amountUnits(row.amount) || 0), 0) / 10000
}

export function validatePurchaseOrderAllocations(rows, amount, partyType = 'supplier') {
  if (!rows.length) return ''
  if (rows.length > 100) return '每笔资金最多关联 100 条订单用途'
  if (partyType !== 'supplier') return '采购订单只能关联供应商收付款'
  const cashAmount = amountUnits(amount)
  if (!(cashAmount > 0)) return '请先填写有效的收付款金额'
  const seen = new Set()
  let total = 0
  for (const row of rows) {
    if (!positivePaymentId(row.purchase_order_id)) return '请选择有效的采购订单'
    if (row.payment_plan_id != null && row.payment_plan_id !== '' && !positivePaymentId(row.payment_plan_id)) return '付款期次无效，请重新选择'
    if (seen.has(purchaseLinkKey(row))) return '同一采购订单的同一期次不能重复关联'
    seen.add(purchaseLinkKey(row))
    const value = amountUnits(row.amount)
    if (!(value > 0)) return '每条关联金额必须大于 0，且最多 4 位小数'
    total += value
  }
  return total === cashAmount ? '' : '关联采购订单的金额合计必须等于本笔收付款金额'
}

export function paymentErrorMessage(error, fallback) {
  const errors = error?.response?.data?.errors
  return error?.userMessage || (errors && Object.values(errors).flat()[0]) || error?.response?.data?.message || error?.message || fallback
}
