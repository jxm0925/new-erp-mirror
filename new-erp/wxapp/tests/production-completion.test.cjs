const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production, wxOverrides = {}) {
  let page;
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/completion/index.js'), 'utf8');
  const wx = Object.assign({
    showToast() {}, showModal() {}, navigateBack() {}, stopPullDownRefresh() {},
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

test('loads server-reported quantity and derives selected terminal-output totals', async () => {
  const page = mount({ completionPreflight: async () => ({ data: {
    work_order_business_version: 4, production_execution_mode: 'unit', passed: true,
    quantity: { planned_base_qty: 3, reported_base_qty: 2, base_unit_name: '台' },
    checks: [], terminal_outputs: [
      { output_record_id: 11, source_target_type: 'unit_operation', submitted_base_qty: 1, qualified_base_qty: 1 },
      { output_record_id: 12, source_target_type: 'unit_operation', submitted_base_qty: 1, qualified_base_qty: 1 },
    ],
  } }) });
  page.data.workOrderId = 9;
  await page.load();
  assert.equal(page.data.plannedQty, '3');
  assert.equal(page.data.reportedQty, '2');
  assert.equal(page.data.completionQty, '2');
  assert.equal(page.data.selectedCount, 2);
  page.updateSelection(1);
  assert.deepEqual(Array.from(page.data.selectedOutputs, row => row.output_record_id), [11]);
  assert.equal(page.data.completionQty, '1');
});

test('submits selected output identities and preserves command id after unknown network result', async () => {
  const payloads = [];
  const networkError = new Error('网络中断'); networkError.errorCode = 'network_error';
  const page = mount({
    newCommandId: () => 'completion-stable-command',
    submitCompletion: async (workOrderId, payload) => { payloads.push({ workOrderId, payload }); throw networkError; },
  });
  page.data.workOrderId = 9;
  page.data.preflight = { work_order_business_version: 4 };
  page.data.selectedOutputs = [{ output_record_id: 11 }, { output_record_id: 12 }];
  page.submit();
  await flush();
  assert.equal(payloads[0].workOrderId, 9);
  assert.equal(payloads[0].payload.client_command_id, 'completion-stable-command');
  assert.equal(payloads[0].payload.expected_version, 4);
  assert.deepEqual(Array.from(payloads[0].payload.output_record_ids), [11, 12]);
  assert.equal(page.data.clientCommandId, 'completion-stable-command');
  assert.equal(page.data.submitting, false);
});
