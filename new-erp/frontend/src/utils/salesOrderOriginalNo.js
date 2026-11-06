export function salesOrderOriginalNo(order) {
  const original = String(order.origin_order_no ?? '').trim()
  if (original || order.channel_type_snapshot !== 'online_platform') return original
  return String(order.external_order_no ?? '').trim()
}
