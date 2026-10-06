const snapshot = row => Object.prototype.hasOwnProperty.call(row || {}, '_planningPreview')
  ? row._planningPreview : row?.purchase_conversion_snapshot
const round = value => Number(Number(value || 0).toFixed(8))

export function planTargetBaseQty(line) {
  return Number(snapshot(line)?.planned_base_qty ?? line?.purchase_quantity ?? line?.required_qty ?? line?.plan_qty ?? 0)
}

export function planAllocatedBaseQty(line) {
  return round((line?.splits || []).reduce((sum, split) => {
    const snap = snapshot(split)
    let baseQty = snap?.planned_base_qty
    if (baseQty == null) {
      const factor = Number(snap?.conversion_factor_snapshot ?? line?._planningPreview?.conversion_factor_snapshot ?? 1)
      const qty = Number(split.purchase_quantity ?? split.purchase_qty ?? 0)
      baseQty = qty * (factor > 0 ? factor : 1)
    }
    return sum + Number(baseQty || 0)
  }, 0))
}

export function planAllocation(line) {
  const splits = line?.splits || []
  const rows = [line, ...splits].filter(Boolean)
  const target = planTargetBaseQty(line)
  const allocated = planAllocatedBaseQty(line)
  // 分配比较始终使用库存单位，保留负差额以识别超配；不能将负数归零后当作已配平。
  const delta = round(target - allocated)
  let state = 'balanced'
  if (rows.some(row => row._planningPending)) state = 'pending'
  else if (rows.some(row => row._planningError || ('_planningPreview' in row && !row._planningPreview))) state = 'error'
  else if (!line?.item_id || target <= 0 || !splits.length) state = 'empty'
  else if (delta < -0.00000001) state = 'over'
  else if (delta > 0.00000001) state = 'short'
  else if (splits.some(split => !split.supplier_id)) state = 'supplier'
  return { target, allocated, delta, state, balanced: state === 'balanced', percent: target > 0 ? Math.max(0, Math.min(100, allocated / target * 100)) : 0 }
}

export function planAllocationLabel(result) {
  return ({ pending: '计算中', error: '换算异常', empty: '待分配', supplier: '未定供应商', balanced: '已配平' })[result.state]
    || (result.state === 'over' ? `超出 ${Math.abs(result.delta)}` : `还差 ${result.delta}`)
}

export function planAllocationTag(result) {
  return result.balanced ? 'success' : ['over', 'error'].includes(result.state) ? 'danger' : 'warning'
}
