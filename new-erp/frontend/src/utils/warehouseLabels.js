import QRCode from 'qrcode'
import JsBarcode from 'jsbarcode'
import api from '../api/erp/master'

export const WAREHOUSE_LABEL_SIZES = Object.freeze({
  '60x40': Object.freeze({ value: '60x40', name: '60 × 40 mm', widthMm: 60, heightMm: 40, paddingMm: 2, qrSizeMm: 22, barcodeHeightMm: 8 }),
  '40x30': Object.freeze({ value: '40x30', name: '40 × 30 mm', widthMm: 40, heightMm: 30, paddingMm: 1.5, qrSizeMm: 16, barcodeHeightMm: 6 }),
  A4: Object.freeze({ value: 'A4', name: 'A4 多联', widthMm: 90, heightMm: 50, paddingMm: 2, qrSizeMm: 24, barcodeHeightMm: 10, paperWidthMm: 210, paperHeightMm: 297, columns: 2, rows: 5, pageMarginMm: 10, columnGapMm: 10, rowGapMm: 4 })
})

export const WAREHOUSE_LABEL_MODES = Object.freeze([
  Object.freeze({ value: 'qrcode', name: '二维码' }),
  Object.freeze({ value: 'barcode', name: '条形码' }),
  Object.freeze({ value: 'both', name: '二维码 + 条形码' })
])

const PREFIXES = { location: 'LOC', item: 'ITEM', serial: 'SN', physical: 'PHY', document: 'DOC' }
// 按 203 dpi 打印机约两点宽保留最小模块，不把长码缩细到实际无法稳定扫描。
const MIN_MODULE_MM = 0.254
const QUIET_ZONE_MODULES = 10

function text(value) {
  return String(value == null ? '' : value).trim()
}

function requiredCode(value, title) {
  const result = text(value)
  if (!result) throw new Error(`${title}缺失，不能生成标签。`)
  if (/[\u0000-\u001f\u007f]/.test(result)) throw new Error(`${title}含有换行或控制字符，请先核对编码。`)
  return result
}

export function escapeWarehouseLabelText(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]))
}

/** 扫描内容只使用正式编码；实物必须用 id，不能把可变的显示编号当作 PHY 身份。 */
export function createWarehouseLabel(type, row, context = {}) {
  if (!PREFIXES[type]) throw new Error('不支持的仓库标签类型。')
  if (!row || typeof row !== 'object') throw new Error('标签数据缺失，请刷新后重试。')
  const source = { ...context, ...row }
  let code
  let payloadCode
  let name
  let spec = text(source.spec || source.item_spec)
  let detail = ''
  if (type === 'location') {
    code = requiredCode(source.location_code, '库位编码')
    name = text(source.location_name)
    spec = [source.warehouse_name, source.zone_name].map(text).filter(Boolean).join(' / ')
  } else if (type === 'item') {
    code = requiredCode(source.item_code, '物料编码')
    name = text(source.item_name)
    spec = [source.material_grade, spec].map(text).filter(Boolean).join(' · ')
  } else if (type === 'serial') {
    code = requiredCode(source.serial_no, '序列号')
    name = text(source.item_name)
    detail = text(source.item_code)
  } else if (type === 'physical') {
    payloadCode = requiredCode(source.id, '实物 ID')
    if (!/^[1-9]\d*$/.test(payloadCode)) throw new Error('实物 ID 无效，请刷新后重试。')
    code = text(source.physical_no) || payloadCode
    name = text(source.item_name)
    detail = [text(source.physical_no), text(source.item_code)].filter(Boolean).join(' · ')
    const dimensions = source.dimensions || {}
    const length = text(dimensions.length_mm)
    const width = text(dimensions.width_mm)
    const thickness = text(dimensions.thickness_mm)
    const areaOnly = String(source.shape || '').toUpperCase() === 'AREA_ONLY'
    const measured = !areaOnly && length && width ? `${length} × ${width}${thickness ? ` × ${thickness}` : ''} mm`
      : (dimensions.area_m2 != null ? `面积 ${text(dimensions.area_m2)} m²${thickness ? ` · 厚 ${thickness} mm` : ''}` : (length ? `${length} mm` : ''))
    // 实物修正后的尺寸是本张的事实，不能同时贴上物料默认尺寸造成厚度/面积冲突。
    spec = measured || spec
  } else {
    const kind = requiredCode(source.kind, '单据类型')
    if (!/^[a-z][a-z0-9_-]*$/i.test(kind)) throw new Error('单据类型无效，请核对正式单据类型。')
    code = requiredCode(source.document_no, '单号')
    payloadCode = `${kind}:${code}`
    name = text(source.document_name)
  }
  return { type, id: source.id, code, payload: `ERP:${PREFIXES[type]}:${payloadCode || code}`, name, spec, detail }
}

async function qrSymbol(payload, sideMm) {
  const data = QRCode.create(payload, { errorCorrectionLevel: 'M' })
  const modules = data.modules.size + 8
  const minSideMm = modules * MIN_MODULE_MM
  if (sideMm + 0.00001 < minSideMm) {
    throw new Error(`编码较长，二维码至少需要 ${minSideMm.toFixed(1)} mm，请改用较大标签。`)
  }
  const svg = await QRCode.toString(payload, { type: 'svg', errorCorrectionLevel: 'M', margin: 4, color: { dark: '#000000', light: '#ffffff' } })
  return {
    svg: svg.replace('<svg ', `<svg width="${sideMm}mm" height="${sideMm}mm" style="width:${sideMm}mm;height:${sideMm}mm;max-width:none;flex-shrink:0" `),
    widthMm: sideMm,
    heightMm: sideMm,
    modules
  }
}

function barcodeSymbol(payload, availableWidthMm, heightMm) {
  if (!/^[\x20-\x7e]+$/.test(payload)) throw new Error('条形码只支持英文、数字和半角符号，此编码请使用二维码。')
  const encoded = {}
  JsBarcode(encoded, payload, { format: 'CODE128', displayValue: false, width: 1, height: 1, margin: 0 })
  const bits = encoded.encodings.map(encoding => encoding.data).join('')
  const modules = bits.length + QUIET_ZONE_MODULES * 2
  const widthMm = modules * MIN_MODULE_MM
  if (widthMm > availableWidthMm + 0.00001) {
    throw new Error(`条形码至少需要 ${widthMm.toFixed(1)} mm，当前可用 ${availableWidthMm} mm；请改用二维码或较大标签。`)
  }
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
  JsBarcode(svg, payload, {
    format: 'CODE128', displayValue: false, width: 1, height: heightMm / MIN_MODULE_MM,
    margin: 0, marginLeft: QUIET_ZONE_MODULES, marginRight: QUIET_ZONE_MODULES,
    background: '#ffffff', lineColor: '#000000'
  })
  svg.setAttribute('width', `${widthMm}mm`)
  svg.setAttribute('height', `${heightMm}mm`)
  svg.setAttribute('preserveAspectRatio', 'none')
  svg.setAttribute('style', `width:${widthMm}mm;height:${heightMm}mm;max-width:none;flex-shrink:0`)
  return { svg: svg.outerHTML, widthMm, heightMm, modules }
}

/** 返回相同内容的矢量码和固定物理尺寸；预览和打印必须共用它，不能另行缩放。 */
export async function prepareWarehouseLabels(labels, options = {}) {
  const size = WAREHOUSE_LABEL_SIZES[options.size || '60x40']
  const mode = options.mode || 'qrcode'
  const copies = options.copies == null ? 1 : Number(options.copies)
  if (!size) throw new Error('请选择支持的标签尺寸。')
  if (!WAREHOUSE_LABEL_MODES.some(item => item.value === mode)) throw new Error('请选择支持的打印码类型。')
  if (!Number.isInteger(copies) || copies < 1 || copies > 100) throw new Error('每个标签的份数须为 1 至 100 的整数。')
  if (!Array.isArray(labels) || !labels.length) throw new Error('请先选择要打印的标签。')
  const prepared = []
  for (const label of labels) {
    // 只接收正式类型的 ERP 前缀；SVG 始终由库生成，不能使用调用方带入的图形。
    const prefix = label && PREFIXES[label.type]
    if (!prefix || !text(label.payload).startsWith(`ERP:${prefix}:`)) throw new Error('标签扫描内容无效，请刷新后重试。')
    requiredCode(label.payload, '扫描内容')
    try {
      const qrSizeMm = mode === 'both' ? Math.min(size.qrSizeMm, size.value === '40x30' ? 14 : 18) : size.qrSizeMm
      const barcodeValue = ['location', 'item', 'serial'].includes(label.type) ? label.code : label.payload
      const qrcode = mode === 'barcode' ? null : await qrSymbol(label.payload, qrSizeMm)
      const barcode = mode === 'qrcode' ? null : barcodeSymbol(barcodeValue, size.widthMm - size.paddingMm * 2, size.barcodeHeightMm)
      prepared.push({
        ...label, qrcode, barcode, barcodeValue,
        escaped: { code: escapeWarehouseLabelText(label.code), name: escapeWarehouseLabelText(label.name), spec: escapeWarehouseLabelText(label.spec), detail: escapeWarehouseLabelText(label.detail), barcodeValue: escapeWarehouseLabelText(barcodeValue) }
      })
    } catch (error) {
      throw new Error(`${label.code || '该标签'}：${error.message || '生成失败，请重试。'}`)
    }
  }
  return { labels: prepared, size, mode, copies, total: prepared.length * copies }
}

/** 裸码沿用正式解析优先序，打印前验证身份，避免同名 SN 抢先命中物料或库位。 */
async function verifyBarcodeTargets(prepared) {
  if (prepared.mode === 'qrcode') return
  for (const label of prepared.labels) {
    if (!['location', 'item', 'serial'].includes(label.type)) continue
    let resolved
    try {
      const response = await api.get('/v1/erp/inventory/scan/resolve', { params: { code: label.barcodeValue } })
      resolved = response.data && response.data.data
    } catch (error) {
      throw new Error(`${label.code}：无法确认条形码的定位结果，请改用二维码，或刷新权限后重试。`)
    }
    if (!resolved || resolved.type !== label.type || String(resolved.id) !== String(label.id)) {
      throw new Error(`${label.code}：裸码会定位到其他对象，请改用二维码打印。`)
    }
  }
}

function labelMarkup(label, mode) {
  const copy = label.escaped
  const qr = label.qrcode ? `<div class="label-qr">${label.qrcode.svg}</div>` : ''
  const code = label.barcode ? copy.barcodeValue : copy.code
  return `<article class="label label--${mode}"><div class="label-top">${qr}<div class="label-copy"><div class="label-name">${copy.name}</div>${copy.spec ? `<div class="label-spec">${copy.spec}</div>` : ''}${copy.detail ? `<div class="label-detail">${copy.detail}</div>` : ''}</div></div>${label.barcode ? `<div class="label-barcode">${label.barcode.svg}</div>` : ''}<div class="label-code">${code}</div></article>`
}

export function warehouseLabelPrintHtml(prepared) {
  const { size, mode, copies, labels } = prepared
  const pageCapacity = size.value === 'A4' ? size.columns * size.rows : 1
  const entries = []
  for (const label of labels) {
    for (let copy = 0; copy < copies; copy++) entries.push(labelMarkup(label, mode))
  }
  const pages = []
  for (let index = 0; index < entries.length; index += pageCapacity) pages.push(`<section class="sheet">${entries.slice(index, index + pageCapacity).join('')}</section>`)
  const a4 = size.value === 'A4'
  const sheetCss = a4
    ? `width:190mm;display:grid;grid-template-columns:repeat(${size.columns},${size.widthMm}mm);grid-auto-rows:${size.heightMm}mm;column-gap:${size.columnGapMm}mm;row-gap:${size.rowGapMm}mm;align-content:start;`
    : `width:${size.widthMm}mm;height:${size.heightMm}mm;`
  return `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>仓库标签</title><style>
@page{size:${a4 ? 'A4 portrait' : `${size.widthMm}mm ${size.heightMm}mm`};margin:${a4 ? size.pageMarginMm : 0}mm}
*{box-sizing:border-box}html,body{margin:0;padding:0;color:#000;background:#fff;font-family:Arial,"Microsoft YaHei",sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.sheet{${sheetCss}break-after:page;page-break-after:always}.sheet:last-child{break-after:auto;page-break-after:auto}
.label{width:${size.widthMm}mm;height:${size.heightMm}mm;padding:${size.paddingMm}mm;display:flex;flex-direction:column;justify-content:center;gap:1mm;break-inside:avoid;page-break-inside:avoid;overflow:visible;background:#fff}
.label-top{display:flex;align-items:center;gap:2mm;min-width:0}.label-qr{flex:none;line-height:0}.label-copy{flex:1;min-width:0;overflow-wrap:anywhere;word-break:break-word}.label-name{font-size:${size.value === '40x30' ? '8' : '10'}pt;font-weight:700;line-height:1.25}.label-spec{font-size:${size.value === '40x30' ? '6.5' : '8'}pt;line-height:1.3;margin-top:.8mm}.label-detail{font-size:6.5pt;line-height:1.25;margin-top:.5mm}
.label-barcode{flex:none;display:flex;justify-content:center;line-height:0}.label-code{flex:none;font-size:${size.value === '40x30' ? '6.5' : '7.5'}pt;line-height:1.2;text-align:center;overflow-wrap:anywhere;word-break:break-word}.label--barcode .label-copy{text-align:center}.label--qrcode .label-code{margin-top:1mm}
svg{display:block;max-width:none;flex-shrink:0}
@media screen{body{background:#e5e7eb;padding:12px}.sheet{margin:0 auto 12px;background:#fff;box-shadow:0 1px 6px #bfc5cc}}
</style></head><body data-warehouse-label-print>${pages.join('')}</body></html>`
}

/** 先在点击栈内打开窗口，再做异步身份核对，避免浏览器拦截打印；失败不留下半批标签。 */
export async function printWarehouseLabels(prepared) {
  if (!prepared || !prepared.labels || !prepared.labels.length) throw new Error('请先选择要打印的标签。')
  const popup = window.open('', '_blank', 'width=940,height=800')
  if (!popup) throw new Error('打印窗口被浏览器拦截，请允许弹出窗口后重试。')
  popup.opener = null
  try {
    await verifyBarcodeTargets(prepared)
    if (popup.closed) throw new Error('打印窗口已关闭，请重新打印。')
    popup.document.open()
    popup.document.write(warehouseLabelPrintHtml(prepared))
    popup.document.close()
    if (popup.document.fonts && popup.document.fonts.ready) await popup.document.fonts.ready
    if (popup.closed) throw new Error('打印窗口已关闭，请重新打印。')
    // 长名称/规格也不能被标签边界裁掉，不能靠浏览器自动缩放整页掩盖排版溢出。
    const labels = Array.from(popup.document.querySelectorAll('.label'))
    const overflow = labels.findIndex(label => label.scrollWidth > label.clientWidth + 2 || label.scrollHeight > label.clientHeight + 2)
    if (overflow !== -1) throw new Error(`${prepared.labels[Math.floor(overflow / prepared.copies)].code}：名称、编码或规格超出当前标签尺寸，请改用较大标签。`)
    popup.focus()
    popup.print()
    return popup
  } catch (error) {
    if (!popup.closed) popup.close()
    throw error
  }
}
