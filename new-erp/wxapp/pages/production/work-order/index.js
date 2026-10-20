const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const UNIT_TAG = { waiting: 'gray', in_progress: 'amber', rework: 'red', completed: 'blue', stored: 'green', scrapped: 'gray', cancelled: 'gray' };

Page({
  data: { id: 0, wo: null, loading: true, error: '' },
  onLoad(options) { this.setData({ id: Number(options.id || 0) }); this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load() {
    this.setData({ loading: true, error: '' });
    return production.workOrder(this.data.id).then(response => {
      const wo = response.data || response;
      const planned = Number(wo.planned_qty || 0);
      const ops = (wo.operations || []).filter(op => op.execution_context === 'production').map(op => Object.assign({}, op, {
        doneText: view.quantity(op.completed_qty), percent: planned > 0 ? Math.min(100, Math.round(Number(op.completed_qty || 0) / planned * 100)) : 0,
      }));
      const units = (wo.units || []).map(u => Object.assign({}, u, {
        short: u.serial_no ? String(u.serial_no).slice(-4) : String(u.unit_no),
        tag: UNIT_TAG[u.status] || 'gray',
        text: u.status_label + (u.current_operation_name && ['in_progress', 'rework'].includes(u.status) ? ' · ' + u.current_operation_name : ''),
      }));
      wx.setNavigationBarTitle({ title: wo.work_order_no || '工单' });
      this.setData({ wo: Object.assign({}, wo, { plannedText: view.quantity(wo.planned_qty), dueText: String(wo.required_date || wo.planned_finish_date || '').slice(5, 10),
        productText: wo.product_name || wo.item_name }), ops, units, loading: false });
    }).catch(error => this.setData({ loading: false, error: view.errorText(error) }));
  },
});
