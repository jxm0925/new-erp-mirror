const production = require('../services/production');

// An unacknowledged write keeps its original command and payload across page reloads.
function execute(key, payload, send) {
  const user = wx.getStorageSync('erp_user') || {};
  const storageKey = 'erp_production_decision_' + Number(user.legacy_id || 0) + '_' + key;
  const prior = wx.getStorageSync(storageKey);
  const command = prior && prior.payload ? prior : {
    payload: Object.assign({}, payload, { client_command_id: production.newCommandId('production-decision') }),
  };
  wx.setStorageSync(storageKey, command);
  return Promise.resolve().then(() => send(command.payload)).then(result => {
    wx.removeStorageSync(storageKey);
    return result;
  }).catch(error => {
    // Network/timeout and server failures can follow a committed transaction; replay the same command.
    if (error.errorCode !== 'network_error' && error.errorCode !== 'command_processing' && Number(error.statusCode || 0) < 500
        && Number(error.statusCode || 0) > 0) wx.removeStorageSync(storageKey);
    throw error;
  });
}

module.exports = { execute };
