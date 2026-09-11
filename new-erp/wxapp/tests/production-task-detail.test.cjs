const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production = {}) {
  let page;
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/task-detail/index.js'), 'utf8');
  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: () => production,
    wx: { getStorageSync: () => ({}), showToast() {}, showLoading() {}, hideLoading() {} },
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
    work_order: { collaboration_enabled: true }, collaborators: [] };
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
  assert.match(template, /bindtap="openReport">提交报工/);
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
