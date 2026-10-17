function percentToRatio(value) {
  const text = String(value === undefined || value === null ? '' : value).trim();
  if (!/^\d+(?:\.\d{1,6})?$/.test(text)) throw new Error('请填写0%到100%的个人份额，最多六位小数');
  const parts = text.split('.');
  const scaled = Number(parts[0]) * 1000000 + Number((parts[1] || '').padEnd(6, '0'));
  if (!Number.isSafeInteger(scaled) || scaled > 100000000) throw new Error('个人份额不能超过100%');
  return scaled === 100000000 ? '1.00000000' : '0.' + String(scaled).padStart(8, '0');
}
function ratioToPercent(value) {
  if (value === undefined || value === null || value === '') return '';
  const text = String(value);
  if (!/^\d+(?:\.\d{1,8})?$/.test(text)) return '';
  const parts = text.split('.');
  const scaled = Number(parts[0]) * 100000000 + Number((parts[1] || '').padEnd(8, '0'));
  const remainder = String(scaled % 1000000).padStart(6, '0').replace(/0+$/, '');
  return String(Math.floor(scaled / 1000000)) + (remainder ? '.' + remainder : '');
}
function defaultShares(people) {
  const ids = Array.from(new Set(people.map(person => Number(person.employee_legacy_id || person.user_id || person.legacy_id)))).sort((a, b) => a - b);
  if (!ids.length || ids.some(id => !Number.isSafeInteger(id) || id <= 0)) return [];
  const portion = Math.floor(100000000 / ids.length);
  return ids.map((id, index) => {
    const ratio = index === ids.length - 1 ? 100000000 - portion * index : portion;
    return { employee_legacy_id: id, share_ratio: ratio === 100000000 ? '1.00000000' : '0.' + String(ratio).padStart(8, '0') };
  });
}
function buildShares(rows) {
  if (!rows.length) throw new Error('请确认实际参与人员');
  let total = 0; const seen = new Set();
  const shares = rows.map(person => {
    const id = Number(person.employee_legacy_id);
    if (!Number.isSafeInteger(id) || id <= 0 || seen.has(id)) throw new Error('参与人员账号无效或重复');
    seen.add(id);
    const ratio = percentToRatio(person.percent);
    total += ratio === '1.00000000' ? 100000000 : Number(ratio.slice(2));
    return { employee_legacy_id: id, eligible: true, share_ratio: ratio, remark: null };
  });
  if (total !== 100000000) throw new Error('本工序个人份额合计必须等于100%');
  return shares.sort((a, b) => a.employee_legacy_id - b.employee_legacy_id);
}
function groups(scopes, selectedPeople, previousGroups) {
  return (scopes || []).filter(scope => scope.can_plan).map(scope => {
    const people = new Map((scope.participants || []).map(person => [Number(person.employee_legacy_id), Object.assign({}, person)]));
    (selectedPeople || []).forEach(person => {
      const id = Number(person.employee_legacy_id || person.user_id || person.legacy_id || person.id);
      if (!Number.isSafeInteger(id) || id <= 0) throw new Error('协同人员必须引用真实账号');
      if (!people.has(id)) people.set(id, { employee_legacy_id: id, employee_name: person.display_name || person.nickname || person.username || person.name || person.employee_name || '账号 #' + id, identity_exists: true, is_active: true });
    });
    const rows = Array.from(people.values()).sort((a, b) => Number(a.employee_legacy_id) - Number(b.employee_legacy_id));
    const ids = rows.map(person => Number(person.employee_legacy_id)).join(',');
    const prior = (previousGroups || []).find(group => group.scope_type === scope.scope_type && Number(group.scope_id) === Number(scope.scope_id));
    const priorMatches = prior && prior.rows.map(person => Number(person.employee_legacy_id)).sort((a, b) => a - b).join(',') === ids;
    const saved = scope.share_plan && scope.share_plan.shares || [];
    const savedMatches = saved.map(person => Number(person.employee_legacy_id)).sort((a, b) => a - b).join(',') === ids;
    const shares = savedMatches ? saved : defaultShares(rows);
    return { scope_type: scope.scope_type, scope_id: Number(scope.scope_id), scope_version: Number(scope.scope_version),
      assignment_version: Number(scope.share_plan && scope.share_plan.version_no || 0), name: scope.scope_name,
      owner_legacy_id: Number(scope.owner_legacy_id), rows: rows.map(person => Object.assign({}, person, {
        percent: priorMatches ? prior.rows.find(row => Number(row.employee_legacy_id) === Number(person.employee_legacy_id)).percent
          : ratioToPercent((shares.find(share => Number(share.employee_legacy_id) === Number(person.employee_legacy_id)) || {}).share_ratio),
      })) };
  });
}
function scopeShares(values) {
  if (!values.length) throw new Error('没有可确认个人份额的真实工序，请刷新任务');
  return values.map(group => ({ scope_type: group.scope_type, scope_id: group.scope_id,
    expected_scope_version: group.scope_version, expected_assignment_version: group.assignment_version, shares: buildShares(group.rows) }));
}
function restoreGroups(scopes, plans, ownerId) {
  return (plans || []).map(plan => {
    const scope = (scopes || []).find(row => row.scope_type === plan.scope_type && Number(row.scope_id) === Number(plan.scope_id));
    return { scope_type: plan.scope_type, scope_id: Number(plan.scope_id), scope_version: Number(plan.expected_scope_version),
      assignment_version: Number(plan.expected_assignment_version), owner_legacy_id: Number(scope && scope.owner_legacy_id || ownerId),
      name: scope && scope.scope_name || '工序 ' + plan.scope_id, rows: plan.shares.map(share => {
        const person = scope && (scope.participants || []).find(row => Number(row.employee_legacy_id) === Number(share.employee_legacy_id));
        return Object.assign({}, person || {}, { employee_legacy_id: Number(share.employee_legacy_id), employee_name: person && person.employee_name || '账号 #' + share.employee_legacy_id, percent: ratioToPercent(share.share_ratio) });
      }) };
  });
}
module.exports = { percentToRatio, ratioToPercent, defaultShares, buildShares, groups, scopeShares, restoreGroups };
