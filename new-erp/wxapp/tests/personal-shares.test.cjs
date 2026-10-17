const test = require('node:test');
const assert = require('node:assert/strict');
const shares = require('../utils/personal-shares');
const scope = (id, extra) => ({ scope_type: 'unit_operation', scope_id: id, scope_version: 3, owner_legacy_id: 101, can_plan: true,
  participants: [{ employee_legacy_id: 101, employee_name: '接单人', is_active: true }].concat(extra || []) });

test('single owner is one hundred, equal allocation keeps the exact rounding remainder', () => {
  assert.equal(shares.groups([scope(11)], [])[0].rows[0].percent, '100');
  const groups = shares.groups([scope(11, [{ employee_legacy_id: 102 }, { employee_legacy_id: 103 }])], []);
  assert.deepEqual(shares.buildShares(groups[0].rows).map(person => person.share_ratio), ['0.33333333', '0.33333333', '0.33333334']);
});

test('manual seventy thirty is exact and each actual target keeps its different past labor person', () => {
  const groups = shares.groups([scope(11, [{ employee_legacy_id: 102, has_labor: true }]), scope(12, [{ employee_legacy_id: 103, has_labor: true }])], []);
  groups[0].rows[0].percent = '70'; groups[0].rows[1].percent = '30';
  const plans = shares.scopeShares(groups);
  assert.deepEqual(plans[0].shares.map(person => person.share_ratio), ['0.70000000', '0.30000000']);
  assert.deepEqual(plans[1].shares.map(person => person.employee_legacy_id), [101, 103]);
});

test('same real group keeps manual edits through search pages, while another scope cannot receive them', () => {
  const first = shares.groups([scope(11)], [{ id: 102, name: '协同人' }]);
  first[0].rows[0].percent = '70'; first[0].rows[1].percent = '30';
  const retained = shares.groups([scope(11), scope(12)], [{ id: 102, name: '协同人' }], first);
  assert.deepEqual(retained[0].rows.map(person => person.percent), ['70', '30']);
  assert.deepEqual(retained[1].rows.map(person => person.percent), ['50', '50']);
});

test('old eighty percent plan stays unchanged on read and invalid new sums or fake person ids are rejected', () => {
  const original = scope(11, [{ employee_legacy_id: 102 }]);
  original.share_plan = { version_no: 2, shares: [{ employee_legacy_id: 101, share_ratio: '0.5' }, { employee_legacy_id: 102, share_ratio: '0.3' }] };
  const group = shares.groups([original], [])[0];
  assert.deepEqual(group.rows.map(person => person.percent), ['50', '30']);
  assert.throws(() => shares.scopeShares([group]), /100%/);
  assert.throws(() => shares.groups([scope(11)], [{ id: 0 }]), /真实账号/);
  assert.equal(shares.percentToRatio('0'), '0.00000000');
  assert.throws(() => shares.percentToRatio('101'));
});
