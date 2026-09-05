const { getErpApiBaseUrl } = require('../config/erp');

const ERP_TOKEN_KEY = 'erp_token';

function buildUrl(path, query) {
  const normalizedPath = String(path || '').replace(/^\/+/, '');
  const entries = Object.keys(query || {})
    .filter((key) => query[key] !== undefined && query[key] !== null && query[key] !== '')
    .map((key) => `${encodeURIComponent(key)}=${encodeURIComponent(query[key])}`);
  return `${getErpApiBaseUrl()}${normalizedPath}${entries.length ? `?${entries.join('&')}` : ''}`;
}

function normalizeError(response, fallbackMessage) {
  const body = response && response.data ? response.data : {};
  const error = new Error(body.message || fallbackMessage || '请求失败，请稍后重试。');
  error.statusCode = response ? response.statusCode : 0;
  error.errorCode = body.error_code || 'request_failed';
  error.errors = body.errors || {};
  error.details = body.details || [];
  error.response = body;
  return error;
}

function request(options) {
  const opts = options || {};
  const token = wx.getStorageSync(ERP_TOKEN_KEY);
  const headers = Object.assign({
    'Content-Type': 'application/json',
    Accept: 'application/json',
  }, opts.header || {});

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (opts.loading !== false) {
    wx.showLoading({ title: opts.loadingTitle || '加载中...', mask: true });
  }

  return new Promise((resolve, reject) => {
    wx.request({
      url: buildUrl(opts.path, opts.query),
      data: opts.data || {},
      method: opts.method || 'GET',
      header: headers,
      timeout: opts.timeout || 15000,
      success(response) {
        if (response.statusCode >= 200 && response.statusCode < 300) {
          resolve(response.data);
          return;
        }

        if (response.statusCode === 401) {
          wx.removeStorageSync(ERP_TOKEN_KEY);
        }
        reject(normalizeError(response));
      },
      fail(error) {
        const requestError = new Error(error.errMsg || '网络连接失败，请检查网络后重试。');
        requestError.errorCode = 'network_error';
        requestError.originalError = error;
        reject(requestError);
      },
      complete() {
        if (opts.loading !== false) {
          wx.hideLoading();
        }
      },
    });
  });
}

function createClientCommandId(prefix) {
  const randomPart = Math.random().toString(36).slice(2, 10);
  return `${prefix || 'wx'}-${Date.now()}-${randomPart}`;
}

function write(path, data, options) {
  const opts = options || {};
  return request(Object.assign({}, opts, {
    path,
    method: opts.method || 'POST',
    data: Object.assign({}, data || {}, {
      client_command_id: (data && data.client_command_id) || createClientCommandId(opts.commandPrefix),
    }),
  }));
}

module.exports = {
  ERP_TOKEN_KEY,
  request,
  write,
  createClientCommandId,
  buildUrl,
};
