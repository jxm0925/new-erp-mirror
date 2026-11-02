const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const UNIT_TAG = { waiting: 'gray', in_progress: 'amber', rework: 'red', completed: 'blue', stored: 'green', scrapped: 'gray', cancelled: 'gray' };

function buildSpecText(obj) {
  if (!obj) return '';
  if (obj.specification) return obj.specification;
  const parts = [obj.item_spec || obj.component_item_spec, obj.material_grade, obj.cut_spec].filter(Boolean);
  if (parts.length) return parts.join(' · ');
  if (obj.sku_name && obj.sku_name !== obj.product_name && obj.sku_name !== obj.item_name) return obj.sku_name;
  return obj.sku_name || '';
}

Page({
  data: { id: 0, wo: null, ops: [], kittingOps: [], materialSummary: { total: 0, ready: 0, short: 0 }, units: [], showChildren: false, showMaterials: false, loading: true, error: '' },
  onLoad(options) { this.setData({ id: Number(options.id || 0) }); this.load(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  callPhone(event) { const phone = event.currentTarget.dataset.phone; if (phone) wx.makePhoneCall({ phoneNumber: String(phone) }); },
  toggleChildren() { this.setData({ showChildren: !this.data.showChildren }); },
  toggleMaterials() { this.setData({ showMaterials: !this.data.showMaterials }); },
  openWorkOrder(event) {
    const id = Number(event.currentTarget.dataset.id || 0);
    if (!id || id === this.data.id) return;
    wx.navigateTo({ url: `/pages/production/work-order/index?id=${id}` });
  },
  load() {
    this.setData({ loading: true, error: '' });
    return production.workOrder(this.data.id).then(response => {
      const wo = response.data || response;
      const planned = Number(wo.planned_qty || 0);
      const ops = (wo.operations || []).filter(op => op.execution_context === 'production').map(op => Object.assign({}, op, {
        doneText: view.quantity(op.completed_qty),
        percent: planned > 0 ? Math.min(100, Math.round(Number(op.completed_qty || 0) / planned * 100)) : 0,
      }));
      let matTotal = 0;
      let matShort = 0;
      const kittingOps = (wo.kitting || []).filter(k => (k.materials || []).length > 0).map(k => Object.assign({}, k, {
        materials: (k.materials || []).map((m, idx) => {
          matTotal++;
          const isShort = Number(m.shortage_qty || 0) > 0.00001 || (Number(m.issued_qty || 0) + Number(m.available_qty || 0) + 0.00001 < Number(m.required_qty || 0));
          if (isShort) matShort++;
          return Object.assign({}, m, {
            rowKey: m.id || `${m.component_item_code || 'm'}-${idx}`,
            specText: buildSpecText(m),
            unitQtyText: view.quantity(m.unit_qty),
            requiredText: view.quantity(m.required_qty),
            issuedText: view.quantity(m.issued_qty),
            availableText: view.quantity(m.available_qty),
            shortageText: view.quantity(m.shortage_qty),
          });
        }),
      }));
      const materialSummary = { total: matTotal, ready: matTotal - matShort, short: matShort };
      const units = (wo.units || []).map(u => Object.assign({}, u, {
        short: u.serial_no ? String(u.serial_no).slice(-4) : String(u.unit_no),
        tag: UNIT_TAG[u.status] || 'gray',
        text: u.status_label + (u.current_operation_name && ['in_progress', 'rework'].includes(u.status) ? ' · ' + u.current_operation_name : ''),
      }));
      const parentWo = wo.parent_work_order ? Object.assign({}, wo.parent_work_order, {
        specText: buildSpecText(wo.parent_work_order),
        plannedText: view.quantity(wo.parent_work_order.planned_qty),
        storedText: view.quantity(wo.parent_work_order.stored_qty),
      }) : null;
      const childWos = (wo.child_work_orders || []).map(c => Object.assign({}, c, {
        specText: buildSpecText(c),
        plannedText: view.quantity(c.planned_qty),
        storedText: view.quantity(c.stored_qty),
      }));
      const childSummaryNames = childWos
        .map(c => c.item_name || c.product_name)
        .filter(Boolean)
        .filter((v, i, a) => a.indexOf(v) === i)
        .join('、');
      const costFields = view.permissions()('inventory.amount.view') ? [
        ['工单已归集材料成本', wo.actual_material_cost_amount],
        ['每台已归集材料成本', wo.actual_material_cost_per_unit],
        ['每台材料暂估成本', wo.actual_material_cost_amount == null ? wo.estimated_material_cost_per_unit : null],
      ].map(([label, value]) => ({ label, value: view.money(value) })).filter(field => field.value) : [];
      wx.setNavigationBarTitle({ title: wo.work_order_no || '工单详情' });
      this.setData({
        wo: Object.assign({}, wo, {
          plannedText: view.quantity(wo.planned_qty),
          storedText: view.quantity(wo.stored_qty),
          dueText: String(wo.required_date || wo.planned_finish_date || '').slice(5, 10),
          productText: wo.product_name || wo.item_name,
          specText: buildSpecText(wo),
          parent_work_order: parentWo,
          child_work_orders: childWos,
          childSummaryNames,
          costFields,
        }),
        ops,
        kittingOps,
        materialSummary,
        units,
        loading: false,
      });
    }).catch(error => this.setData({ loading: false, error: view.errorText(error) }));
  },
});
