const erpRequest = require('../utils/erp-request');

function get(path, query, options) {
  return erpRequest.request(Object.assign({ path, query }, options || {}));
}

function command(path, data, commandPrefix) {
  return erpRequest.write(path, data, { commandPrefix });
}

module.exports = {
  taskPool: (query) => get('production/tasks', Object.assign({ view: 'pool' }, query || {})),
  myTasks: (query) => get('production/tasks', Object.assign({ view: 'owned' }, query || {})),
  collaborations: (query) => get('production/tasks', Object.assign({ view: 'collaboration' }, query || {})),
  task: (id) => get(`production/tasks/${id}`),
  claimTask: (id, version, clientCommandId) => command(`production/tasks/${id}/claim`, {
    expected_version: version,
    client_command_id: clientCommandId,
  }, 'claim'),
  joinTask: (id, data) => command(`production/tasks/${id}/collaborators/join`, data, 'join'),
  leaveTask: (id, data) => command(`production/tasks/${id}/collaborators/leave`, data, 'leave'),
  kittingRequirements: (taskId, targetType, targetId) => get(`production/tasks/${taskId}/targets/${targetType}/${targetId}/kitting-requirements`),
  confirmKitting: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/confirm-kitting`, data, 'kitting'),
  start: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/start`, data, 'start'),
  pause: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/pause`, data, 'pause'),
  resume: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/resume`, data, 'resume'),
  complete: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/complete`, data, 'complete'),
  deliveries: (query) => get('production/material-deliveries', query),
  delivery: (id) => get(`production/material-deliveries/${id}`),
  dispatchDelivery: (id, data) => command(`production/material-deliveries/${id}/dispatch`, data, 'dispatch'),
  deliverDelivery: (id, data) => command(`production/material-deliveries/${id}/deliver`, data, 'deliver'),
  receiveDelivery: (id, data) => command(`production/material-deliveries/${id}/receive`, data, 'receive'),
  pendingHandovers: (query) => get('production/handovers/pending', query),
  acceptHandover: (id, data) => command(`production/handovers/${id}/accept`, data, 'handover-accept'),
  rejectHandover: (id, data) => command(`production/handovers/${id}/reject`, data, 'handover-reject'),
  requestSupplement: (data) => command('production/material-supplements', data, 'supplement'),
  requestReturn: (data) => command('production/material-returns', data, 'return'),
  workOrderUnits: (workOrderId, query) => get(`production/work-orders/${workOrderId}/units`, query),
  unit: (id) => get(`production/units/${id}`),
  trace: (keyword) => get('production/trace', { keyword }),
};
