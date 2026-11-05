const production = require('../../services/production');
const view = require('../../utils/warehouse-page');
const labels = { cancel: '取消后续生产', stock: '继续做备货', dismantle: '拆解退料', scrap: '报废在制设备', finish_termination: '处置办结', terminate: '终止生产', postpone: '继续暂停' };
const statuses = { pending: '待接单人决定', postponed: '继续暂停', terminating: '退料 / 拆解 / 报废处理中' };
const activities = [{ value: 'material_return', label: '退料' }, { value: 'dismantle', label: '拆解' }, { value: 'scrap', label: '报废处理' }];

Component({
  properties: { workOrderId: { type: Number, value: 0 }, operationId: { type: Number, value: 0 } },
  data: { rows: [], page: 1, lastPage: 1, total: 0, error: '', selected: null, action: '', reason: '', due: '', unitIds: [], media: [], busy: false, uploading: false, labor: {}, laborError: '', activityIndex: 0, operationIndex: 0, activities },
  observers: { workOrderId() { this.setData({ page: 1 }); if (this.attachedOnce) this.load(); } },
  lifetimes: { attached() { this.attachedOnce = true; this.load(); }, detached() { this.sequence = (this.sequence || 0) + 1; } },
  pageLifetimes: { show() { if (!this.mediaActive) this.load(); } },
  methods: {
    load() {
      const can = view.permissions();
      if (!(can('production.task.execute') || can('production.work_order.view'))) return this.setData({ rows: [], total: 0, error: '' });
      const sequence = this.sequence = (this.sequence || 0) + 1;
      return production.dispositions({ work_order_id: this.properties.workOrderId || undefined, page: this.data.page, per_page: 10 }).then(response => {
        if (sequence !== this.sequence) return;
        const paged = view.rows(response);
        this.setData({ rows: paged.rows.map(row => Object.assign({}, row, {
          statusText: statuses[row.status] || row.status,
          actions: (row.allowed_actions || []).filter(action => labels[action]).map(action => ({ value: action, label: labels[action] })),
          deciders: (row.decider_names || []).join('、') || '尚未接单',
          unitText: row.unit_serial_no || `第${row.unit_no || row.work_order_unit_id}个`,
        })), page: paged.page, lastPage: paged.lastPage, total: paged.total, error: '' });
      }).catch(error => { if (sequence === this.sequence) this.setData({ error: view.errorText(error) }); });
    },
    page(event) { this.setData({ page: this.data.page + Number(event.currentTarget.dataset.step) }); this.load(); },
    open(event) {
      if (this.data.busy) return;
      const row = this.data.rows.find(item => item.id === Number(event.currentTarget.dataset.id));
      if (!row || !(row.can_decide || row.can_record_labor)) return;
      this.setData({ selected: row, action: '', reason: '', due: '', unitIds: row.work_order_unit_id ? [row.work_order_unit_id] : [], media: [], labor: {}, laborError: '', operationIndex: Math.max(0, (row.operations || []).findIndex(op => op.id === this.properties.operationId)) });
      this.loadLabor();
    },
    close() { if (!this.data.busy && !this.data.uploading) this.setData({ selected: null }); },
    stop() {},
    chooseAction(event) {
      const action = event.currentTarget.dataset.action;
      this.setData({ action, media: [] });
    },
    reasonInput(event) { this.setData({ reason: event.detail.value }); },
    dueInput(event) { this.setData({ due: event.detail.value }); },
    operationInput(event) { this.setData({ operationIndex: Number(event.detail.value) }); },
    activityInput(event) { this.setData({ activityIndex: Number(event.detail.value) }); },
    mediaChanged(event) { this.setData({ media: event.detail.value }); },
    mediaBusy(event) { this.setData({ uploading: event.detail.busy }); },
    mediaActivity(event) { this.mediaActive = event.detail.active; },
    returnMaterials() {
      const row = this.data.selected;
      if (!row || !row.can_request_material_return) return wx.showToast({ title: '当前不能申请退料', icon: 'none' });
      const op = (row.operations || [])[this.data.operationIndex];
      if (!op) return wx.showToast({ title: '没有可退料的工序', icon: 'none' });
      this.setData({ selected: null });
      if (this.properties.operationId === op.id) this.triggerEvent('materialreturn', { returnType: 'cancellation', dispositionId: row.id });
      else wx.navigateTo({ url: `/pages/production/task/index?id=${op.id}&return_type=cancellation&disposition_id=${row.id}` });
    },
    loadLabor() {
      const id = this.data.selected.id;
      return production.dispositionLabor(id).then(response => {
        if (!this.data.selected || this.data.selected.id !== id) return;
        const labor = response.data || response;
        this.setData({ labor: Object.assign({}, labor, { totalText: Number(labor.totals && labor.totals.total_minutes || 0).toFixed(2), startedText: view.dateTime(labor.active_session && labor.active_session.started_at) }), laborError: '' });
      }).catch(error => { if (this.data.selected && this.data.selected.id === id) this.setData({ laborError: view.errorText(error) }); });
    },
    laborAction(event) {
      if (this.data.busy) return;
      const action = event.currentTarget.dataset.action;
      this.setData({ busy: true });
      production.dispositionLaborAction(this.data.selected.id, action, action === 'start' ? { activity: activities[this.data.activityIndex].value } : {}).then(() => this.loadLabor())
        .catch(error => this.setData({ laborError: view.errorText(error) })).finally(() => this.setData({ busy: false }));
    },
    submit() {
      const d = this.data;
      if (d.busy || d.uploading || !d.action) return;
      if (!d.reason.trim()) return wx.showToast({ title: '请填写处理原因', icon: 'none' });
      if (d.action === 'postpone' && !d.due) return wx.showToast({ title: '请选择处理日期', icon: 'none' });
      if (d.action === 'scrap' && (!d.unitIds.length || !d.media.some(item => item.type === 'image'))) return wx.showToast({ title: '请选择设备并上传现场照片', icon: 'none' });
      const payload = { action: d.action, reason: d.reason.trim(), expected_version: d.selected.business_version };
      if (d.action === 'scrap') Object.assign(payload, { unit_ids: d.unitIds, media: d.media });
      if (d.action === 'postpone') payload.due_at = d.due;
      this.setData({ busy: true });
      production.decideDisposition(d.selected.id, payload).then(() => {
        this.setData({ selected: null }); this.load(); this.triggerEvent('changed');
        wx.showToast({ title: '处置已记录', icon: 'success' });
      }).catch(error => wx.showModal({ title: '处置未完成', content: view.errorText(error), showCancel: false })).finally(() => this.setData({ busy: false }));
    },
  },
});
