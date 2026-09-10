const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mountPage(filePath, productionMock, options = {}) {
  let page;
  const modals = [];
  const navigations = [];
  const toasts = [];
  const source = fs.readFileSync(filePath, 'utf8');

  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: moduleName => {
      if (moduleName.includes('production')) return productionMock;
      return {};
    },
    wx: {
      getStorageSync: key => (key === 'erp_token' ? 'token-123' : ''),
      getSystemInfoSync: () => ({ statusBarHeight: 24, windowWidth: 390 }),
      getMenuButtonBoundingClientRect: () => ({ top: 30, left: 305, height: 32 }),
      showToast: opt => toasts.push(opt),
      showModal: opt => modals.push(opt),
      navigateTo: opt => navigations.push(opt),
      navigateBack() {},
      stopPullDownRefresh() {},
    },
    getCurrentPages: () => [{ route: 'previous' }, { route: 'current' }],
    decodeURIComponent: str => decodeURIComponent(str),
    encodeURIComponent: str => encodeURIComponent(str),
    clearTimeout() {},
    setTimeout: fn => { fn(); return 1; },
    Number, String, Object, Array, Set, Promise, Math, Date,
  });

  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (val) {
    for (const k of Object.keys(val)) {
      if (k.includes('.')) {
        const parts = k.split('.');
        let cur = this.data;
        for (let i = 0; i < parts.length - 1; i++) {
          if (!cur[parts[i]]) cur[parts[i]] = {};
          cur = cur[parts[i]];
        }
        cur[parts[parts.length - 1]] = val[k];
      } else {
        this.data[k] = val[k];
      }
    }
  };
  page.__modals = modals;
  page.__navigations = navigations;
  page.__toasts = toasts;
  return page;
}

test('all 3 work order detail routes are registered in app.json', () => {
  const appJson = JSON.parse(fs.readFileSync(path.join(__dirname, '../app.json'), 'utf8'));
  assert.ok(appJson.pages.includes('pages/production/master-detail/index'));
  assert.ok(appJson.pages.includes('pages/production/work-order-detail/index'));
  assert.ok(appJson.pages.includes('pages/production/unit-detail/index'));
});

test('master-detail page renders 5 tabs and links to work-order-detail', async () => {
  const filePath = path.join(__dirname, '../pages/production/master-detail/index.js');
  const mockProduction = {
    masterOrder: async () => ({
      data: {
        id: 10,
        master_order_no: 'MWO202609090001',
        sales_order_no_snapshot: 'SO202609090018',
        customer_snapshot: { customer_name: '宁波星辉自动化' },
        quantity_summary: { planned_qty: 6, completed_qty: 4, in_progress_qty: 1, waiting_qty: 1, exception_qty: 0 },
        production_task_progress: { completed: 12, total: 18 },
        delivery: { received_line_count: 5, total_line_count: 8 },
        kitting: { confirmed_target_count: 4, required_target_count: 6 },
        funding: { production_funds_satisfied: true, shipment_funds_satisfied: false, contract_amount: 500000, net_received_amount: 300000, outstanding_amount: 200000 },
        shipment: { comparable: true, total_qty: 10, shipped_qty: 4, unit_name: '台' },
        delivery_overview: {
          total_required_line_count: 8,
          prepared_line_count: 5,
          delivered_line_count: 4,
          received_line_count: 3,
          waiting_kitting_operation_count: 2,
          pending_alerts: [{ id: 1, text: '驱动器：配送触发时间未配置' }],
          active_deliveries: [{ id: 2, wave_no: 'DW001', destination: '机加区', summary_text: '1 个配送任务 / 3 项物料', status: '配送中' }],
          upcoming_deliveries: [{ id: 3, stage_name: '首工序 · WO202609090001', material_count_text: '3 项物料', expected_release_text: '预计 09-10 14:30 释放' }],
        },
        attachments: [],
        remarks: [],
      },
    }),
    masterOrderWorkOrders: async () => ({
      data: [
        {
          id: 101,
          work_order_no: 'WO202609090001',
          display_status_label: '生产中',
          output_item: { item_name: '智能装配工作站' },
          quantity_summary: { planned_qty: 6, completed_qty: 4, in_progress_qty: 1, waiting_qty: 1, exception_qty: 0, unit_name: '台' },
          production_task_progress: { completed: 12, total: 18 },
        },
      ],
    }),
  };

  const page = mountPage(filePath, mockProduction);
  page.onLoad({ id: '10' });
  await page.load();

  assert.equal(page.data.statusBarHeight, 24);
  assert.equal(page.data.navBarHeight, 44);
  assert.equal(page.data.tabs.length, 5);
  assert.equal(page.data.master.master_order_no, 'MWO202609090001');
  assert.equal(page.data.master.plannedQty, '6');
  assert.equal(page.data.master.completedQty, '4');
  assert.equal(page.data.master.inProgressQty, '1');
  assert.equal(page.data.master.waitingQty, '1');
  assert.equal(page.data.master.exceptionQty, '0');
  assert.equal(page.data.master.taskRatio, 67);

  // Four conditions
  assert.equal(page.data.master.deliveryDelivered, '5');
  assert.equal(page.data.master.deliveryTotal, '8');
  assert.equal(page.data.master.kittingConfirmed, '4');
  assert.equal(page.data.master.kittingTotal, '6');
  assert.equal(page.data.master.productionSatisfied, true);
  assert.equal(page.data.master.shipmentSatisfied, false);

  // Tab 2 execution tab verification (matching design mockup)
  assert.equal(page.data.master.workOrders.length, 1);
  const wo1 = page.data.master.workOrders[0];
  assert.equal(wo1.work_order_no, 'WO202609090001');
  assert.equal(wo1.plannedQty, '6');
  assert.equal(wo1.completedQty, '4');
  assert.equal(wo1.inProgressQty, '1');
  assert.equal(wo1.taskCompleted, '12');
  assert.equal(wo1.taskTotal, '18');

  // Tab 3 delivery tab verification (matching design mockup)
  assert.equal(page.data.master.deliveryTab.totalRequired, '8');
  assert.equal(page.data.master.deliveryTab.prepared, '5');
  assert.equal(page.data.master.deliveryTab.delivered, '4');
  assert.equal(page.data.master.deliveryTab.received, '3');
  assert.equal(page.data.master.deliveryTab.waitingKittingOps, '2');
  assert.equal(page.data.master.deliveryTab.pendingAlerts.length, 1);
  assert.equal(page.data.master.deliveryTab.activeDeliveries.length, 1);
  assert.equal(page.data.master.deliveryTab.activeDeliveries[0].wave_no, 'DW001');
  assert.equal(page.data.master.deliveryTab.upcomingDeliveries.length, 1);
  assert.equal(page.data.master.deliveryTab.upcomingDeliveries[0].stage_name, '首工序 · WO202609090001');

  // Navigate to work order detail
  page.openWorkOrder({ currentTarget: { dataset: { id: 101 } } });
  assert.equal(page.__navigations.length, 1);
  assert.match(page.__navigations[0].url, /work-order-detail\/index\?masterId=10&masterOrderNo=MWO202609090001&workOrderId=101/);
});

test('work-order-detail page parses 6 metrics, formats PU cards, and filters units', async () => {
  const filePath = path.join(__dirname, '../pages/production/work-order-detail/index.js');
  const mockProduction = {
    workOrder: async () => ({
      data: {
        id: 101,
        work_order_no: 'WO202609090001',
        product: { item_name: '智能装配工作站', specification: 'ZW-1000', item_code: 'FG-001' },
        execution_summary: {
          quantity: { planned_qty: 6, completed_qty: 4, in_progress_qty: 1, waiting_qty: 1, exception_qty: 0, unit_name: '台' },
          tasks: { completed: 12, total: 18 },
        },
      },
    }),
    masterOrderUnits: async () => ({
      current_page: 1,
      last_page: 1,
      data: [
        { id: 1, unit_no: 'PU001', status: 'PROCESSING', execution: { current_operation: { name: '焊接', sequence: 3, total: 6, status: 'IN_PROGRESS' }, current_task: { task_no: 'PT007' }, kitting: { status: 'CONFIRMED' }, previous_handover: { status: 'RECEIVED' } } },
        { id: 2, unit_no: 'PU002', status: 'PROCESSING', execution: { current_operation: { name: '打磨', sequence: 2, total: 6, status: 'WAIT_MATERIAL' }, current_task: { task_no: 'PT008' }, kitting: { status: 'NOT_CONFIRMED' }, previous_handover: { status: 'RECEIVED' } } },
        { id: 3, unit_no: 'PU003', status: 'WAITING', execution: { current_operation: { name: '总装', sequence: 1, total: 6, status: 'WAIT_PREVIOUS' }, current_task: { task_no: 'PT009' }, kitting: { status: 'NOT_REQUIRED' }, previous_handover: { status: 'NOT_REQUIRED' } } },
      ],
    }),
  };

  const page = mountPage(filePath, mockProduction);
  page.onLoad({ masterId: '10', masterOrderNo: 'MWO202609090001', workOrderId: '101', workOrderNo: 'WO202609090001' });
  await page.load();

  assert.equal(page.data.masterOrderNo, 'MWO202609090001');
  assert.equal(page.data.workOrder.work_order_no, 'WO202609090001');
  assert.equal(page.data.workOrder.product_name, '智能装配工作站');
  assert.equal(page.data.workOrder.product_model, 'ZW-1000');

  // 6 metrics verification
  assert.equal(page.data.metrics.planned, 6);
  assert.equal(page.data.metrics.completed, 4);
  assert.equal(page.data.metrics.in_progress, 1);
  assert.equal(page.data.metrics.waiting, 1);
  assert.equal(page.data.metrics.exception, 0);
  assert.equal(page.data.metrics.tasks_completed, 12);
  assert.equal(page.data.metrics.tasks_total, 18);

  // PU cards verification
  assert.equal(page.data.units.length, 3);
  assert.equal(page.data.units[0].unit_no, 'PU001');
  assert.equal(page.data.units[0].statusLabel, '生产中');
  assert.equal(page.data.units[0].kittingLabel, '齐套');
  assert.equal(page.data.units[0].handoverLabel, '已交接');

  assert.equal(page.data.units[1].unit_no, 'PU002');
  assert.equal(page.data.units[1].statusLabel, '待齐套');
  assert.equal(page.data.units[1].kittingLabel, '未齐套');

  // Filter verification
  page.applyFilter({ currentTarget: { dataset: { key: 'WAIT_MATERIAL', label: '待齐套' } } });
  assert.equal(page.data.filteredUnits.length, 1);
  assert.equal(page.data.filteredUnits[0].unit_no, 'PU002');

  // Breadcrumb navigation back to master order
  page.navBackToMaster();

  // Navigation to unit detail
  page.openUnit({ currentTarget: { dataset: { unit: page.data.units[0] } } });
  assert.equal(page.__navigations.length, 1);
  assert.match(page.__navigations[0].url, /unit-detail\/index\?masterId=10&masterOrderNo=MWO202609090001&workOrderId=101&workOrderNo=WO202609090001&unitId=1&unitNo=PU001/);
});

test('unit-detail page displays PU Hero card and operations vertical timeline', async () => {
  const filePath = path.join(__dirname, '../pages/production/unit-detail/index.js');
  const mockProduction = {
    unit: async () => ({
      data: {
        id: 1,
        unit_no: 'PU001',
        status: 'IN_PROGRESS',
        product: { item_name: '智能装配工作站' },
        work_order: { id: 101, work_order_no: 'WO202609090001', production_master_order_id: 10 },
        current_operation: { name: '焊接', sequence: 2, total: 3, status: 'IN_PROGRESS' },
        execution: {
          current_operation: { name: '焊接', sequence: 2, total: 3, status: 'IN_PROGRESS' },
          current_task: { task_no: 'PT007', owner: { display_name: '张三' } },
          kitting: { status: 'CONFIRMED', label: '已齐套' },
          previous_handover: { status: 'RECEIVED', label: '已接收' },
        },
        operations: [
          { id: 1, sequence: 1, operation_name: '下料', task: { task_no: 'PT001' }, status: 'COMPLETED', status_label: '已完成', actual_labor_minutes: 35, started_at: '2026-09-09T08:10:00.000Z', completed_at: '2026-09-09T08:45:00.000Z' },
          { id: 2, sequence: 2, operation_name: '焊接', task: { task_no: 'PT007' }, status: 'IN_PROGRESS', status_label: '加工中', elapsed_seconds: 1396, started_at: '2026-09-09T09:18:00.000Z' },
          { id: 3, sequence: 3, operation_name: '打磨', task: { task_no: 'PT013' }, status: 'WAIT_HANDOVER', status_label: '待前序交接' },
        ],
      },
    }),
  };

  const page = mountPage(filePath, mockProduction);
  page.onLoad({
    masterId: '10',
    masterOrderNo: 'MWO202609090001',
    workOrderId: '101',
    workOrderNo: 'WO202609090001',
    unitId: '1',
    unitNo: 'PU001',
  });
  await page.load();

  assert.equal(page.data.unit.unit_no, 'PU001');
  assert.equal(page.data.unit.statusLabel, '生产中');
  assert.equal(page.data.unit.product_name, '智能装配工作站');
  assert.equal(page.data.unit.current_operation_name, '焊接');
  assert.equal(page.data.unit.current_pt_no, 'PT007');

  // Breadcrumb navigation back handlers
  page.navBackToWorkOrder();
  page.navBackToMaster();

  // Timeline operations verification
  const ops = page.data.unit.operations;
  assert.equal(ops.length, 3);

  // Step 1
  assert.equal(ops[0].operation_name, '下料');
  assert.equal(ops[0].statusLabel, '已完成');
  assert.equal(ops[0].statusPillClass, 'pill-green');
  assert.equal(ops[0].stepCircleClass, 'circle-green');
  assert.equal(ops[0].stepLineClass, 'line-green');
  assert.equal(ops[0].durationLabel, '实际用时');
  assert.equal(ops[0].durationText, '35分钟');
  assert.equal(ops[0].time1Value, '2026-09-09 08:10');
  assert.equal(ops[0].time2Value, '2026-09-09 08:45');

  // Step 2
  assert.equal(ops[1].operation_name, '焊接');
  assert.equal(ops[1].statusLabel, '加工中');
  assert.equal(ops[1].statusPillClass, 'pill-blue');
  assert.equal(ops[1].stepCircleClass, 'circle-blue');
  assert.equal(ops[1].stepLineClass, 'line-dashed');
  assert.equal(ops[1].durationLabel, '已用时');
  assert.equal(ops[1].durationText, '00:23:16');
  assert.equal(ops[1].time1Value, '2026-09-09 09:18');
  assert.equal(ops[1].time2Value, '—');

  // Step 3
  assert.equal(ops[2].operation_name, '打磨');
  assert.equal(ops[2].statusLabel, '待前序交接');
  assert.equal(ops[2].statusPillClass, 'pill-gray');
  assert.equal(ops[2].stepCircleClass, 'circle-gray');
});

test('work order detail and unit detail retain brand red primary color', () => {
  const woJson = JSON.parse(fs.readFileSync(path.join(__dirname, '../pages/production/work-order-detail/index.json'), 'utf8'));
  const unitJson = JSON.parse(fs.readFileSync(path.join(__dirname, '../pages/production/unit-detail/index.json'), 'utf8'));
  const woWxml = fs.readFileSync(path.join(__dirname, '../pages/production/work-order-detail/index.wxml'), 'utf8');
  const unitWxml = fs.readFileSync(path.join(__dirname, '../pages/production/unit-detail/index.wxml'), 'utf8');

  // Brand primary color #d81e06
  assert.equal(woJson.navigationBarBackgroundColor, '#d81e06');
  assert.equal(woJson.navigationBarTextStyle, 'white');
  assert.equal(unitJson.navigationBarBackgroundColor, '#d81e06');
  assert.equal(unitJson.navigationBarTextStyle, 'white');

  // Loading spinner should use brand red #d81e06
  assert.match(woWxml, /color="#d81e06"/);
  assert.match(unitWxml, /color="#d81e06"/);
});

test('work order detail styles adhere to responsive design rules', () => {
  const masterWxss = fs.readFileSync(path.join(__dirname, '../pages/production/master-detail/index.wxss'), 'utf8');
  const woWxss = fs.readFileSync(path.join(__dirname, '../pages/production/work-order-detail/index.wxss'), 'utf8');
  const unitWxss = fs.readFileSync(path.join(__dirname, '../pages/production/unit-detail/index.wxss'), 'utf8');

  // Mandatory responsive design rules check
  for (const [name, wxss] of [['master-detail', masterWxss], ['work-order-detail', woWxss], ['unit-detail', unitWxss]]) {
    assert.match(wxss, /box-sizing:\s*border-box/, `${name} must enforce box-sizing: border-box`);
    assert.match(wxss, /min-width:\s*0/, `${name} must include min-width: 0 flex adaptation`);
    assert.match(wxss, /flex-shrink:\s*0/, `${name} must protect tags and badges from squeezing`);
    assert.match(wxss, /@media screen and \(max-width:\s*360px\)/, `${name} must have small screen media query`);
  }
});

test('unit detail and work order detail have clean breadcrumb separators and non-overlapping hero grid', () => {
  const woWxml = fs.readFileSync(path.join(__dirname, '../pages/production/work-order-detail/index.wxml'), 'utf8');
  const unitWxml = fs.readFileSync(path.join(__dirname, '../pages/production/unit-detail/index.wxml'), 'utf8');
  const unitWxss = fs.readFileSync(path.join(__dirname, '../pages/production/unit-detail/index.wxss'), 'utf8');

  // Breadcrumbs must NOT contain unescaped &gt; literals
  assert.ok(!woWxml.includes('&gt;'), 'work-order-detail must not output literal &gt; in breadcrumb');
  assert.ok(!unitWxml.includes('&gt;'), 'unit-detail must not output literal &gt; in breadcrumb');

  // unit-detail hero card has structured info section and 3-column status strip
  assert.match(unitWxml, /class="hero-info-section"/, 'unit-detail must have structured hero-info-section');
  assert.match(unitWxml, /class="hero-status-strip"/, 'unit-detail must have symmetrical hero-status-strip');
  assert.match(unitWxml, /class="step-inner-box"/, 'unit-detail must have step-inner-box');
  assert.match(unitWxml, /class="time-item"/, 'unit-detail must have vertical time-item rows');
  assert.match(unitWxss, /\.hero-info-section/, 'hero-info-section style must exist');
  assert.match(unitWxss, /\.hero-status-strip/, 'hero-status-strip style must exist');
  assert.match(unitWxss, /\.step-inner-box/, 'step-inner-box style must exist');
  assert.match(unitWxss, /\.time-item/, 'time-item style must exist');
  assert.match(unitWxss, /\.breadcrumb-bar\s*\{[^}]*overflow-x:\s*auto/, 'breadcrumb-bar must scroll horizontally without truncation');
});

test('detail runtime scripts contain no fixed business fallback fixtures', () => {
  const scripts = [
    '../pages/production/master-detail/index.js',
    '../pages/production/work-order-detail/index.js',
    '../pages/production/unit-detail/index.js',
  ].map(relative => fs.readFileSync(path.join(__dirname, relative), 'utf8')).join('\n');
  for (const forbidden of [
    'DEFAULT_MOCK', 'MWO202609090001', 'WO202609090001', 'PU001', 'PT007',
    '智能装配工作站', '宁波星辉自动化', '首批先交4台', '00:23:16', '2026-09-09 08:10',
  ]) assert.ok(!scripts.includes(forbidden), `runtime script must not contain fixed fixture: ${forbidden}`);
});
