const erpRequest = require('../utils/erp-request');

function login(username, password) {
  return erpRequest.request({
    path: 'auth/login',
    method: 'POST',
    data: { username, password },
  }).then((result) => {
    wx.setStorageSync(erpRequest.ERP_TOKEN_KEY, result.token);
    wx.setStorageSync('erp_user', result.user || {});
    wx.setStorageSync('erp_permissions', result.permissions || []);
    return result;
  });
}

function me(options) {
  return erpRequest.request({ path: 'auth/me', loading: !(options && options.silent) });
}

function logout() {
  return erpRequest.request({ path: 'auth/logout', method: 'POST', loading: false })
    .catch(() => null)
    .then(() => {
      wx.removeStorageSync(erpRequest.ERP_TOKEN_KEY);
      wx.removeStorageSync('erp_user');
      wx.removeStorageSync('erp_permissions');
    });
}

module.exports = { login, me, logout };
