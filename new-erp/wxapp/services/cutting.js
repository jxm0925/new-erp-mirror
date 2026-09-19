const erpRequest = require('../utils/erp-request');
const safeCommand = require('../utils/cutting-command');

function get(path, query, options) {
  return erpRequest.request(Object.assign({ path, query }, options || {}));
}

function command(path, data, commandPrefix) {
  return safeCommand.write(path, data, commandPrefix || 'cutting');
}

module.exports = {
  createOrder: (data) => command('production/cutting/orders', Object.assign({ expected_version: 0 }, data || {}), 'cut-create'),
  workerInputs: (query) => get('production/cutting/worker-inputs', query || {}),
  workerInputCategories: (query) => get('production/cutting/worker-input-categories', query || {}),
  workerOutputs: (query) => get('production/cutting/worker-outputs', query || {}),
  workerCategories: (query) => get('production/cutting/worker-output-categories', query || {}),
  listDemands: (query) => get('production/cutting/demands', query || {}),
  publishOrder: (data) => command('production/cutting/orders/publish', Object.assign({ expected_version: 0 }, data || {}), 'cut-publish'),

  listOrders: (query) => get('production/cutting/orders', query || {}),
  orderExecution: (id, query) => get(`production/cutting/orders/${id}/execution`, query || {}),
  listTasks: (query) => get('production/cutting/tasks', query || {}),
  taskExecution: (id, query) => get(`production/cutting/tasks/${id}`, query || {}),
  listInputs: (id, query) => get(`production/cutting/orders/${id}/input-candidates`, query || {}),
  selectorCategories: (id, query) => get(`production/cutting/orders/${id}/selector-categories`, query || {}),
  allowedOutputs: (id, query) => get(`production/cutting/orders/${id}/allowed-outputs`, query || {}),
  settlementExecution: (id, query) => get(`production/cutting/settlements/${id}/execution`, query || {}),
  reserve: (id, data) => command(`production/cutting/orders/${id}/reserve`, data || {}, 'cut-reserve'),
  issue: (id, data) => command(`production/cutting/orders/${id}/issue`, data || {}, 'cut-issue'),
  issuePhysicals: (id, data) => command(`production/cutting/orders/${id}/issue-physicals`, data || {}, 'cut-issue-multi'),
  markFirstCut: (id, data) => command(`production/cutting/settlements/${id}/first-cut`, data || {}, 'cut-first'),
  returnOriginal: (id, data) => command(`production/cutting/settlements/${id}/return-original`, data || {}, 'cut-return'),
  confirmSettlement: (id, data) => command(`production/cutting/settlements/${id}/confirm`, data || {}, 'cut-confirm'),
  inspectResult: (id, data) => command(`production/cutting/results/${id}/quality-inspect`, data || {}, 'cut-inspect'),
  returnForEdit: (id, data) => command(`production/cutting/settlements/${id}/return-for-edit`, data || {}, 'cut-edit'),
  dispatchRoute: (id, data) => command(`production/cutting/routes/${id}/dispatch`, data || {}, 'cut-dispatch'),
  warehouseRoute: (id, data) => command(`production/cutting/routes/${id}/warehouse`, data || {}, 'cut-warehouse'),
  claimTask: (id, data) => command(`production/cutting/tasks/${id}/claim`, data || {}, 'cut-claim'),
  startTask: (id, data) => command(`production/cutting/tasks/${id}/start`, data || {}, 'cut-start'),
  pauseTask: (id, data) => command(`production/cutting/tasks/${id}/pause`, data || {}, 'cut-pause'),
  resumeTask: (id, data) => command(`production/cutting/tasks/${id}/resume`, data || {}, 'cut-resume'),
  finishTask: (id, data) => command(`production/cutting/tasks/${id}/finish`, data || {}, 'cut-finish'),
  addCollaborators: (id, data) => command(`production/cutting/tasks/${id}/collaborators`, data || {}, 'cut-collaborators'),
  leaveCollaboration: (id, data) => command(`production/cutting/tasks/${id}/collaborators/leave`, data || {}, 'cut-leave'),
  startCollaborator: (id, data) => command(`production/cutting/tasks/${id}/collaborator-labor/start`, data || {}, 'cut-co-start'),
  pauseCollaborator: (id, data) => command(`production/cutting/tasks/${id}/collaborator-labor/pause`, data || {}, 'cut-co-pause'),
  closeOrder: (id, data) => command(`production/cutting/orders/${id}/close`, data || {}, 'cut-close'),
  cancelOrder: (id, data) => command(`production/cutting/orders/${id}/cancel`, data || {}, 'cut-cancel'),
  saveSettlement: (id, data) => safeCommand.write(`production/cutting/settlements/${id}/results`, data || {}, 'cut-save', 'PUT'),
  submitSettlement: (id, data) => command(`production/cutting/settlements/${id}/submit`, data || {}, 'cut-submit'),
  splitRoutes: (id, data) => safeCommand.write(`production/cutting/results/${id}/routes`, data || {}, 'cut-split', 'PUT'),
  handoverTargets: (id, query) => get(`production/cutting/results/${id}/handover-targets`, query || {}),
  pendingHandovers: (query) => get('production/cutting/handovers/pending', query || {}),
  acceptHandover: (id, data) => command(`production/cutting/handovers/${id}/accept`, data || {}, 'cut-accept'),
  rejectHandover: (id, data) => command(`production/cutting/handovers/${id}/reject`, data || {}, 'cut-reject'),
};
