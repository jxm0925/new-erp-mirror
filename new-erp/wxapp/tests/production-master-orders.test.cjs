const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production) {
  let page;
  const modals = [];
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/tasks/index.js'), 'utf8');
  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: moduleName => moduleName.includes('production') ? production : {},
    wx: {
      getStorageSync: key => key === 'erp_token' ? 'real-test-token' : '',
      getSystemInfoSync: () => ({ statusBarHeight: 24, windowWidth: 390 }),
      getMenuButtonBoundingClientRect: () => ({ top: 30, left: 305, height: 32 }),
      showToast() {},
      showModal: value => modals.push(value),
      scanCode() {},
      navigateTo() {},
      stopPullDownRefresh() {},
    },
    clearTimeout() {},
    setTimeout: fn => { fn(); return 1; },
    Number, String, Object, Array, Set, Promise, Math, Date,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) { Object.assign(this.data, value); };
  page.__modals = modals;
  return page;
}

test('sales source renders server MWO semantics without summing incompatible concepts', async () => {
  let query;
  const page = mount({
    masterOrders: async params => {
      query = params;
      return {
        current_page: 1,
        total: 1,
        summary: { total: 6, in_progress: 2, wait_condition: 2, exception: 1, completed: 1 },
        data: [{
          id: 10,
          master_order_no: 'MWO202609090001',
          display_status: 'IN_PROGRESS',
          sales_order_no_snapshot: 'SO202609090018',
          customer_snapshot: { customer_name: '宁波星辉自动化' },
          salesperson_name_snapshot: '王敏',
          required_delivery_date_snapshot: '2026-09-18',
          order_remark_snapshot: '加急订单，首批先交 4 台',
          work_order_count: 2,
          product_summary: [{ item_name: '智能装配工作站', planned_qty: 9, unit_name: '台', work_order_count: 2 }],
          quantity_summary: { comparable: true, planned_qty: 9, completed_qty: 4, in_progress_qty: 2, exception_qty: 0 },
          production_task_progress: { completed: 12, total: 27, ratio: 0.4444 },
          delivery: { status: 'PARTIALLY_RECEIVED', received_line_count: 5, total_line_count: 8 },
          funding_status: 'passed',
          shipment_status: 'blocked',
          blockers: [],
        }],
      };
    },
  });

  page.onLoad();
  await page.load();
  assert.equal(query.page, 1);
  assert.equal(query.per_page, 10);
  assert.equal(page.data.summary.total, 6);
  assert.equal(page.data.rows.length, 1);
  const row = page.data.rows[0];
  assert.equal(row.workOrderCountText, '2 张生产工单');
  assert.equal(row.plannedText, '9');
  assert.equal(row.completedText, '4');
  assert.equal(row.progressText, '12 / 27 · 44%');
  assert.equal(row.deliveryText, '部分到位 5 / 8');
  assert.equal(row.fundingText, '生产已满足 · 发货待付清');
  assert.equal(row.productLines[0].displayText, '智能装配工作站 × 9 台');
  assert.equal(row.compact, false);

  page.openRow({ currentTarget: { dataset: { key: 'master:10' } } });
  assert.match(page.__modals[0].content, /PT 进度 12 \/ 27/);
  assert.match(page.__modals[0].content, /备料配送 部分到位/);
});

test('blocked master and independent source tabs use real server filters and summaries', async () => {
  let independentQuery;
  const page = mount({
    masterOrders: async () => ({
      current_page: 1,
      total: 1,
      summary: { total: 1, in_progress: 0, wait_condition: 1, exception: 0, completed: 0 },
      data: [{
        id: 11,
        master_order_no: 'MWO-BLOCKED',
        display_status: 'WAIT_CONDITION',
        customer_snapshot: {},
        work_order_count: 1,
        product_summary: [],
        quantity_summary: { comparable: true, planned_qty: 10, completed_qty: 0, in_progress_qty: 0, exception_qty: 0 },
        production_task_progress: { completed: 0, total: 0, ratio: 0 },
        delivery: { status: 'NOT_CREATED', received_line_count: 0, total_line_count: 0 },
        funding_status: 'blocked', shipment_status: 'blocked',
        blockers: [{ type: 'production_funding', reason_code: 'production_funds_insufficient', label: '当前有效净收款未达到生产资金门槛。', count: 1 }],
      }],
    }),
    workOrders: async params => {
      independentQuery = params;
      return {
        current_page: 1, total: 1,
        summary: { total: 1, in_progress: 0, wait_condition: 1, exception: 0, completed: 0 },
        data: [{
          id: 21, work_order_no: 'WO-STOCK-1', status: 'RELEASED',
          source: { no: 'SPB-1', type_label: '备货' },
          product: { item_name: '备货件' },
          quantity: { target_qty: 5, target_base_qty: 5, unit_name: '件' },
          release: { gate_summary: null },
        }],
      };
    },
  });

  await page.load();
  assert.equal(page.data.rows[0].compact, true);
  assert.equal(page.data.rows[0].actionText, '查看阻断');
  assert.equal(page.data.rows[0].blockers[0].shortLabel, '生产资金待满足');

  page.setData({ source: 'stock', activeStatus: 'WAIT_CONDITION' });
  await page.load();
  assert.equal(independentQuery.source_group, 'stock_prebuild');
  assert.equal(independentQuery.display_status, 'WAIT_CONDITION');
  assert.equal(independentQuery.include_summary, 1);
  assert.equal(page.data.rows[0].number, 'WO-STOCK-1');
  assert.equal(page.data.rows[0].productLines[0].displayText, '备货件 × 5 件');
});

test('approved MWO page keeps responsive and semantic labels in source', () => {
  const wxml = fs.readFileSync(path.join(__dirname, '../pages/production/tasks/index.wxml'), 'utf8');
  const wxss = fs.readFileSync(path.join(__dirname, '../pages/production/tasks/index.wxss'), 'utf8');
  assert.match(wxml, /销售生产/);
  assert.match(wxml, /备料配送/);
  assert.match(wxml, /回款发货/);
  assert.match(wxml, /workOrderCountText/);
  assert.match(wxss, /@media \(max-width: 360px\)/);
  assert.match(wxss, /@media \(min-width: 431px\)/);
  assert.match(wxss, /min-width: 0/);
});

test('home enters production workbench and MWO is only a workbench item', () => {
  const app = JSON.parse(fs.readFileSync(path.join(__dirname, '../app.json'), 'utf8'));
  const tabbar = fs.readFileSync(path.join(__dirname, '../custom-tab-bar/index.js'), 'utf8');
  const home = fs.readFileSync(path.join(__dirname, '../pages/index/index.js'), 'utf8');
  const workbench = fs.readFileSync(path.join(__dirname, '../pages/production/workbench/index.js'), 'utf8');
  const pageConfig = JSON.parse(fs.readFileSync(path.join(__dirname, '../pages/production/tasks/index.json'), 'utf8'));
  assert.equal(app.tabBar.list.some(item => item.pagePath === 'pages/production/tasks/index'), false);
  assert.equal(tabbar.includes('pages/production/tasks/index'), false);
  assert.equal((home.match(/name:\s*["']工单["']/g) || []).length, 1);
  assert.equal(home.includes('/pages/production/tasks/index'), false);
  assert.equal((home.match(/pages\/production\/workbench\/index/g) || []).length, 1);
  assert.equal((workbench.match(/pages\/production\/tasks\/index/g) || []).length, 1);
  assert.match(workbench, /key:\s*['"]orders['"],\s*title:\s*['"]生产工单['"]/);
  assert.equal(pageConfig.navigationStyle, 'custom');
  assert.equal(Object.hasOwn(pageConfig, 'navigationBarTitleText'), false);
});

test('custom-tab-bar has 3 symmetrical tabs without hardcoded 4-column distortion', () => {
  const tabbarJs = fs.readFileSync(path.join(__dirname, '../custom-tab-bar/index.js'), 'utf8');
  const tabbarWxss = fs.readFileSync(path.join(__dirname, '../custom-tab-bar/index.wxss'), 'utf8');
  const tabbarWxml = fs.readFileSync(path.join(__dirname, '../custom-tab-bar/index.wxml'), 'utf8');

  // Verify 3 tabs
  assert.match(tabbarJs, /pages\/index\/index/);
  assert.match(tabbarJs, /pages\/mall\/index\/index/);
  assert.match(tabbarJs, /pages\/my\/index\/index/);

  // Verify no hardcoded 4-column grid distortion
  assert.equal(tabbarWxss.includes('repeat(4,1fr)'), false);
  assert.equal(tabbarWxss.includes('repeat(4, 1fr)'), false);
  assert.match(tabbarWxss, /flex:\s*1/);
  assert.match(tabbarWxss, /safe-area-inset-bottom/);

  // Verify clean tabbar structure and auto-sync
  assert.match(tabbarWxml, /class="tabbar"/);
  assert.match(tabbarWxml, /tab-item/);
  assert.match(tabbarJs, /updateActiveByRoute/);
});

