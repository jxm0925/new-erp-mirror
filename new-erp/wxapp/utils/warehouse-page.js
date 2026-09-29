function permissions() {
  const value = wx.getStorageSync('erp_permissions') || [];
  return code => Array.isArray(value) ? value.includes(code) : value[code] === true;
}
function quantity(value) {
  if (value === null || value === undefined || value === '') return '';
  return String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}
function rows(response) {
  const body = response && response.data && !Array.isArray(response.data) ? response.data : response;
  const meta = body.meta || body;
  return { rows: body.data || [], page: Number(meta.current_page || 1), total: Number(meta.total || 0), lastPage: Number(meta.last_page || Math.max(1, Math.ceil(Number(meta.total || 0) / Number(meta.per_page || 10)))) };
}
function decorate(row) {
  return Object.assign({}, row, { quantityText: quantity(row.quantity), dateText: String(row.sort_at || '').slice(0, 10),
    buttonText: row.status_group === 'completed' ? '查看记录' : row.kind === 'picking' ? '查看拣货' : '查看并办理' });
}
function openDocument(row) {
  const common = `id=${Number(row.id)}&parent=${Number(row.parent_id)}&kind=${row.kind}&stage=${row.stage}&completed=${row.status_group === 'completed' ? '1' : '0'}`;
  if (row.kind === 'remnant') return wx.navigateTo({ url: `/pages/warehouse/remnant/index?${common}` });
  if (row.kind === 'picking') return wx.navigateTo({ url: `/pages/warehouse/picking/index?${common}` });
  return wx.navigateTo({ url: `/pages/warehouse/document/index?${common}` });
}
function errorText(error) { return error && error.message ? error.message : '加载失败，请稍后重试'; }
function back() { if (getCurrentPages().length > 1) wx.navigateBack(); else wx.redirectTo({ url: '/pages/warehouse/workbench/index' }); }
module.exports = { permissions, quantity, rows, decorate, openDocument, errorText, back };
