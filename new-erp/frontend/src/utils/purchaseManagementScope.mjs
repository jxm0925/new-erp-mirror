export const purchaseScopes = [
  { value: 'factory', label: '工厂物料' },
  { value: 'office', label: '办公用品' }
]

export const validPurchaseScope = scope => scope === 'factory' || scope === 'office'
export const purchaseScopeLabel = scope => purchaseScopes.find(row => row.value === scope)?.label || '待拆分纠正'

// 历史单据没有表头范围时不能用当前物料主档反推；否则会重解释历史事实。
export function purchaseScopeIssue(document, items = []) {
  if (!validPurchaseScope(document?.management_scope)) return '历史单据管理类型未明确，请拆分或纠正后再继续采购、到货及入库。'
  const scope = document.management_scope
  const lines = document.items || document.request_items || []
  if (lines.some(line => {
    if (!line.item_id && !line.item) return false
    const item = items.find(row => Number(row.id) === Number(line.item_id)) || line.item
    const lineScope = line.management_scope_snapshot || item?.management_scope
    return !validPurchaseScope(lineScope) || lineScope !== scope
  })) return '明细管理类型不明确或混有办公用品和工厂物料，请拆分或纠正后再继续。'
  return ''
}

export const purchaseSourceLocked = document => Boolean(document?.plan_id || document?.purchase_order_id || document?.order_id || document?.replacement_exchange_order_id || document?.exchange_order_id || document?.settlement_mode === 'replacement_no_charge' || (document?.items || []).some(line => line.request_item_id || line.plan_item_id || line.order_item_id || line.purchase_order_item_id))
export const purchaseScopeMatches = (row, scope) => validPurchaseScope(scope) && row?.management_scope === scope
