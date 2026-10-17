import { totalLengthMetres } from './cutting-quantity.mjs'

export const cuttingWholeStock = row => ['root', 'sheet'].includes(row && row.cutting_unit_kind)
export const cuttingTotalLength = row => !!row && (row.cutting_quantity_basis || row.quantity_basis) === 'total_length'

export function validCuttingSheetPlan(row) {
  const plan = row.cutting_source_plan
  const ids = row.physical_material_ids || []
  if (!row.cutting_plan_checked || !plan?.source_fingerprint || plan.version !== 1 || !ids.length || new Set(ids.map(Number)).size !== ids.length
    || !['sheet', 'm2'].includes(plan.stock_unit_kind) || Number(row.quantity) !== Number(plan.planned_stock_qty)
    || (plan.stock_unit_kind === 'sheet' && Number(row.quantity) !== ids.length)
    || !Array.isArray(plan.sources) || plan.sources.length !== ids.length) return false
  const requirements = row.cutting_member_requirements || []
  const members = row.cutting_members || []
  if (!members.length || !members.some(member => Number(member.target_material_requirement_id) === Number(plan.owner_target_material_requirement_id))
    || new Set(members.map(member => Number(member.target_material_requirement_id))).size !== members.length
    || new Set(plan.sources.map(source => Number(source.physical_material_id))).size !== ids.length
    || !plan.sources.every(source => ids.map(Number).includes(Number(source.physical_material_id))
    && Number(source.expected_physical_version) > 0 && source.members?.length
    && new Set(source.members.map(member => Number(member.target_material_requirement_id))).size === source.members.length
    && source.members.every(member => /^\d+$/.test(String(member.planned_cut_piece_qty)) && Number.isSafeInteger(Number(member.planned_cut_piece_qty))
      && Number(member.planned_cut_piece_qty) > 0 && members.some(total => Number(total.target_material_requirement_id) === Number(member.target_material_requirement_id))))) return false
  return members.every(member => {
    const id = Number(member.target_material_requirement_id)
    const pieces = Number(member.planned_cut_piece_qty)
    const requirement = requirements.find(item => Number(item.id ?? item.target_material_requirement_id) === id)
    const total = plan.sources.reduce((sum, source) => sum + source.members.reduce((count, cell) => count + (Number(cell.target_material_requirement_id) === id ? Number(cell.planned_cut_piece_qty) : 0), 0), 0)
    return requirement && Number.isSafeInteger(pieces) && pieces > 0 && pieces === total && pieces <= Number(requirement.remaining_cut_piece_qty)
  })
}

export function validCuttingSource(row) {
  if (!/^\d+(?:\.\d{1,8})?$/.test(String(row.quantity)) || Number(row.quantity) <= 0 || Number(row.quantity) > Number(row.picking_available_qty)) return false
  if (row.cutting_unit_kind === 'sheet') return validCuttingSheetPlan(row)
  if (cuttingTotalLength(row)) {
    const perSource = Number(row.cutting_stock_qty_per_source)
    const count = Number(row.quantity) / perSource
    const length = Number(row.planned_cut_total_length_mm)
    return perSource > 0 && Number.isFinite(count) && count > 0 && count <= 1000 && Math.abs(count - Math.round(count)) < 0.00000001
      && /^\d+(?:\.\d{1,5}0*)?$/.test(String(row.planned_cut_total_length_mm)) && length > 0
      && length <= Number(row.remaining_cut_total_length_mm) + 0.00000001
      && length <= Math.round(count) * Number(row.cutting_source_dimensions?.length_mm) + 0.00000001
      && !row.cutting_members?.length && !row.physical_material_ids?.length
  }
  if (!cuttingWholeStock(row)) return true
  const quantity = Number(row.quantity)
  if (row.cutting_members && row.cutting_members.length) {
    const requirements = row.cutting_member_requirements || []
    const ids = new Set(row.cutting_members.map(member => Number(member.target_material_requirement_id)))
    return Number.isInteger(quantity) && quantity <= 1000 && ids.size === row.cutting_members.length && requirements.length === row.cutting_members.length
      && row.cutting_members.every(member => {
        const requirement = requirements.find(item => Number(item.id ?? item.target_material_requirement_id) === Number(member.target_material_requirement_id))
        const pieces = Number(member.planned_cut_piece_qty)
        return requirement && /^\d+$/.test(String(member.planned_cut_piece_qty)) && Number.isSafeInteger(pieces) && pieces > 0 && pieces <= Number(requirement.remaining_cut_piece_qty)
      })
  }
  const pieces = Number(row.planned_cut_piece_qty)
  return Number.isInteger(quantity) && quantity <= 1000 && /^\d+$/.test(String(row.planned_cut_piece_qty))
    && Number.isSafeInteger(pieces) && pieces > 0 && pieces <= Number(row.remaining_cut_piece_qty)
    && pieces <= quantity * Number(row.cutting_capacity_piece_qty)
}

export function validCuttingGroup(demand, sources) {
  if (!sources.length || sources.some(row => !validCuttingSource(row))) return false
  if (cuttingTotalLength(demand)) return !demand.cutting_member_requirements?.length && sources.every(cuttingTotalLength)
    && sources.reduce((sum, row) => sum + Number(row.planned_cut_total_length_mm), 0) <= Number(demand.remaining_cut_total_length_mm) + 0.00000001
  if (sources.some(cuttingTotalLength)) return false
  const cutting = cuttingWholeStock(demand) || sources.some(cuttingWholeStock)
  if (demand.cutting_member_requirements && demand.cutting_member_requirements.length) {
    const requirements = demand.cutting_member_requirements
    if (demand.cutting_unit_kind === 'sheet') return sources.every(row => row.cutting_unit_kind === 'sheet' && row.cutting_members?.length
      && row.cutting_members.every(member => requirements.some(requirement => Number(requirement.id ?? requirement.target_material_requirement_id) === Number(member.target_material_requirement_id))))
      && requirements.every(requirement => sources.reduce((sum, row) => sum + Number(row.cutting_members.find(member => Number(member.target_material_requirement_id) === Number(requirement.id ?? requirement.target_material_requirement_id))?.planned_cut_piece_qty || 0), 0) <= Number(requirement.remaining_cut_piece_qty))
    return sources.every(row => row.cutting_members && row.cutting_members.length === requirements.length)
      && requirements.every(requirement => sources.reduce((sum,row) => sum + Number(row.cutting_members.find(member => Number(member.target_material_requirement_id) === Number(requirement.id ?? requirement.target_material_requirement_id))?.planned_cut_piece_qty || 0),0) <= Number(requirement.remaining_cut_piece_qty))
  }
  if (demand.cutting_unit_kind === 'sheet') return sources.every(row => row.cutting_unit_kind === 'sheet')
    && sources.reduce((sum, row) => sum + Number(row.cutting_members?.find(member => Number(member.target_material_requirement_id) === Number(demand.id))?.planned_cut_piece_qty || 0), 0) <= Number(demand.remaining_cut_piece_qty)
  return cutting
    ? sources.every(cuttingWholeStock) && sources.reduce((sum, row) => sum + Number(row.planned_cut_piece_qty), 0) <= Number(demand.remaining_cut_piece_qty)
    : sources.reduce((sum, row) => sum + Number(row.quantity), 0) <= Number(demand.remaining_to_prepare) + 0.00000001
}

export function cuttingSourceSuggestion(row, demand, selected) {
  if (cuttingTotalLength(demand)) {
    const length = selected.length ? '' : String(demand.remaining_cut_total_length_mm || '')
    return { quantity: '', planned_cut_total_length_mm: length, planned_cut_total_length_m: totalLengthMetres(length) }
  }
  if (demand.cutting_member_requirements && demand.cutting_member_requirements.length) {
    return { quantity: '', cutting_member_requirements: demand.cutting_member_requirements.map(requirement=>({...requirement})),
      cutting_members: demand.cutting_member_requirements.map(requirement => ({target_material_requirement_id:requirement.id ?? requirement.target_material_requirement_id,planned_cut_piece_qty:String(Math.max(0,Number(requirement.remaining_cut_piece_qty)-selected.reduce((sum,source)=>sum+Number(source.cutting_members?.find(member=>Number(member.target_material_requirement_id)===Number(requirement.id ?? requirement.target_material_requirement_id))?.planned_cut_piece_qty || 0),0)))})) }
  }
  if (!cuttingWholeStock(row)) {
    return { quantity: Math.min(Number(row.picking_available_qty), Math.max(0, Number(demand.remaining_to_prepare) - selected.reduce((sum, source) => sum + Number(source.quantity || 0), 0))) || '' }
  }
  const remaining = Math.max(0, Number(row.remaining_cut_piece_qty ?? demand.remaining_cut_piece_qty) - selected.reduce((sum, source) => sum + Number(source.planned_cut_piece_qty || 0), 0))
  const capacity = Number(row.cutting_capacity_piece_qty)
  const available = Math.min(1000, Math.floor(Number(row.picking_available_qty)))
  if (!Number.isInteger(capacity) || capacity <= 0 || !Number.isSafeInteger(remaining) || !remaining || available <= 0) return { quantity: '', planned_cut_piece_qty: '' }
  return { quantity: String(Math.min(available, Math.ceil(remaining / capacity))), planned_cut_piece_qty: String(Math.min(remaining, available * capacity)) }
}

export function cuttingPickingLine(demand, source) {
  const payload = { target_material_requirement_id: source.cutting_source_plan ? Number(source.cutting_source_plan.owner_target_material_requirement_id) : demand.id, inventory_balance_id: source.id, planned_pick_qty: String(source.quantity) }
  if (cuttingTotalLength(demand)) return { ...payload, planned_cut_total_length_mm: String(source.planned_cut_total_length_mm).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') }
  if (source.cutting_members && source.cutting_members.length) payload.cutting_members=source.cutting_members.map(member=>({target_material_requirement_id:Number(member.target_material_requirement_id),planned_cut_piece_qty:Number(member.planned_cut_piece_qty)}))
  else if (cuttingWholeStock(source)) payload.planned_cut_piece_qty = Number(source.planned_cut_piece_qty)
  if (source.physical_material_ids && source.physical_material_ids.length) payload.physical_material_ids = [...source.physical_material_ids]
  if (source.cutting_source_plan) payload.cutting_source_plan = {
    version: 1,
    source_fingerprint: source.cutting_source_plan.source_fingerprint,
    sources: source.cutting_source_plan.sources.map(row => ({
      physical_material_id: Number(row.physical_material_id), expected_physical_version: Number(row.expected_physical_version),
      members: row.members.filter(member => Number(member.planned_cut_piece_qty) > 0).map(member => ({ target_material_requirement_id: Number(member.target_material_requirement_id), planned_cut_piece_qty: Number(member.planned_cut_piece_qty) }))
    }))
  }
  return payload
}

export function cuttingDimensions(value) {
  if (!value) return '—'
  return ['length_mm', 'width_mm', 'thickness_mm'].map(key => value[key]).filter(value => value != null && value !== '').join(' × ') + ' mm'
}

export function combinedCuttingDemand(owner, members) {
  return { ...owner, cutting_requirement_dimensions: null, cutting_member_requirements: members.map(row=>({...row})),
    remaining_to_prepare: members.reduce((sum,row)=>sum+Number(row.remaining_to_prepare),0).toFixed(8),
    remaining_cut_piece_qty: members.reduce((sum,row)=>sum+Number(row.remaining_cut_piece_qty),0) }
}
