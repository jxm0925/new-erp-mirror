const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production, wxOverrides = {}) {
  let page;
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/report/index.js'), 'utf8');
  const wx = Object.assign({
    showToast() {}, showModal() {}, navigateBack() {},
  }, wxOverrides);
  vm.runInNewContext(source, {
    Page: value => { page = value; }, require: () => production, wx,
    Date, Math, Number, String, Object, Array, Promise,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) { Object.assign(this.data, value); };
  return page;
}

const flush = () => new Promise(resolve => setImmediate(resolve));

test('loads only the requested real quantity target and derives the report limit', async () => {
  const page = mount({ task: async () => ({ data: {
    work_order: { work_order_no: 'WO-18', base_unit: { unit_name: '台' } },
    target_details: [{ target_id: 7, target_type: 'quantity_operation', business_version: 3,
      planned_base_qty: 12, reported_base_qty: 5, remaining_base_qty: 7, actual_labor_minutes: 2 }],
  } }) });
  page.data.taskId = 18; page.data.targetId = 7;
  await page.load();
  assert.equal(page.data.remainingQty, 7);
  assert.equal(page.data.reportQty, 1);
  assert.equal(page.data.unitName, '台');
});

test('submits qualified and defect facts with one stable client command id', async () => {
  let payload;
  const page = mount({
    newCommandId: () => 'production-report-stable',
    report: async (taskId, type, targetId, data) => {
      payload = { taskId, type, targetId, data };
      return { data: { report_no: 'PRP-1', remaining_base_qty: 3 } };
    },
  });
  page.data.taskId = 18; page.data.targetId = 7;
  page.data.target = { business_version: 4 };
  page.data.task = { work_order: { work_order_no: 'WO-18' } };
  page.data.qualifiedQty = 2; page.data.unqualifiedQty = 1; page.data.scrappedQty = 0;
  page.data.defectReason = '焊缝不平整'; page.data.endLabor = true;
  page.submit();
  await flush();
  assert.equal(payload.data.client_command_id, 'production-report-stable');
  assert.equal(payload.data.expected_version, 4);
  assert.equal(payload.data.qualified_base_qty, 2);
  assert.equal(payload.data.unqualified_base_qty, 1);
  assert.equal(payload.data.defect_reason, '焊缝不平整');
});

test('keeps the same command id when a network result is unknown', async () => {
  const page = mount({
    newCommandId: () => 'production-report-retry',
    report: async () => { const error = new Error('网络中断'); error.errorCode = 'network_error'; throw error; },
  });
  page.data.taskId = 18; page.data.targetId = 7; page.data.target = { business_version: 4 };
  page.submit();
  await flush();
  assert.equal(page.data.clientCommandId, 'production-report-retry');
  assert.equal(page.data.submitting, false);
});
