const erpRequest = require('../utils/erp-request');

function sso(ticket) {
  return erpRequest.request({
    path: 'auth/sso',
    method: 'POST',
    data: { ticket },
  });
}

function persistSession(result) {
  wx.setStorageSync(erpRequest.ERP_TOKEN_KEY, result.token);
  wx.setStorageSync('erp_user', result.user || {});
  wx.setStorageSync('erp_permissions', result.permissions || []);
  return result;
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

function logoutAll() {
  return logout().then(() => {
    wx.removeStorageSync('token');
    wx.removeStorageSync('userInfo');
  });
}

module.exports = { sso, persistSession, me, logout, logoutAll };
