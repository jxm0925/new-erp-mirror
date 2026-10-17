function decimal(value, scale, allowZero) {
  const text = String(value === undefined || value === null ? '' : value).trim();
  if (!new RegExp('^\\d{1,10}(?:\\.\\d{1,' + scale + '})?$').test(text) || (!allowZero && compare(text, '0') === 0)) throw new Error('实际耗用数量或尺寸必须为有效的正十进制数');
  return text;
}
function parts(value) {
  const pair = String(value).split('.');
  return [pair[0].replace(/^0+(?=\d)/, ''), (pair[1] || '').padEnd(8, '0')];
}
function compare(left, right) {
  const a = parts(left); const b = parts(right);
  if (a[0].length !== b[0].length) return a[0].length > b[0].length ? 1 : -1;
  if (a[0] !== b[0]) return a[0] > b[0] ? 1 : -1;
  return a[1] === b[1] ? 0 : a[1] > b[1] ? 1 : -1;
}
function add(left, right) {
  const a = parts(left); const b = parts(right); const fraction = Number(a[1]) + Number(b[1]);
  return String(Number(a[0]) + Number(b[0]) + Math.floor(fraction / 100000000)) + '.' + String(fraction % 100000000).padStart(8, '0');
}
function buildMaterials(rows) {
  if (!rows.length) throw new Error('请选择实际材料和库存来源');
  const totals = {};
  return rows.map(row => {
    const id = Number(row.id); if (!Number.isSafeInteger(id) || id <= 0) throw new Error('库存来源必须引用真实记录');
    const quantity = decimal(row.base_qty, 8, false); const available = decimal(row.quantity_available, 8, true);
    totals[id] = add(totals[id] || '0', quantity);
    if (compare(totals[id], available) > 0) throw new Error('实际耗用不能超过该库存来源的可用数量');
    if (row.unit_decimal_places !== undefined && row.unit_decimal_places !== null) {
      const places = row.unit_allow_decimal === false ? 0 : Number(row.unit_decimal_places);
      const fraction = quantity.split('.')[1] || '';
      if (!Number.isInteger(places) || places < 0 || places > 8 || /[1-9]/.test(fraction.slice(places))) throw new Error('实际耗用超过库存单位允许的小数精度');
    }
    const identities = (row.identities || []).map(person => Number(person.id));
    if (identities.some(value => !Number.isSafeInteger(value) || value <= 0) || new Set(identities).size !== identities.length) throw new Error('材料实物或序列号身份无效或重复');
    if ((row.physical || row.serial_tracking_mode === 'required') && compare(quantity, String(identities.length)) !== 0) throw new Error('实际耗用数量必须与真实实物或序列号数量一致');
    if (row.serial_tracking_mode === 'optional' && compare(quantity, String(identities.length)) < 0) throw new Error('材料序列号数量不能超过实际耗用');
    const result = { inventory_balance_id: id, base_qty: quantity,
      physical_material_ids: row.physical ? identities : [], inventory_serial_ids: !row.physical && row.serial_tracking_mode !== 'none' ? identities : [] };
    const dimensions = {};
    ['length_mm', 'width_mm', 'thickness_mm'].forEach(axis => {
      const value = row.usage_dimensions && row.usage_dimensions[axis];
      if (value !== undefined && value !== null && String(value).trim() !== '') dimensions[axis] = decimal(value, 2, false);
    });
    const remark = String(row.usage_remark || '').trim(); if (Array.from(remark).length > 500) throw new Error('实际用料备注最多500字');
    if (Object.keys(dimensions).length) result.usage_dimensions = dimensions;
    if (remark) result.usage_remark = remark;
    return result;
  });
}
module.exports = { buildMaterials };
