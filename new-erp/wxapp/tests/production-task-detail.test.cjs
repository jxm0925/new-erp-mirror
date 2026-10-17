const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production = {}, wxOverrides = {}, decisionOverrides = {}) {
  let page;
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.js'), 'utf8');
  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: module => module.includes('production-decision-command')
      ? Object.assign({ pending: () => null, execute: (key, payload, send) => send(Object.assign({}, payload, { client_command_id: 'test-' + key })) }, decisionOverrides)
      : module.includes('personal-shares') ? require('../utils/personal-shares')
      : production,
    wx: Object.assign({ getStorageSync: () => ({}), showToast() {}, showLoading() {}, hideLoading() {} }, wxOverrides),
    clearInterval() {}, setInterval() { return 1; }, Date, Math, Number, String, Object, Array, Promise,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) { Object.assign(this.data, value); };
  return page;
}

const flush = () => new Promise(resolve => setImmediate(resolve));

test('task personnel uses projected identities and measured labor only', () => {
  const page = mount();
  page.data.userId = 100;
  page.data.userName = '当前负责人';
  page.buildWorkersList({
    assignee_user_legacy_id: 100,
    assignee_user: { user_id: 100, display_name: '真实负责人', department_name: '一车间' },
    collaborators: [
      { employee_legacy_id: 100, role: 'owner', joined_at: '2026-09-07T10:00:00' },
      { employee_legacy_id: 200, role: 'collaborator', joined_at: '2026-09-07T10:10:00',
        employee: { user_id: 200, display_name: '真实协同员', department_name: '二车间' } },
    ],
    labor_sessions: [
      { employee_legacy_id: 100, actual_labor_minutes: 8.5 },
      { employee_legacy_id: 200, actual_labor_minutes: 2.25 },
    ],
  }, []);
  assert.equal(page.data.workersList.length, 2);
  assert.equal(page.data.workersList[0].name, '真实负责人');
  assert.equal(page.data.workersList[0].laborText, '8.5 分钟');
  assert.equal(page.data.workersList[1].name, '真实协同员');
  assert.equal(page.data.workersList[1].laborText, '2.3 分钟');
});

test('task classification follows its frozen value even when the master operation changes', async () => {
  for (const [snapshot, current, expected] of [[true, false, true], [false, true, false], ['0', true, false], [undefined, true, false]]) {
    const page = mount({ task: async () => ({ data: { id: 9, status: 'WAIT_CLAIM', is_public_snapshot: snapshot,
      operation: { is_public: current }, work_order: {}, target_details: [] } }) }, {
      getStorageSync: key => key === 'erp_token' ? 'token' : {},
    });
    page.data.id = 9;
    await page.load();
    assert.equal(page.data.task.showPublicBadge, expected);
    assert.equal(page.data.task.assignee_user_legacy_id, undefined);
    assert.equal(page.data.totalWorkersCount, 0);
  }
});

test('owner loads real directory candidates and submits selected ids', async () => {
  let submitted;
  const page = mount({
    collaborationCandidates: async () => ({ data: [
      { user_id: 100, display_name: '负责人', department_name: '一车间' },
      { user_id: 200, display_name: '协同员', department_name: '二车间' },
    ] }),
    addCollaborators: async (id, payload) => { submitted = { id, payload }; },
  });
  page.data.id = 9;
  page.data.userId = 100;
  page.data.task = { assignee_user_legacy_id: 100, business_version: 3,
    work_order: { collaboration_enabled: true }, collaborators: [], share_scopes: [
      { scope_type: 'unit_operation', scope_id: 11, scope_version: 2, owner_legacy_id: 100, can_plan: true, participants: [{ employee_legacy_id: 100, employee_name: '负责人', is_active: true }] },
    ] };
  page.data.workersList = [{ id: 100 }];
  page.load = async () => {};
  page.openAddCollaborator();
  await flush();
  assert.equal(page.data.showCollabModal, true);
  assert.deepEqual(Array.from(page.data.collabCandidates, row => row.id), [200]);
  page.data.selectedCollabIds = [200];
  page.submitAddCollaborators();
  await flush();
  assert.equal(submitted.id, 9);
  assert.equal(submitted.payload.expected_version, 3);
  assert.deepEqual(Array.from(submitted.payload.employee_legacy_ids), [200]);
  assert.deepEqual(JSON.parse(JSON.stringify(submitted.payload.scope_shares[0].shares)).map(row => row.share_ratio), ['0.50000000', '0.50000000']);
});

test('task owner enters seventy thirty per actual scope and keeps different historical contributors', async () => {
  let submitted;
  const page = mount({ addCollaborators: async (_id, payload) => { submitted = JSON.parse(JSON.stringify(payload)); } });
  page.data.id = 9; page.data.task = { assignee_user_legacy_id: 100, business_version: 3, work_order: { collaboration_enabled: true }, share_scopes: [
    { scope_type: 'unit_operation', scope_id: 11, scope_version: 2, owner_legacy_id: 100, can_plan: true, participants: [{ employee_legacy_id: 100, employee_name: '接单人' }, { employee_legacy_id: 201, employee_name: '实际甲', has_labor: true }] },
    { scope_type: 'unit_operation', scope_id: 12, scope_version: 2, owner_legacy_id: 100, can_plan: true, participants: [{ employee_legacy_id: 100, employee_name: '接单人' }, { employee_legacy_id: 202, employee_name: '实际乙', has_labor: true }] },
  ] }; page.collabPeople = {}; page.load = async () => {}; page.refreshCollabShares();
  page.collabShareInput({ currentTarget: { dataset: { group: 0, person: 0 } }, detail: { value: '70' } });
  page.collabShareInput({ currentTarget: { dataset: { group: 0, person: 1 } }, detail: { value: '30' } });
  await page.submitAddCollaborators();
  assert.deepEqual(submitted.scope_shares[0].shares.map(person => person.share_ratio), ['0.70000000', '0.30000000']);
  assert.deepEqual(submitted.scope_shares[1].shares.map(person => person.employee_legacy_id), [100, 202]);
  assert.deepEqual(submitted.employee_legacy_ids, []);
});

test('uncertain task collaboration reopens original allocation and blocks edits until original replay', async () => {
  const pending = { expected_version: 3, employee_legacy_ids: [201], scope_shares: [{ scope_type: 'unit_operation', scope_id: 11, expected_scope_version: 2, expected_assignment_version: 0,
    shares: [{ employee_legacy_id: 100, share_ratio: '0.7' }, { employee_legacy_id: 201, share_ratio: '0.3' }] }] }; let submitted;
  const page = mount({ addCollaborators: async (_id, payload) => { submitted = JSON.parse(JSON.stringify(payload)); } }, {}, { pending: () => pending });
  page.data.id = 9; page.data.task = { status: 'COMPLETED', assignee_user_legacy_id: 100, share_scopes: [] }; page.load = async () => {};
  await page.openAddCollaborator();
  assert.equal(page.data.collabPending, true);
  assert.deepEqual(Array.from(page.data.collabShareGroups[0].rows, person => person.percent), ['70', '30']);
  page.collabShareInput({ currentTarget: { dataset: { group: 0, person: 0 } }, detail: { value: '50' } });
  assert.equal(page.data.collabShareGroups[0].rows[0].percent, '70');
  await page.submitAddCollaborators();
  assert.equal(submitted.expected_version, 3); assert.equal(submitted.scope_shares[0].shares[0].share_ratio, '0.7');
});

test('rework remains an explicit start action in the current design', () => {
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.js'), 'utf8');
  const template = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.wxml'), 'utf8');
  assert.match(template, /allowed_actions\.start_rework/);
  assert.match(template, />开始返工</);
  assert.match(source, /production\.restartRework/);
});

test('quantity work reports before the independent completion action', () => {
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.js'), 'utf8');
  const template = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.wxml'), 'utf8');
  assert.match(source, /pages\/production\/report\/index\?taskId=/);
  assert.match(template, /bindtap="openReport">\{\{targets\[0\]\.cutting_required \? '登记下料' : '提交报工'\}\}/);
  assert.match(template, /readyForCompletion/);
});

test('task timer uses the authenticated employees labor projection', () => {
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.js'), 'utf8');
  const template = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.wxml'), 'utf8');
  assert.match(source, /const myLabor = row\.my_labor \|\| null/);
  assert.match(source, /myLabor\.accumulated_seconds/);
  assert.match(source, /myLaborActive/);
  assert.match(template, /我的实际作业时长/);
});

test('pending offers do not project the viewer as an owner or start a worker clock', () => {
  const page = mount(); page.data.userId = 100; page.data.userName = '待接受人员';
  page.buildWorkersList({ status: 'WAIT_ACCEPT', assignee_user_legacy_id: null, collaborators: [], labor_sessions: [] }, []);
  assert.equal(page.data.totalWorkersCount, 0);
  assert.equal(page.data.workersList.length, 0);
});

test('offer acceptance sends assignment and task versions using the real offer id', async () => {
  let submitted;
  const page = mount({ acceptAssignment: async (id, payload) => { submitted = { id, payload }; } });
  page.data.id = 9;
  page.data.task = { business_version: 6, pending_assignment: { id: 31, business_version: 2 },
    allowed_actions: { accept_assignment: true } };
  page.load = async () => {};
  page.acceptAssignment(); await flush();
  assert.equal(submitted.id, 31);
  assert.equal(submitted.payload.expected_version, 2);
  assert.equal(submitted.payload.expected_task_version, 6);
  assert.equal(submitted.payload.client_command_id, 'test-accept_31');
});

test('optional warehouse completion preserves the selected disposition and required warehouse cannot bypass it', async () => {
  const submissions = [];
  const page = mount({ complete: async (...args) => { submissions.push(args[3]); } }, {
    showActionSheet: options => options.success({ tapIndex: 1 }),
    showModal: options => options.success({ confirm: true }),
  });
  page.data.id = 9; page.load = async () => {};
  page.data.targets = [{ target_id: 2, target_type: 'unit_operation', business_version: 4,
    output_mode_snapshot: 'warehouse_optional', quality_mode_snapshot: 'none' }];
  page.complete({ currentTarget: { dataset: { id: 2 } } }); await flush();
  assert.equal(submissions[0].disposition, 'warehouse');
  page.data.targets[0].output_mode_snapshot = 'warehouse_required';
  page.complete({ currentTarget: { dataset: { id: 2 } } }); await flush();
  assert.equal(submissions[1].disposition, 'warehouse');
});

test('collaborator search uses server pages and keeps selection when moving between pages', async () => {
  const requests = [];
  const page = mount({
    collaborationCandidates: async (id, query) => {
      requests.push({ id, query });
      return { total: 21, data: [{ user_id: query.page === 1 ? 200 : 300, display_name: '真实成员' }] };
    },
  });
  page.data.id = 9; page.data.userId = 100;
  page.data.task = { assignee_user_legacy_id: 100, status: 'READY', work_order: { collaboration_enabled: true } };
  await page.openAddCollaborator();
  page.onCheckboxChange({ detail: [200] });
  await page.collabNextPage();
  page.onCheckboxChange({ detail: [300] });
  assert.deepEqual(Array.from(page.data.selectedCollabIds), [200, 300]);
  await page.onCollabSearch({ detail: '真实' });
  assert.equal(requests[2].id, 9);
  assert.equal(requests[2].query.keyword, '真实');
  assert.equal(requests[2].query.per_page, 20);
  assert.equal(requests[2].query.page, 1);
  page.closeCollabModal();
  assert.equal(page.data.selectedCollabIds.length, 0);
  assert.equal(page.data.collabCandidates.length, 0);
});
