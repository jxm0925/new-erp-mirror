const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production = {}) {
  let page;
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/my-tasks/index.js'), 'utf8');
  const storage = { erp_token: 'valid_mock_token' };
  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: (mod) => {
      if (mod.includes('production')) return production;
      return {};
    },
    wx: {
      getStorageSync: key => storage[key] || '',
      setStorageSync: (key, val) => { storage[key] = val; },
      showToast() {},
      navigateTo() {},
      scanCode() {},
      stopPullDownRefresh() {},
    },
    clearInterval() {},
    setInterval() { return 1; },
    clearTimeout() {},
    setTimeout(fn) { return fn(); },
    Date,
    Math,
    Number,
    String,
    Object,
    Array,
    Set,
    Promise,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) {
    Object.entries(value).forEach(([k, v]) => {
      if (k.includes('[')) {
        // e.g. filteredRows[0].statusTagText
        const match = k.match(/filteredRows\[(\d+)\]\.(.+)/);
        if (match) {
          this.data.filteredRows[Number(match[1])][match[2]] = v;
        }
      } else {
        this.data[k] = v;
      }
    });
  };
  return page;
}

test('my-tasks loads tasks, computes progress, and formats status tags and CTA buttons correctly', async () => {
  const page = mount({
    myTasks: async (params) => {
      assert.equal(params.include_stats, 1);
      assert.equal(params.execution_filter, 'all');
      return {
        total: 2,
        stats: { today_total: 8, running: 2, waiting: 4, completed_today: 2 },
        data: [
          {
            id: 1,
            task_no: 'TASK-20260907-0001',
            status: 'IN_PROGRESS',
            sequence_no_snapshot: '020',
            operation_name_snapshot: '总成装配与测试',
            execution_mode: 'quantity',
            target_details: [
              {
                status: 'IN_PROGRESS',
                planned_base_qty: 100,
                completed_base_qty: 65,
                kitting_confirmed_at: '2026-09-07T08:00:00Z',
                started_at: new Date(Date.now() - 3600000).toISOString(),
                status_label: '进行中',
                allowed_actions: { pause: true },
              },
            ],
            work_order: {
              work_order_no: 'WO202609070001',
              output_item: { item_name: '精密伺服电机总成 M-400A' },
            },
            collaborators: [{ id: 1 }, { id: 2 }],
            assignee_user: { nickname: '张师傅' },
          },
          {
            id: 2,
            task_no: 'TASK-20260907-0018',
            status: 'WAIT_MATERIAL',
            sequence_no_snapshot: '015',
            operation_name_snapshot: '齿轮箱精整',
            execution_mode: 'quantity',
            target_details: [
              {
                status: 'WAIT_MATERIAL',
                planned_base_qty: 50,
                completed_base_qty: 0,
                kitting_confirmed_at: null,
                status_label: '待齐套',
                reason_message: '物料条件待满足；可能来自配送、交接、内部领用或工位常备料。',
                allowed_actions: { confirm_kitting: true },
              },
            ],
            work_order: {
              work_order_no: 'WO202609070003',
              output_item: { item_name: '高转矩减速机壳体 (GH-15)' },
            },
            collaborators: [],
          },
        ],
      };
    },
  });

  await page.load();

  assert.equal(page.data.loaded, true);
  assert.equal(page.data.stats.total, 8);
  assert.equal(page.data.stats.running, 2);
  assert.equal(page.data.stats.waiting, 4);
  assert.equal(page.data.stats.completed, 2);
  assert.equal(page.data.filteredRows.length, 2);

  // First item: IN_PROGRESS
  const item1 = page.data.filteredRows[0];
  assert.equal(item1.operationSnapshot, '020 · 总成装配与测试');
  assert.equal(item1.progressPct, 65);
  assert.equal(item1.statusCategory, 'running');
  assert.match(item1.statusTagText, /加工中 \d{2}:\d{2}:\d{2}/);
  assert.equal(item1.kittingText, '全部齐套');
  assert.equal(item1.kittingClass, 'col-success');
  assert.equal(item1.ctaText, '继续作业 ➔');
  assert.equal(item1.ctaClass, 'cta-danger');
  assert.equal(item1.workerDesc, '负责人 张师傅 · 协同 2人');

  // Second item: WAIT_MATERIAL
  const item2 = page.data.filteredRows[1];
  assert.equal(item2.operationSnapshot, '015 · 齿轮箱精整');
  assert.equal(item2.progressPct, 0);
  assert.equal(item2.statusCategory, 'kitting');
  assert.equal(item2.statusTagText, '待齐套');
  assert.match(item2.kittingText, /物料条件待满足/);
  assert.equal(item2.kittingClass, 'col-warning');
  assert.equal(item2.ctaText, '确认齐套并开工 ➔');
  assert.equal(item2.ctaClass, 'cta-warning');
  assert.equal(item2.workerDesc, '负责人 —');
});

test('switching tabs changes active state and reloads data with execution_filter', async () => {
  let queriedFilter = null;
  const page = mount({
    myTasks: async (params) => {
      queriedFilter = params.execution_filter;
      return { total: 0, data: [], stats: {} };
    },
  });

  page.setTab({ currentTarget: { dataset: { value: 'running' } } });
  assert.equal(page.data.active, 'running');
  assert.equal(queriedFilter, 'running');
});
