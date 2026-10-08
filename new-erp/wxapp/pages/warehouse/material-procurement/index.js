const warehouse = require('../../../services/warehouse');
const ui = require('../../../utils/warehouse-page');
const writes = require('../../../utils/material-preparation');
const endpoint = 'production/material-procurement-requests';
Page({
  data: { mode: 'manual', order: null, lines: [], expectedDate: '', remark: '', busy: false, error: '', pending: false, canSubmit: false,
    pickerOpen: false, pickerMode: '', pickerTitle: '', pickerSelected: [], pickerMultiple: false, result: null },
  onLoad() { this.setData({ canSubmit: ui.permissions()('production.material_procurement.create'), pending: !!writes.pending(endpoint) }); },
  changeMode(event) { if (!this.data.busy) this.setData({ mode: event.currentTarget.dataset.mode, order: null }); },
  chooseOrder() { this.setData({ pickerOpen: true, pickerMode: 'procurement-order', pickerTitle: '选择来源订单', pickerSelected: this.data.order ? [this.data.order] : [], pickerMultiple: false }); },
  chooseItems() { this.setData({ pickerOpen: true, pickerMode: 'procurement-item', pickerTitle: '选择缺料物料', pickerSelected: this.data.lines, pickerMultiple: true }); },
  acceptPicker(event) {
    if (this.data.pickerMode === 'procurement-order') this.setData({ order: event.detail.rows[0] });
    else {
      const previous = Object.fromEntries(this.data.lines.map(row => [row.id, row]));
      this.setData({ lines: event.detail.rows.map(row => Object.assign({}, row, previous[row.id], { request_qty: previous[row.id] ? previous[row.id].request_qty : '' })) });
    }
    this.closePicker();
  },
  closePicker() { this.setData({ pickerOpen: false }); },
  removeItem(event) { this.setData({ lines: this.data.lines.filter(row => row.id !== Number(event.currentTarget.dataset.id)) }); },
  quantityInput(event) { const index = this.data.lines.findIndex(row => row.id === Number(event.currentTarget.dataset.id)); if (index >= 0) this.setData({ [`lines[${index}].request_qty`]: event.detail.value }); },
  dateInput(event) { this.setData({ expectedDate: event.detail.value }); },
  remarkInput(event) { this.setData({ remark: event.detail.value }); },
  submit() {
    if (this.data.busy || !this.data.canSubmit) return Promise.resolve();
    const { lines, mode, order, remark, expectedDate } = this.data;
    if (!this.data.pending && (!lines.length || lines.length > 100 || !remark.trim() || (mode === 'order' && !order)
      || lines.some(row => !/^\d+(\.\d{1,4})?$/.test(row.request_qty) || Number(row.request_qty) <= 0 || !row.unit_name))) {
      this.setData({ error: '请选择来源和缺料物料，填写有效数量及用途。' }); return Promise.resolve();
    }
    this.setData({ busy: true, error: '' });
    return writes.submit(endpoint, { sales_order_id: mode === 'order' && order ? order.id : undefined, expected_date: expectedDate || undefined,
      remark: remark.trim(), items: lines.map(row => ({ item_id: row.id, request_qty: row.request_qty })) }).then(response => {
      this.setData({ result: response.data, lines: [], order: null, remark: '', expectedDate: '' });
      wx.showToast({ title: response.recoveredCommand ? '已核对上次提交' : '采购需求已提交', icon: 'success' });
    }).catch(error => this.setData({ error: error.message })).finally(() => this.setData({ busy: false, pending: !!writes.pending(endpoint) }));
  },
});
