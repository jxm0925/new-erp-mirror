const { formatTime } = require('./util');
function dateTime(value) {
  if (!value) return '';
  const text = String(value);
  if (/(Z|[+-]\d{2}:?\d{2})$/.test(text)) { const date = new Date(text); if (!Number.isNaN(date.getTime())) return formatTime(date).replace(/\//g, '-'); }
  return text.replace('T', ' ').slice(0, 19);
}
function permissions() {
  const value = wx.getStorageSync('erp_permissions') || [];
  return code => Array.isArray(value) ? value.includes(code) : value[code] === true;
}
function quantity(value) {
  if (value === null || value === undefined || value === '') return '';
  return String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}
function money(value) {
  if (value === null || value === undefined || value === '') return '';
  const amount = Number(value);
  return Number.isFinite(amount) ? `¥${amount.toFixed(4)}` : '';
}
function rows(response) {
  const body = response && response.data && !Array.isArray(response.data) ? response.data : response;
  const meta = body.meta || body;
  return { rows: body.data || [], page: Number(meta.current_page || 1), total: Number(meta.total || 0), lastPage: Number(meta.last_page || Math.max(1, Math.ceil(Number(meta.total || 0) / Number(meta.per_page || 10)))) };
}
function decorate(row) {
  return Object.assign({}, row, { quantityText: quantity(row.quantity), dateText: String(row.sort_at || '').slice(0, 10),
    buttonText: row.status_group === 'completed' ? '查看记录' : '查看并办理' });
}
function openDocument(row) {
  if (['mfg_receipt', 'cutting_remnant', 'inventory_transfer', 'material_physical_transfer'].includes(row.kind)) return wx.navigateTo({ url: `/pages/warehouse/inbound/index?kind=${row.kind}&id=${Number(row.id)}` });
  if (row.kind === 'stocktake') return wx.navigateTo({ url: `/pages/warehouse/stocktake/index?id=${Number(row.id)}` });
  if (['material_preparation', 'material_delivery', 'material_return'].includes(row.kind)) {
    return wx.navigateTo({ url: `/pages/warehouse/material/index?kind=${row.kind}&id=${Number(row.id)}&parent=${Number(row.parent_id || row.work_order_id || 0)}` });
  }
  const common = `id=${Number(row.id)}&parent=${Number(row.parent_id)}&kind=${row.kind}&stage=${row.stage}&completed=${row.status_group === 'completed' ? '1' : '0'}`;
  return wx.navigateTo({ url: `/pages/warehouse/document/index?${common}` });
}
function errorText(error) { return error && error.message ? error.message : '加载失败，请稍后重试'; }
function resumingScan(host) {
  const returning = !!(host._warehouseScanReturning || host._warehouseScanActive);
  if (returning) host._warehouseScanReturning = false;
  return returning;
}
function back() { if (getCurrentPages().length > 1) wx.navigateBack(); else wx.redirectTo({ url: '/pages/production/hub/index' }); }
function modal(page, options) {
  const dlg = page && typeof page.selectComponent === 'function' ? page.selectComponent('#prodDialog') : null;
  if (dlg && typeof dlg.show === 'function') return dlg.show(options);
  return wx.showModal(options);
}
module.exports = { permissions, quantity, money, dateTime, rows, decorate, openDocument, errorText, resumingScan, back, modal };
