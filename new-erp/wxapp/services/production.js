const erpRequest = require('../utils/erp-request');

function get(path, query, options) {
  return erpRequest.request(Object.assign({ path, query }, options || {}));
}

function command(path, data, commandPrefix) {
  return erpRequest.write(path, data, { commandPrefix });
}

function previewSalesOrderAttachment(id, attachment) {
  const url = erpRequest.buildUrl(`sales/orders/attachments/${id}/preview`);
  const token = wx.getStorageSync(erpRequest.ERP_TOKEN_KEY);
  return new Promise((resolve, reject) => {
    wx.downloadFile({
      url,
      header: token ? { Authorization: `Bearer ${token}` } : {},
      success(response) {
        if (response.statusCode < 200 || response.statusCode >= 300) {
          reject(new Error('附件下载失败'));
          return;
        }
        const mime = String((attachment && attachment.mime_type) || '').toLowerCase();
        if (mime.startsWith('image/')) {
          wx.previewImage({ urls: [response.tempFilePath], current: response.tempFilePath, success: resolve, fail: reject });
        } else {
          wx.openDocument({ filePath: response.tempFilePath, showMenu: true, success: resolve, fail: reject });
        }
      },
      fail: reject,
    });
  });
}

module.exports = {
  newCommandId: prefix => erpRequest.createClientCommandId(prefix),
  masterOrders: (query) => get('production/master-orders', query || {}),
  masterOrder: (id) => get(`production/master-orders/${id}`),
  masterOrderWorkOrders: (id, query) => get(`production/master-orders/${id}/work-orders`, query || {}),
  masterOrderUnits: (id, query) => get(`production/master-orders/${id}/units`, query || {}),
  masterOrderFundingStatus: (id) => get(`production/master-orders/${id}/funding-status`),
  previewSalesOrderAttachment,
  workOrders: (query) => get('production/work-orders', query || {}),
  workOrder: (id) => get(`production/work-orders/${id}`),
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
  addCollaborators: (id, data) => command(`production/tasks/${id}/collaborators`, data, 'collaborators-add'),
  collaborationCandidates: (query) => get('user-directory/users', Object.assign({ scope: 'production', capability: 'collaborate', status: 'normal' }, query || {})),
  kittingRequirements: (taskId, targetType, targetId) => get(`production/tasks/${taskId}/targets/${targetType}/${targetId}/kitting-requirements`),
  materialOptions: (taskId, targetType, targetId, query) => get(`production/tasks/${taskId}/targets/${targetType}/${targetId}/material-options`, query),
  confirmKitting: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/confirm-kitting`, data, 'kitting'),
  start: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/start`, data, 'start'),
  restartRework: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/rework/start`, data, 'rework-start'),
  pause: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/pause`, data, 'pause'),
  resume: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/resume`, data, 'resume'),
  startCollaboratorLabor: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/collaborator-labor/start`, data, 'collaborator-labor-start'),
  pauseCollaboratorLabor: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/collaborator-labor/pause`, data, 'collaborator-labor-pause'),
  report: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/report`, data, 'report'),
  complete: (taskId, targetType, targetId, data) => command(`production/tasks/${taskId}/targets/${targetType}/${targetId}/complete`, data, 'complete'),
  completionPreflight: (workOrderId) => get(`production/work-orders/${workOrderId}/completion-preflight`),
  submitCompletion: (workOrderId, data) => command(`production/work-orders/${workOrderId}/completions`, data, 'work-order-completion'),
  outputs: (query) => get('production/outputs', query),
  output: (id) => get(`production/outputs/${id}`),
  inspectOutput: (id, data) => command(`production/outputs/${id}/quality-inspect`, data, 'output-quality'),
  warehouseOutput: (id, data) => command(`production/outputs/${id}/warehouse`, data, 'output-warehouse'),
  internalIssues: (query) => get('production/internal-issues', query),
  internalIssue: (id) => get(`production/internal-issues/${id}`),
  dispatchInternalIssue: (id, data) => command(`production/internal-issues/${id}/dispatch`, data, 'internal-issue-dispatch'),
  receiveInternalIssue: (id, data) => command(`production/internal-issues/${id}/receive`, data, 'internal-issue-receive'),
  supplements: (query) => get('production/material-supplements', query),
  supplement: (id) => get(`production/material-supplements/${id}`),
  decideSupplement: (id, data) => command(`production/material-supplements/${id}/decision`, data, 'supplement-decision'),
  materialReturns: (query) => get('production/material-returns', query),
  materialReturn: (id) => get(`production/material-returns/${id}`),
  receiveMaterialReturn: (id, data) => command(`production/material-returns/${id}/receive`, data, 'return-receive'),
  qualityMaterialReturn: (id, data) => command(`production/material-returns/${id}/quality`, data, 'return-quality'),
  pickingTasks: (query) => get('production/material-picking-tasks', query),
  pickingTask: (id) => get(`production/material-picking-tasks/${id}`),
  assignPickingTask: (id, data) => command(`production/material-picking-tasks/${id}/assign`, data, 'picking-assign'),
  startPickingTask: (id, data) => command(`production/material-picking-tasks/${id}/start`, data, 'picking-start'),
  confirmPickingTask: (id, data) => command(`production/material-picking-tasks/${id}/confirm`, data, 'picking-confirm'),
  cancelPickingTask: (id, data) => command(`production/material-picking-tasks/${id}/cancel`, data, 'picking-cancel'),
  warehouses: (query) => get('master/warehouses', query),
  locations: (query) => get('master/locations', query),
  deliveries: (query) => get('production/material-deliveries', query),
  delivery: (id) => get(`production/material-deliveries/${id}`),
  dispatchDelivery: (id, data) => command(`production/material-deliveries/${id}/dispatch`, data, 'dispatch'),
  deliverDelivery: (id, data) => command(`production/material-deliveries/${id}/deliver`, data, 'deliver'),
  receiveDelivery: (id, data) => command(`production/material-deliveries/${id}/receive`, data, 'receive'),
  cancelDelivery: (id, data) => command(`production/material-deliveries/${id}/cancel`, data, 'delivery-cancel'),
  deliveryWaves: (query) => get('production/delivery-waves', query || {}),
  createDeliveryWave: (data) => command('production/delivery-waves', data, 'delivery-wave'),
  configureDeliveryTrigger: (id, data) => command(`production/preparation-lines/${id}/delivery-trigger`, data, 'delivery-trigger'),
  manualReleaseDelivery: (id, data) => command(`production/preparation-lines/${id}/manual-release-delivery`, data, 'delivery-manual-release'),
  claimDeliveryTask: (id, data) => command(`production/delivery-tasks/${id}/pool-claim`, data, 'delivery-task-claim'),
  assignDeliveryTask: (id, data) => command(`production/delivery-tasks/${id}/dispatcher-assign`, data, 'delivery-task-assign'),
  transitionDeliveryTask: (id, data) => command(`production/delivery-tasks/${id}/transition`, data, 'delivery-task-transition'),
  pendingHandovers: (query) => get('production/handovers/pending', query),
  acceptHandover: (id, data) => command(`production/handovers/${id}/accept`, data, 'handover-accept'),
  rejectHandover: (id, data) => command(`production/handovers/${id}/reject`, data, 'handover-reject'),
  requestSupplement: (data) => command('production/material-supplements', data, 'supplement'),
  requestReturn: (data) => command('production/material-returns', data, 'return'),
  workOrderUnits: (workOrderId, query) => get(`production/work-orders/${workOrderId}/units`, query),
  unit: (id) => get(`production/units/${id}`),
  trace: (keyword) => get('production/trace', { keyword }),
};
