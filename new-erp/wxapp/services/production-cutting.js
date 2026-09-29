const request = require('../utils/erp-request');
const command = require('../utils/cutting-command');
function base(taskId, type, targetId) { return `production/tasks/${taskId}/targets/${type}/${targetId}/cutting`; }
module.exports = {
  show: (taskId, type, id, query) => request.request({ path: base(taskId, type, id), query }),
  materials: (taskId, type, id, query) => request.request({ path: `${base(taskId, type, id)}/materials`, query }),
  prepare: (taskId, type, id, data) => command.write(`${base(taskId, type, id)}/prepare`, data, 'operation-cutting-prepare'),
  useMaterial: (taskId, type, id, data) => command.write(`${base(taskId, type, id)}/materials`, data, 'operation-cutting-use'),
  save: (taskId, type, id, batchId, data) => command.write(`${base(taskId, type, id)}/batches/${batchId}`, data, 'operation-cutting-save', 'PUT'),
  finish: (taskId, type, id, data) => command.write(`${base(taskId, type, id)}/finish`, data, 'operation-cutting-finish'),
};
