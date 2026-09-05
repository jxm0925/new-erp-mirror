const DEFAULT_ERP_API_BASE_URL = 'http://127.0.0.1:8012/api/v1/erp/';

function normalizeBaseUrl(value) {
  const baseUrl = String(value || DEFAULT_ERP_API_BASE_URL).trim();
  return baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
}

function getErpApiBaseUrl() {
  return normalizeBaseUrl(wx.getStorageSync('erp_api_base_url'));
}

module.exports = {
  DEFAULT_ERP_API_BASE_URL,
  getErpApiBaseUrl,
};
