export const confirmationQuantityKeys = ['inventory_qty', 'production_qty', 'purchase_qty', 'service_qty', 'no_delivery_qty']

export function confirmationAllocationSum(row) {
  return confirmationQuantityKeys.reduce((sum, key) => sum + Number(row[key] || 0), 0)
}

export function currentConfirmationSuggestion(row) {
  const quantity = Math.max(0, Number(row.confirm_qty || 0))
  if (row.line_type === 'service') return { inventory_qty: 0, production_qty: 0, purchase_qty: 0, service_qty: quantity, no_delivery_qty: 0 }
  if (['no_delivery', 'fee', 'auxiliary'].includes(row.line_type)) return { inventory_qty: 0, production_qty: 0, purchase_qty: 0, service_qty: 0, no_delivery_qty: quantity }
  const inventory = Math.min(quantity, Math.max(0, Number(row.available_sales_qty || 0)))
  return { inventory_qty: inventory, production_qty: row.is_purchase_fulfilled ? 0 : quantity - inventory,
    purchase_qty: row.is_purchase_fulfilled ? quantity - inventory : 0, service_qty: 0, no_delivery_qty: 0 }
}

export function confirmationIsAdjusted(row) {
  const suggestion = currentConfirmationSuggestion(row)
  return confirmationQuantityKeys.some(key => Math.abs(Number(row[key] || 0) - suggestion[key]) > 0.00000001)
}

export function buildSalesProductionConfirmation(order, rows, reason, commandId) {
  const version = Number(order?.business_version)
  if (!Number.isSafeInteger(version) || version < 1) throw new Error('缺少有效订单版本，请刷新后重试')
  if (!commandId) throw new Error('缺少本次确认的操作编号')
  const lines = rows.map(row => {
    const values = Object.fromEntries(confirmationQuantityKeys.map(key => [key, Number(row[key] || 0)]))
    const quantity = Number(row.confirm_qty)
    if (!Number.isFinite(quantity) || quantity < 0 || Object.values(values).some(value => !Number.isFinite(value) || value < 0)) throw new Error('确认数量必须是有效的非负数')
    if (Math.abs(confirmationAllocationSum(values) - quantity) > 0.00000001) throw new Error('库存、生产、外购、服务及无需发货数量之和必须等于本次确认数量')
    if (quantity > Number(row.remaining_confirm_qty ?? row.confirm_qty) + 0.00000001) throw new Error('本次确认数量不能超过剩余可确认数量')
    const line = { sales_order_line_id: row.sales_order_line_id, confirm_qty: quantity, ...values }
    // 服务行（如发红包）默认已完成，可在确认页取消勾选
    if (row.line_type === 'service') line.service_completed = row.service_completed !== false
    return line
  })
  return { client_command_id: commandId, expected_version: version, adjustment_reason: String(reason || '').trim() || null, lines }
}
