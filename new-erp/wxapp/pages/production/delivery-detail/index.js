const production = require('../../../services/production');

const STATUS_LABELS = {
  READY: '待发出', IN_TRANSIT: '配送中', DELIVERED: '待签收', RECEIVED: '已签收', CANCELLED: '已取消'
};

function number(value) { return Number(value || 0); }

Page({
  data: { id: 0, mode: 'delivery', loading: true, busy: false, delivery: null, lines: [] },

  onLoad(options) {
    const mode = options.mode === 'receipt' ? 'receipt' : 'delivery';
    this.setData({ id: Number(options.id || 0), mode });
    wx.setNavigationBarTitle({ title: mode === 'receipt' ? '物料签收' : '物料配送' });
    this.load();
  },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },

  load() {
    this.setData({ loading: true });
    return production.delivery(this.data.id).then((response) => {
      const delivery = response.data || response;
      delivery.statusLabel = STATUS_LABELS[delivery.status] || delivery.status || '-';
      delivery.deliveryUserLabel = delivery.delivery_user_legacy_id ? `#${delivery.delivery_user_legacy_id}` : '-';
      const lines = (delivery.lines || []).map((line) => {
        const remaining = Math.max(0, number(line.delivery_qty) - number(line.received_qty) - number(line.rejected_qty));
        const requirement = line.requirement || {};
        return Object.assign({}, line, {
          itemCode: requirement.component_item_code_snapshot || '-',
          itemName: requirement.component_item_name_snapshot || '-',
          remaining,
          acceptedInput: remaining,
          rejectedInput: 0,
          rejectReason: ''
        });
      });
      this.setData({ delivery, lines, loading: false });
    }).catch((error) => {
      this.setData({ loading: false, delivery: null, lines: [] });
      wx.showToast({ title: error.message, icon: 'none' });
    });
  },

  updateAccepted(event) {
    this.updateLine(event.currentTarget.dataset.index, 'acceptedInput', event.detail.value);
  },
  updateRejected(event) {
    this.updateLine(event.currentTarget.dataset.index, 'rejectedInput', event.detail.value);
  },
  updateReason(event) {
    this.updateLine(event.currentTarget.dataset.index, 'rejectReason', event.detail.value);
  },
  updateLine(index, key, value) {
    this.setData({ [`lines[${index}].${key}`]: value });
  },
  acceptAll() {
    this.setData({ lines: this.data.lines.map((line) => Object.assign({}, line, { acceptedInput: line.remaining, rejectedInput: 0, rejectReason: '' })) });
  },

  dispatch() {
    if (this.data.busy) return;
    const delivery = this.data.delivery;
    const erpUser = wx.getStorageSync('erp_user') || {};
    const deliveryUserId = delivery.delivery_user_legacy_id || erpUser.legacy_id || erpUser.id;
    this.setData({ busy: true });
    production.dispatchDelivery(delivery.id, { expected_version: delivery.business_version, delivery_user_legacy_id: deliveryUserId })
      .then(() => { wx.showToast({ title: '配送单已发出', icon: 'success' }); return this.load(); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' }))
      .finally(() => this.setData({ busy: false }));
  },
  markDelivered() {
    if (this.data.busy) return;
    const delivery = this.data.delivery;
    this.setData({ busy: true });
    production.deliverDelivery(delivery.id, { expected_version: delivery.business_version })
      .then(() => { wx.showToast({ title: '已确认送达', icon: 'success' }); return this.load(); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' }))
      .finally(() => this.setData({ busy: false }));
  },
  confirmReceipt() {
    if (this.data.busy) return;
    const lines = this.data.lines.map((line) => ({
      delivery_line_id: line.id,
      accepted_qty: number(line.acceptedInput),
      rejected_qty: number(line.rejectedInput),
      reject_reason: String(line.rejectReason || '').trim()
    })).filter((line) => line.accepted_qty > 0 || line.rejected_qty > 0);
    if (!lines.length) return wx.showToast({ title: '请填写本次签收或拒收数量', icon: 'none' });
    const invalid = this.data.lines.some((line) => number(line.acceptedInput) + number(line.rejectedInput) > line.remaining);
    if (invalid) return wx.showToast({ title: '签收与拒收数量不能超过剩余数量', icon: 'none' });
    const missingReason = lines.some((line) => line.rejected_qty > 0 && !line.reject_reason);
    if (missingReason) return wx.showToast({ title: '存在拒收数量时必须填写原因', icon: 'none' });
    this.setData({ busy: true });
    production.receiveDelivery(this.data.delivery.id, { expected_version: this.data.delivery.business_version, lines })
      .then(() => { wx.showToast({ title: '物料签收成功', icon: 'success' }); setTimeout(() => wx.navigateBack(), 500); })
      .catch((error) => wx.showToast({ title: error.message, icon: 'none' }))
      .finally(() => this.setData({ busy: false }));
  }
});
