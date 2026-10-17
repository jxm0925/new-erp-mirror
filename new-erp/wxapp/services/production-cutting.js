const request = require('../utils/erp-request');
const command = require('../utils/cutting-command');
function base(taskId, type, targetId) { return `production/tasks/${taskId}/targets/${type}/${targetId}/cutting`; }
module.exports = {
  show: (taskId, type, id, query) => request.request({ path: base(taskId, type, id), query }),
  materials: (taskId, type, id, query) => request.request({ path: `${base(taskId, type, id)}/materials`, query }),
  prepare: (taskId, type, id, data) => command.write(`${base(taskId, type, id)}/prepare`, data, 'operation-cutting-prepare'),
  useMaterial: (taskId, type, id, data) => command.write(`${base(taskId, type, id)}/materials`, data, 'operation-cutting-use'),
  save: (taskId, type, id, batchId, data) => command.write(`${base(taskId, type, id)}/batches/${batchId}`, data, 'operation-cutting-save', 'PUT'),
  pendingSaves: (taskId, type, id) => command.pendingCommands(`${base(taskId, type, id)}/batches/`, 'PUT'),
  recoverSave: (taskId, type, id, batchId) => {
    const path = `${base(taskId, type, id)}/batches/${batchId}`; const payload = command.pendingCommand(path, 'PUT');
    return payload ? command.write(path, payload, 'operation-cutting-save', 'PUT') : Promise.reject(new Error('原下料登记命令不存在，请重新查询'));
  },
  finish: (taskId, type, id, data) => command.write(`${base(taskId, type, id)}/finish`, data, 'operation-cutting-finish'),
};
