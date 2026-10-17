export function cuttingUnitKind(unit) {
  if (!unit) return null
  if (unit.is_legacy && unit.standard_unit) unit = unit.standard_unit
  const code = String(unit.unit_code || '').trim().toUpperCase()
  const symbol = String(unit.symbol || unit.unit_symbol || '').trim().toUpperCase().replace(/²/g, '2').replace(/㎡/g, 'M2').replace(/\^/g, '')
  if (code === 'M' || symbol === 'M') return 'm'
  if (['M2', 'SQM'].includes(code) || symbol === 'M2') return 'm2'
  if (code === 'ROOT' || symbol === '根') return 'root'
  if (code === 'SHEET' || symbol === '张') return 'sheet'
  return null
}

export function cuttingQuantityBasis(line) {
  return line?.cutting_quantity_basis || line?.cut_dimensions?.quantity_basis || line?.cutting_quantity_snapshot?.quantity_basis || 'dimensions_pieces'
}

export function totalLengthMetres(value) {
  const text = value == null ? '' : String(value).trim()
  if (!/^\d+(?:\.\d+)?$/.test(text)) return ''
  const [whole, fraction = ''] = text.split('.')
  const padded = whole.padStart(4, '0')
  return `${padded.slice(0, -3)}.${padded.slice(-3)}${fraction}`.replace(/^0+(?=\d)/, '').replace(/0+$/, '').replace(/\.$/, '')
}

export function totalLengthMillimetres(value, precision = 2) {
  if (value == null || String(value).trim() === '') return null
  const digitsAfterPoint = precision + 3
  const text = typeof value === 'number' && Number.isFinite(value) ? value.toFixed(digitsAfterPoint) : String(value).trim()
  if (!new RegExp(`^\\d+(?:\\.\\d{0,${digitsAfterPoint}})?$`).test(text)) return null
  const [whole, fraction = ''] = text.split('.')
  const digits = `${whole}${fraction.padEnd(digitsAfterPoint, '0')}`
  return `${digits.slice(0, -precision)}.${digits.slice(-precision)}`.replace(/^0+(?=\d)/, '')
}

export function cuttingAreaSquareMetres(value) {
  const text = value == null ? '' : String(value).trim()
  if (!/^\d+(?:\.\d+)?$/.test(text)) return ''
  const [whole, fraction = ''] = text.split('.')
  const padded = whole.padStart(7, '0')
  return `${padded.slice(0, -6)}.${padded.slice(-6)}${fraction}`.replace(/^0+(?=\d)/, '').replace(/0+$/, '').replace(/\.$/, '')
}

export function cuttingAreaSquareMillimetres(value) {
  const text = value == null ? '' : String(value).trim()
  if (!/^\d+(?:\.\d{0,8})?$/.test(text)) return null
  const [whole, fraction = ''] = text.split('.')
  const digits = `${whole}${fraction.padEnd(8, '0')}`
  return `${digits.slice(0, -2)}.${digits.slice(-2)}`.replace(/^0+(?=\d)/, '')
}

// 即时展示使用同一几何口径；正式数量及八位快照始终由后端重新计算。
export function previewCuttingQuantity(line, unit) {
  const mode = line.cutting_mode || (line.is_length_cut_material ? 'length' : 'none')
  if (!['length', 'sheet'].includes(mode)) return { quantity: Number(line.qty), error: null }
  const kind = cuttingUnitKind(unit)
  if (!kind || (mode === 'length' && !['m', 'root'].includes(kind)) || (mode === 'sheet' && !['m2', 'sheet'].includes(kind))) return { quantity: null, error: '请维护物料真实库存单位：米、平方米、根或张' }
  const basis = cuttingQuantityBasis(line)
  if (!['dimensions_pieces', 'total_length'].includes(basis) || (basis === 'total_length' && mode !== 'length')) return { quantity: null, error: '按总用量仅适用于长料，板材仍按单件尺寸和件数登记' }
  const totalLength = basis === 'total_length' ? Number(line.total_length_mm) : null
  const length = Number(line.cut_length_mm)
  const width = Number(line.cut_width_mm)
  const pieces = Number(line.piece_qty)
  if (basis === 'total_length') {
    if (!(totalLength > 0) || totalLength > 9999999999.99) return { quantity: null, error: '请填写有效的每份总用量，最多保留五位小数（米）' }
    if (['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty'].some(key => line[key] != null && line[key] !== '') || line.allow_cut_rotation) return { quantity: null, error: '按总用量不能同时填写单段尺寸或段数' }
  } else if (!(length > 0) || !Number.isSafeInteger(pieces) || pieces <= 0 || (mode === 'sheet' && (!(width > 0) || !(Number(line.cut_thickness_mm) > 0)))) return { quantity: null, error: '请完整填写单件下料尺寸和正整数件数' }
  const stock = line.stock_dimensions || {}
  const stockLength = Number(stock.length_mm || (mode === 'length' ? line.standard_stock_length_mm : null))
  const stockWidth = Number(stock.width_mm)
  if ((kind === 'root' && !(stockLength > 0)) || (kind === 'sheet' && (!(stockLength > 0) || !(stockWidth > 0)))) return { quantity: null, error: '按根或张换算必须明确原料标准尺寸' }
  if (mode === 'length' && basis !== 'total_length' && stockLength > 0 && length > stockLength) return { quantity: null, error: '单件下料长度不能超过原料标准长度' }
  if (mode === 'sheet' && stockLength > 0 && stockWidth > 0 && !((length <= stockLength && width <= stockWidth) || (line.allow_cut_rotation && length <= stockWidth && width <= stockLength))) return { quantity: null, error: '单件裁切长宽超过原料标准尺寸' }
  const divisor = ({ m: 1000, m2: 1000000, root: stockLength, sheet: stockLength * stockWidth })[kind]
  const theoretical = (basis === 'total_length' ? totalLength : length * pieces * (mode === 'sheet' ? width : 1)) / divisor
  const quantity = Math.round((theoretical + Number.EPSILON) * 10000) / 10000
  if (!(quantity > 0) || quantity > 9999999999.9999) return { quantity: null, error: '理论耗用超出 BOM 四位小数可记录范围，请调整计量单位' }
  return { quantity, theoreticalQuantity: Math.round((theoretical + Number.EPSILON) * 100000000) / 100000000, error: null }
}
