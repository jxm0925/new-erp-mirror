const positiveId = value => /^\d+$/.test(String(value || '')) && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null

export const continuationFocus = query => {
  const balanceId = positiveId(query.continuation_balance_id)
  const outputId = positiveId(query.continuation_output_id)
  if (!balanceId || !outputId) return null
  const rawQty = String(query.continuation_qty || '1')
  const quantity = /^\d+(?:\.\d{1,8})?$/.test(rawQty) && Number(rawQty) > 0 ? Number(rawQty) : 1
  return { inventory_balance_id: balanceId, source_output_record_id: outputId,
    inventory_serial_id: positiveId(query.continuation_serial_id), base_qty: quantity }
}

export const matchesContinuationSource = (row, focus) => Boolean(focus)
  && Number(row.inventory_balance_id) === focus.inventory_balance_id
  && Number(row.source_output_record_id) === focus.source_output_record_id

export const continuationExecutionStatus = row => {
  if (row.internal_issue_status === 'RECEIVED') return '已领用，继续后续工序'
  if (row.internal_issue_status === 'ISSUED') return '仓库已交出，待工序接收'
  if (row.internal_issue_status === 'WAIT_ISSUE') return row.continuation_mode === 'same_work_order' ? '干态入库，待试水领用' : '库存已保留，待续接领用'
  return ({ CANCELLED: '已取消', RECEIVED: '已领用' })[row.status] || '待续接领用'
}
