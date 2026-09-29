const request = require('../utils/erp-request');
// Keep legacy pages from replaying create/edit/publish writes after management moved to PC.
const pcOnly = () => Promise.reject({ statusCode: 403, code: 'work_order_pc_only', message: '工单创建和发布请在电脑端办理' });

module.exports = {
  options: (type, query) => request.request({ path: `shopfloor/options/${type}`, query }),
  reserve: pcOnly,
  detail: id => request.request({ path: `production/work-orders/${id}` }),
  gate: id => request.request({ path: `production/work-orders/${id}/release-gate` }),
  save: pcOnly,
  savePlan: pcOnly,
  transition: pcOnly,
};
