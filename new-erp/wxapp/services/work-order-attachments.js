const request = require('../utils/erp-request');

function download(workOrderId, attachment) {
  const token = wx.getStorageSync(request.ERP_TOKEN_KEY);
  const name = String(attachment.original_name || 'attachment').replace(/[\\/:*?"<>|\x00-\x1f]/g, '_');
  // Preserve the original extension for desktop Save As and native CAD export.
  const filePath = wx.env && wx.env.USER_DATA_PATH ? `${wx.env.USER_DATA_PATH}/wo-${workOrderId}-${attachment.id}-${name}` : undefined;
  return new Promise((resolve, reject) => wx.downloadFile({
    url: request.buildUrl(`production/work-orders/${workOrderId}/technical-attachments/${attachment.id}`),
    header: token ? { Authorization: `Bearer ${token}` } : {},
    filePath,
    success(result) {
      if (result.statusCode < 200 || result.statusCode >= 300 || !(result.filePath || result.tempFilePath)) {
        return reject(new Error(result.statusCode === 403 ? '没有查看该工单附件的权限' : '附件下载失败，请重试'));
      }
      resolve(result.filePath || result.tempFilePath);
    },
    fail: () => reject(new Error('附件下载失败，请检查网络')),
  }));
}

function nativeCall(method, options) {
  return new Promise((resolve, reject) => wx[method](Object.assign({}, options, {
    success: resolve,
    fail: error => /cancel/i.test(error.errMsg || '') ? resolve({ cancelled: true }) : reject(new Error(error.errMsg || '文件打开失败')),
  })));
}

async function open(workOrderId, attachment) {
  const filePath = await download(workOrderId, attachment);
  if (String(attachment.mime_type).startsWith('image/')) return nativeCall('previewImage', { current: filePath, urls: [filePath] });
  if (attachment.previewable && (attachment.mime_type === 'application/pdf' || /\.pdf$/i.test(attachment.original_name))) {
    return nativeCall('openDocument', { filePath, fileType: 'pdf', showMenu: true });
  }
  const platform = (wx.getDeviceInfo ? wx.getDeviceInfo() : wx.getSystemInfoSync()).platform;
  if (['windows', 'mac'].includes(platform) && typeof wx.saveFileToDisk === 'function') {
    return nativeCall('saveFileToDisk', { filePath });
  }
  // CAD cannot be opened by openDocument. Mobile export is a native user-operated
  // file picker: the application never selects a recipient or sends a message.
  if (typeof wx.shareFileMessage === 'function') return nativeCall('shareFileMessage', { filePath, fileName: attachment.original_name });
  throw new Error('当前微信版本不支持文件导出，请升级微信后重试');
}

module.exports = { open };
