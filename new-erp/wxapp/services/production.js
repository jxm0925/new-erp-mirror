// 车间报工（第 2 阶段）：生产任务列表、按台报工、协同人、拍照、工单只读。
const request = require('../utils/erp-request');
const base = 'production';
const get = (path, query) => request.request({ path: `${base}/${path}`, query: query || {}, loading: false });

function uploadPhoto(filePath) {
  const token = wx.getStorageSync(request.ERP_TOKEN_KEY);
  return new Promise((resolve, reject) => {
    wx.uploadFile({
      url: request.buildUrl(`${base}/tasks/photos`),
      filePath,
      name: 'file',
      header: Object.assign({ Accept: 'application/json' }, token ? { Authorization: `Bearer ${token}` } : {}),
      success(response) {
        let body = {};
        try { body = typeof response.data === 'string' ? JSON.parse(response.data) : (response.data || {}); } catch (_) { body = {}; }
        if (response.statusCode >= 200 && response.statusCode < 300 && body.data && body.data.url) resolve(body.data.url);
        else reject(new Error(body.message || '照片上传失败，请重试'));
      },
      fail(error) { reject(new Error(error.errMsg || '网络连接失败，照片没传上去')); },
    });
  });
}

module.exports = {
  tasks: query => get('tasks', query),
  task: id => get(`tasks/${Number(id)}`),
  board: query => get('board', query),
  serialLookup: query => get('serials/lookup', query),
  action: (id, payload) => request.write(`${base}/tasks/${Number(id)}/actions`, payload, { loading: false, commandPrefix: 'task' }),
  people: (id, query) => get(`tasks/${Number(id)}/people`, query),
  workOrder: id => get(`work-orders/${Number(id)}`),
  receiveMaterials: (workOrderId, operationId, payload) => request.write(`${base}/work-orders/${Number(workOrderId)}/operations/${Number(operationId)}/material-receipts`, payload || {}, { loading: true, commandPrefix: 'issue' }),
  returnOptions: (workOrderId, operationId) => get(`work-orders/${Number(workOrderId)}/operations/${Number(operationId)}/return-options`),
  createReturn: (workOrderId, operationId, payload) => request.write(`${base}/work-orders/${Number(workOrderId)}/operations/${Number(operationId)}/material-returns`, payload, { loading: true, commandPrefix: 'return' }),
  deliveryAction: (id, action, payload) => request.write(`${base}/material-deliveries/${Number(id)}/${action}`, payload || {}, { loading: true, commandPrefix: 'delivery' }),
  uploadPhoto,
};
