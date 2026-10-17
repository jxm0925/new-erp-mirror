const test = require('node:test');
const assert = require('node:assert/strict');
const { buildMaterials } = require('../utils/packing-material-usage');
const material = values => Object.assign({ id: 21, base_qty: '1', quantity_available: '3', physical: false, serial_tracking_mode: 'none', identities: [] }, values || {});

test('actual decimal stock quantity is independent of explicit dimensions and Chinese notes', () => {
  const row = material({ base_qty: '1.5', usage_dimensions: { length_mm: '1200.25', width_mm: '', thickness_mm: '12' }, usage_remark: '木箱底托实际用料' });
  const result = buildMaterials([row])[0];
  assert.equal(result.base_qty, '1.5');
  assert.deepEqual(result.usage_dimensions, { length_mm: '1200.25', thickness_mm: '12' });
  assert.equal(result.usage_remark, '木箱底托实际用料');
  assert.equal(row.base_qty, '1.5');
});

test('blank dimensions do not acquire fake stock or cutting defaults', () => {
  const result = buildMaterials([material({ usage_dimensions: { length_mm: '', width_mm: '', thickness_mm: '' } })])[0];
  assert.equal(Object.hasOwn(result, 'usage_dimensions'), false);
  assert.equal(Object.hasOwn(result, 'usage_remark'), false);
});

test('source cap and unit precision use exact eight-place comparisons', () => {
  assert.throws(() => buildMaterials([material({ base_qty: '3.00000001', quantity_available: '3.00000000' })]), /可用数量/);
  assert.throws(() => buildMaterials([material({ base_qty: '0.1', unit_decimal_places: 0, unit_allow_decimal: false })]), /小数精度/);
  assert.throws(() => buildMaterials([material({ base_qty: '1.2351', unit_decimal_places: 3, unit_allow_decimal: true })]), /小数精度/);
});

test('repeating the same source cannot hide aggregate overconsumption', () => {
  assert.throws(() => buildMaterials([material({ base_qty: '2' }), material({ base_qty: '2' })]), /可用数量/);
});

test('physical identity count remains authoritative and dimensions cannot resize a physical stock source', () => {
  assert.throws(() => buildMaterials([material({ physical: true, base_qty: '0.2', identities: [{ id: 77 }], usage_dimensions: { length_mm: '300' } })]), /数量一致/);
  const result = buildMaterials([material({ physical: true, identities: [{ id: 77 }], usage_dimensions: { length_mm: '300' } })])[0];
  assert.equal(result.base_qty, '1'); assert.deepEqual(result.physical_material_ids, [77]);
});
