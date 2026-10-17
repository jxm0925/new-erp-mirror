const erpRequest = require('../utils/erp-request');

function login(username, password) {
  return erpRequest.request({
    path: 'auth/login',
    method: 'POST',
    data: { username, password },
    authToken: '',
    loading: false,
  });
}

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

function clearSession() {
  wx.removeStorageSync(erpRequest.ERP_TOKEN_KEY);
  wx.removeStorageSync('erp_user');
  wx.removeStorageSync('erp_permissions');
}

function logout() {
  const token = wx.getStorageSync(erpRequest.ERP_TOKEN_KEY);
  const request = token
    ? erpRequest.request({ path: 'auth/logout', method: 'POST', authToken: token, loading: false })
    : Promise.resolve();
  return request
    .catch(() => null)
    .then(clearSession);
}

function logoutAll() {
  return logout().then(() => {
    wx.removeStorageSync('token');
    wx.removeStorageSync('userInfo');
  });
}

module.exports = { login, sso, persistSession, clearSession, me, logout, logoutAll };
