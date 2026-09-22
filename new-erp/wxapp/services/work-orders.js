const request = require('../utils/erp-request');
const commands = require('../utils/cutting-command');

module.exports = {
  options: (type, query) => request.request({ path: `shopfloor/options/${type}`, query }),
  reserve: session => request.request({ path: 'document-numbers/reserve', method: 'POST', data: { document_type: 'work_order', creation_session_id: session, page: 'wxapp/production/work-order-form' } }),
  detail: id => request.request({ path: `production/work-orders/${id}` }),
  gate: id => request.request({ path: `production/work-orders/${id}/release-gate` }),
  save: (id, data) => commands.write(`production/work-orders${id ? '/' + id : ''}`, data, 'work-order-save', id ? 'PUT' : 'POST'),
  transition: (id, action, data) => commands.write(`production/work-orders/${id}/${action}`, data, `work-order-${action}`),
};
